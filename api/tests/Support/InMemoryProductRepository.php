<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Support;

use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Domain\Exception\InsufficientStock;
use InventoryTracker\Domain\Exception\SkuAlreadyExists;
use InventoryTracker\Domain\Pagination\Page;
use InventoryTracker\Domain\Product\ProductQuery;
use InventoryTracker\Domain\Product\ProductSort;
use InventoryTracker\Domain\Repository\ProductRepositoryInterface;
use ReflectionProperty;

/**
 * In-memory adapter used by unit tests.
 *
 * Mirrors the Doctrine adapter's observable behaviour, not its SQL. Anything
 * that depends on how the query is expressed belongs in an integration test.
 */
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $products = [];

    private int $nextId = 1;

    public function add(Product ...$products): void
    {
        foreach ($products as $product) {
            $this->save($product);
        }
    }

    /**
     * @return Page<Product>
     */
    public function search(ProductQuery $query): Page
    {
        $matches = array_values(array_filter(
            $this->products,
            fn(Product $p): bool => $this->matches($p, $query),
        ));

        usort($matches, fn(Product $a, Product $b): int => $this->compare($a, $b, $query));

        $total = count($matches);
        $page = array_values(array_slice($matches, $query->offset(), $query->perPage));

        return new Page($page, $total, $query->page, $query->perPage);
    }

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?Product
    {
        $normalised = Product::normaliseSku($sku);

        foreach ($this->products as $product) {
            if ($product->getSku() === $normalised) {
                return $product;
            }
        }

        return null;
    }

    public function save(Product $product): void
    {
        $existing = $this->findBySku($product->getSku());

        if ($existing instanceof Product && $existing !== $product) {
            throw new SkuAlreadyExists($product->getSku());
        }

        $id = $product->getId();

        if ($id === null) {
            $id = $this->nextId++;
            (new ReflectionProperty(Product::class, 'id'))->setValue($product, $id);
        }

        $this->products[$id] = $product;
    }

    public function adjustQuantity(Product $product, int $delta): void
    {
        if ($product->getQuantity() + $delta < 0) {
            throw new InsufficientStock($product->getSku(), $delta);
        }

        $product->adjustQuantity($delta);
        $this->save($product);
    }

    private function matches(Product $product, ProductQuery $query): bool
    {
        if (!$query->includeInactive && !$product->isActive()) {
            return false;
        }

        if ($query->lowStockOnly && $product->getQuantity() >= $query->lowStockThreshold) {
            return false;
        }

        if ($query->hasSearch()) {
            $term = (string) $query->search;

            return stripos($product->getName(), $term) === 0
                || stripos($product->getSku(), $term) === 0;
        }

        return true;
    }

    private function compare(Product $a, Product $b, ProductQuery $query): int
    {
        $result = match ($query->sort) {
            ProductSort::Name => strcasecmp($a->getName(), $b->getName()),
            ProductSort::Sku => strcasecmp($a->getSku(), $b->getSku()),
            ProductSort::Quantity => $a->getQuantity() <=> $b->getQuantity(),
            ProductSort::UpdatedAt => $a->getUpdatedAt() <=> $b->getUpdatedAt(),
        };

        if ($result === 0) {
            $result = (int) $a->getId() <=> (int) $b->getId();
        }

        return $query->descending ? -$result : $result;
    }
}
