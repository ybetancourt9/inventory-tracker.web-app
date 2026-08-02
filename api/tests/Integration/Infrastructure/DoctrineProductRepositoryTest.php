<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Integration\Infrastructure;

use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Domain\Exception\InsufficientStock;
use InventoryTracker\Domain\Exception\SkuAlreadyExists;
use InventoryTracker\Domain\Product\ProductQuery;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineProductRepository;
use InventoryTracker\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DoctrineProductRepository::class)]
final class DoctrineProductRepositoryTest extends IntegrationTestCase
{
    private DoctrineProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DoctrineProductRepository($this->entityManager);
    }

    /**
     * The regression test for the bug that shipped past 55 unit tests: DQL
     * requires a literal after ESCAPE, not a bound parameter, and the in-memory
     * double never noticed. Any search at all used to raise a syntax error.
     */
    public function testSearchExecutesWithoutASyntaxError(): void
    {
        $page = $this->repository->search(ProductQuery::fromInput(search: 'anything'));

        self::assertGreaterThanOrEqual(0, $page->total);
    }

    public function testSearchMatchesANamePrefix(): void
    {
        $this->given(['A-1' => ['Alpha thing', 5], 'B-1' => ['Beta thing', 5]]);

        $page = $this->repository->search(
            ProductQuery::fromInput(search: $this->prefix . ' Alpha')
        );

        self::assertSame(1, $page->total);
        self::assertSame($this->sku('A-1'), $page->items[0]->getSku());
    }

    public function testSearchMatchesASkuPrefix(): void
    {
        $this->given(['A-1' => ['Alpha thing', 5], 'B-1' => ['Beta thing', 5]]);

        $page = $this->repository->search(ProductQuery::fromInput(search: $this->sku('A-1')));

        self::assertSame(1, $page->total);
        self::assertSame($this->sku('A-1'), $page->items[0]->getSku());
    }

    /**
     * A literal percent in the search term must match a percent character, not
     * behave as a wildcard. This only has meaning against real SQL.
     */
    public function testLiteralPercentInSearchIsNotTreatedAsAWildcard(): void
    {
        $this->given([
            'P-1' => ['50% off bundle', 1],
            'P-2' => ['50 pack of bolts', 1],
        ]);

        $matches = $this->repository->search(
            ProductQuery::fromInput(search: $this->prefix . ' 50% ')
        );

        self::assertSame(1, $matches->total, 'The percent should match literally.');
        self::assertStringContainsString('50%', $matches->items[0]->getName());

        // Control: without the percent, the prefix matches both rows.
        $both = $this->repository->search(ProductQuery::fromInput(search: $this->prefix . ' 50'));
        self::assertSame(2, $both->total);
    }

    public function testLiteralUnderscoreInSearchIsNotTreatedAsAWildcard(): void
    {
        $this->given([
            'U-1' => ['Widget_A special', 1],
            'U-2' => ['WidgetXA special', 1],
        ]);

        $page = $this->repository->search(
            ProductQuery::fromInput(search: $this->prefix . ' Widget_')
        );

        self::assertSame(1, $page->total);
        self::assertStringContainsString('Widget_A', $page->items[0]->getName());
    }

    public function testSortByQuantityDescendingOrdersCorrectly(): void
    {
        $this->given(['Q-1' => ['One', 5], 'Q-2' => ['Two', 90], 'Q-3' => ['Three', 40]]);

        $page = $this->repository->search(ProductQuery::fromInput(
            search: $this->prefix,
            sort: 'quantity',
            direction: 'desc',
        ));

        self::assertSame([90, 40, 5], array_map(
            static fn(Product $p): int => $p->getQuantity(),
            $page->items,
        ));
    }

    public function testSortBySkuAscendingOrdersCorrectly(): void
    {
        $this->given(['S-3' => ['Gamma', 1], 'S-1' => ['Alpha', 1], 'S-2' => ['Beta', 1]]);

        $page = $this->repository->search(
            ProductQuery::fromInput(search: $this->prefix, sort: 'sku')
        );

        self::assertSame(
            [$this->sku('S-1'), $this->sku('S-2'), $this->sku('S-3')],
            array_map(static fn(Product $p): string => $p->getSku(), $page->items),
        );
    }

    public function testLowStockFilterAppliesTheThreshold(): void
    {
        $this->given(['L-1' => ['Low', 2], 'L-2' => ['Mid', 9], 'L-3' => ['High', 400]]);

        $page = $this->repository->search(ProductQuery::fromInput(
            search: $this->prefix,
            lowStock: true,
            threshold: 10,
        ));

        self::assertSame(2, $page->total);
    }

    public function testInactiveProductsAreExcludedUnlessRequested(): void
    {
        $this->given(['I-1' => ['Active', 1], 'I-2' => ['Retired', 1]]);

        $retired = $this->repository->findBySku($this->sku('I-2'));
        self::assertInstanceOf(Product::class, $retired);
        $retired->deactivate();
        $this->repository->save($retired);

        $active = $this->repository->search(ProductQuery::fromInput(search: $this->prefix));
        self::assertSame(1, $active->total);

        $all = $this->repository->search(
            ProductQuery::fromInput(search: $this->prefix, includeInactive: true)
        );
        self::assertSame(2, $all->total);
    }

    /**
     * The count query and the page query share a WHERE clause, so the reported
     * total has to agree with the rows actually returned across pages.
     */
    public function testTotalAgreesWithThePagedResults(): void
    {
        $this->given([
            'G-1' => ['One', 1], 'G-2' => ['Two', 1], 'G-3' => ['Three', 1],
            'G-4' => ['Four', 1], 'G-5' => ['Five', 1],
        ]);

        $collected = [];

        for ($pageNumber = 1; $pageNumber <= 3; $pageNumber++) {
            $page = $this->repository->search(ProductQuery::fromInput(
                search: $this->prefix,
                page: $pageNumber,
                perPage: 2,
            ));

            self::assertSame(5, $page->total);
            self::assertSame(3, $page->pageCount());

            foreach ($page->items as $item) {
                $collected[] = $item->getSku();
            }
        }

        self::assertCount(5, $collected);
        self::assertSame($collected, array_unique($collected), 'Paging must not repeat rows.');
    }

    public function testFindBySkuIsCaseInsensitiveOnInput(): void
    {
        $this->given(['F-1' => ['Findable', 1]]);

        self::assertInstanceOf(Product::class, $this->repository->findBySku($this->sku('F-1')));
        self::assertInstanceOf(
            Product::class,
            $this->repository->findBySku(strtolower($this->sku('F-1')))
        );
    }

    public function testFindByIdReturnsTheSavedProduct(): void
    {
        $product = Product::create($this->sku('D-1'), $this->prefix . ' Identified', 3);
        $this->repository->save($product);

        $found = $this->repository->findById((int) $product->getId());

        self::assertInstanceOf(Product::class, $found);
        self::assertSame($product->getSku(), $found->getSku());
    }

    /**
     * The unique index is what enforces this, and the adapter must translate the
     * driver exception into a domain one.
     */
    public function testDuplicateSkuRaisesTheDomainException(): void
    {
        $this->given(['X-1' => ['Original', 1]]);

        $this->expectException(SkuAlreadyExists::class);

        $this->repository->save(Product::create($this->sku('X-1'), $this->prefix . ' Copy', 1));
    }

    public function testUpdatingAnExistingProductPersists(): void
    {
        $this->given(['M-1' => ['Mutable', 1]]);

        $product = $this->repository->findBySku($this->sku('M-1'));
        self::assertInstanceOf(Product::class, $product);

        $product->setQuantity(42);
        $this->repository->save($product);

        $this->entityManager->clear();

        $reloaded = $this->repository->findBySku($this->sku('M-1'));
        self::assertInstanceOf(Product::class, $reloaded);
        self::assertSame(42, $reloaded->getQuantity());
    }

    public function testAdjustQuantityAppliesTheDelta(): void
    {
        $this->given(['A-1' => ['Adjustable', 10]]);

        $product = $this->repository->findBySku($this->sku('A-1'));
        self::assertInstanceOf(Product::class, $product);

        $this->repository->adjustQuantity($product, 5);
        self::assertSame(15, $product->getQuantity(), 'The entity should reflect the stored value.');

        $this->repository->adjustQuantity($product, -12);
        self::assertSame(3, $product->getQuantity());
    }

    public function testAdjustQuantityRefusesToGoNegative(): void
    {
        $this->given(['A-2' => ['Scarce', 1]]);

        $product = $this->repository->findBySku($this->sku('A-2'));
        self::assertInstanceOf(Product::class, $product);

        try {
            $this->repository->adjustQuantity($product, -2);
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock) {
            // The rejected attempt must leave the stored quantity untouched.
            $this->entityManager->clear();
            $reloaded = $this->repository->findBySku($this->sku('A-2'));
            self::assertInstanceOf(Product::class, $reloaded);
            self::assertSame(1, $reloaded->getQuantity());
        }
    }

    public function testAdjustQuantityToExactlyZeroIsAllowed(): void
    {
        $this->given(['A-3' => ['Exact', 3]]);

        $product = $this->repository->findBySku($this->sku('A-3'));
        self::assertInstanceOf(Product::class, $product);

        $this->repository->adjustQuantity($product, -3);

        self::assertSame(0, $product->getQuantity());
    }

    /**
     * The database rejects a negative quantity regardless of how it is written,
     * so the application-level guard is not the only line of defence.
     */
    public function testTheCheckConstraintRejectsADirectNegativeWrite(): void
    {
        $this->given(['A-4' => ['Guarded', 5]]);

        $product = $this->repository->findBySku($this->sku('A-4'));
        self::assertInstanceOf(Product::class, $product);

        $this->expectExceptionMessageMatches('/[Cc]heck constraint/');

        $this->connection->executeStatement(
            'UPDATE products SET quantity = -1 WHERE id = ?',
            [$product->getId()],
        );
    }

    /**
     * @param array<string, array{string, int}> $products keyed by SKU suffix
     */
    private function given(array $products): void
    {
        foreach ($products as $suffix => [$name, $quantity]) {
            $this->repository->save(
                Product::create($this->sku($suffix), $this->prefix . ' ' . $name, $quantity)
            );
        }

        $this->entityManager->clear();
    }

    private function sku(string $suffix): string
    {
        return $this->prefix . '-' . $suffix;
    }
}
