<?php

declare(strict_types=1);

namespace InventoryTracker\Infrastructure\Doctrine\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Domain\Exception\InsufficientStock;
use InventoryTracker\Domain\Exception\SkuAlreadyExists;
use InventoryTracker\Domain\Pagination\Page;
use InventoryTracker\Domain\Product\ProductQuery;
use InventoryTracker\Domain\Repository\ProductRepositoryInterface;

/**
 * Doctrine adapter for {@see ProductRepositoryInterface}.
 *
 * Search, filter, sort and paging are all expressed as one query so the
 * database does the work using its indexes, rather than the application
 * loading the table and processing it.
 */
final class DoctrineProductRepository implements ProductRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return Page<Product>
     */
    public function search(ProductQuery $query): Page
    {
        return $query->hasSearch() ? $this->searchPage($query) : $this->listPage($query);
    }

    /**
     * Text search across name and SKU.
     *
     * @return Page<Product>
     */
    private function searchPage(ProductQuery $query): Page
    {
        [$filterSql, $filterParams] = $this->filters($query);

        $escape = ProductQuery::LIKE_ESCAPE;
        $branch = static fn(string $column): string => sprintf(
            'SELECT id FROM products WHERE %s%s LIKE ? ESCAPE \'%s\'',
            $filterSql,
            $column,
            $escape,
        );

        $union = $branch('name') . ' UNION ' . $branch('sku');
        $pattern = $query->likePattern();
        $params = [...$filterParams, $pattern, ...$filterParams, $pattern];

        $connection = $this->entityManager->getConnection();

        $total = (int) $connection
            ->executeQuery(sprintf('SELECT COUNT(*) FROM (%s) AS m', $union), $params)
            ->fetchOne();

        // Sort and paginate over the matched ids only, then load those rows.
        // Column and direction come from an enum, never from input.
        $ids = array_map('intval', $connection->executeQuery(
            sprintf(
                'SELECT p.id FROM (%s) AS m JOIN products p ON p.id = m.id
                  ORDER BY p.%s %s, p.id %s LIMIT %d OFFSET %d',
                $union,
                $query->sort->column(),
                $query->sqlDirection(),
                $query->sqlDirection(),
                $query->perPage,
                $query->offset(),
            ),
            $params,
        )->fetchFirstColumn());

        return new Page($this->hydrateInOrder($ids), $total, $query->page, $query->perPage);
    }

    /**
     * Load entities for the given ids and restore the database's ordering.
     *
     * @param list<int> $ids
     *
     * @return list<Product>
     */
    private function hydrateInOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];

        foreach ($this->entityManager->getRepository(Product::class)->findBy(['id' => $ids]) as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Filters that apply to both UNION branches.
     *
     * @return array{string, list<int|bool>}
     */
    private function filters(ProductQuery $query): array
    {
        $clauses = [];
        $params = [];

        if (!$query->includeInactive) {
            $clauses[] = 'is_active = ?';
            $params[] = 1;
        }

        if ($query->lowStockOnly) {
            $clauses[] = 'quantity < ?';
            $params[] = $query->lowStockThreshold;
        }

        return [$clauses === [] ? '' : implode(' AND ', $clauses) . ' AND ', $params];
    }

    /**
     * @return Page<Product>
     */
    private function listPage(ProductQuery $query): Page
    {
        $items = $this->criteria($query)
            ->select('p')
            ->orderBy('p.' . $query->sort->field(), $query->sqlDirection())
            ->addOrderBy('p.id', $query->sqlDirection())
            ->setFirstResult($query->offset())
            ->setMaxResults($query->perPage)
            ->getQuery()
            ->getResult();

        $total = (int) $this->criteria($query)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Product> $items */
        return new Page($items, $total, $query->page, $query->perPage);
    }

    public function findById(int $id): ?Product
    {
        return $this->entityManager->getRepository(Product::class)->find($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return $this->entityManager
            ->getRepository(Product::class)
            ->findOneBy(['sku' => Product::normaliseSku($sku)]);
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new SkuAlreadyExists($product->getSku(), $e);
        }
    }

    public function adjustQuantity(Product $product, int $delta): void
    {
        $id = $product->getId();

        if ($id === null) {
            throw new InvalidArgumentException('Cannot adjust an unpersisted product.');
        }

        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE products
                SET quantity = quantity + :delta, updated_at = :now
              WHERE id = :id AND quantity + :floorDelta >= 0',
            [
                'delta'      => $delta,
                'floorDelta' => $delta,
                'now'        => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'         => $id,
            ],
        );

        if ($affected === 0) {
            throw new InsufficientStock($product->getSku(), $delta);
        }

        // The in-memory entity still holds the pre-update value.
        $this->entityManager->refresh($product);
    }

    /**
     * Shared WHERE clause for the page query and the count query, so the total
     * can never disagree with the rows returned. Search is not handled here;
     * see searchPage().
     */
    private function criteria(ProductQuery $query): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->from(Product::class, 'p');

        if (!$query->includeInactive) {
            $qb->andWhere('p.isActive = :active')->setParameter('active', true);
        }

        if ($query->lowStockOnly) {
            $qb->andWhere('p.quantity < :threshold')
                ->setParameter('threshold', $query->lowStockThreshold);
        }

        return $qb;
    }
}
