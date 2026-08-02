<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Repository;

use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Domain\Exception\InsufficientStock;
use InventoryTracker\Domain\Exception\SkuAlreadyExists;
use InventoryTracker\Domain\Pagination\Page;
use InventoryTracker\Domain\Product\ProductQuery;

interface ProductRepositoryInterface
{
    /**
     * Search, filter, sort and paginate in one round trip.
     *
     * @return Page<Product>
     */
    public function search(ProductQuery $query): Page;

    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * @throws SkuAlreadyExists
     */
    public function save(Product $product): void;

    /**
     * Apply a relative change to a product's quantity.
     *
     * @throws InsufficientStock when the result would be negative
     */
    public function adjustQuantity(Product $product, int $delta): void;
}
