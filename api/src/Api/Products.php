<?php

declare(strict_types=1);

namespace InventoryTracker\Api;

use InvalidArgumentException;
use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Domain\Exception\InsufficientStock;
use InventoryTracker\Domain\Exception\SkuAlreadyExists;
use InventoryTracker\Domain\Pagination\Page;
use InventoryTracker\Domain\Product\ProductQuery;
use InventoryTracker\Domain\Repository\ProductRepositoryInterface;
use InventoryTracker\Infrastructure\Doctrine\EntityManagerProvider;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineProductRepository;
use Luracast\Restler\Exceptions\HttpException;

final class Products
{
    private ProductRepositoryInterface $products;

    public function __construct(?ProductRepositoryInterface $products = null)
    {
        $this->products = $products ?? new DoctrineProductRepository(EntityManagerProvider::get());
    }

    /**
     * List products with search, filtering, sorting and paging.
     *
     * @access protected
     *
     * @param string $search          {@from query}
     * @param string $sort            {@from query} one of name, sku, quantity, updatedAt
     * @param string $direction       {@from query} asc or desc
     * @param bool   $lowStock        {@from query} only items below the threshold
     * @param int    $threshold       {@from query}
     * @param bool   $includeInactive {@from query}
     * @param int    $page            {@from query}
     * @param int    $perPage         {@from query}
     *
     * @return array{
     *     items: list<array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}>,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     pageCount: int
     * }
     */
    public function get(
        ?string $search = null,
        ?string $sort = null,
        ?string $direction = null,
        bool $lowStock = false,
        ?int $threshold = null,
        bool $includeInactive = false,
        ?int $page = null,
        ?int $perPage = null,
    ): array {
        $query = ProductQuery::fromInput(
            search: $search,
            sort: $sort,
            direction: $direction,
            lowStock: $lowStock,
            threshold: $threshold,
            includeInactive: $includeInactive,
            page: $page,
            perPage: $perPage,
        );

        return $this->present($this->products->search($query));
    }

    /**
     * Create a product.
     *
     * @access protected
     * @status 201
     *
     * @param string $sku      {@from body}
     * @param string $name     {@from body}
     * @param int    $quantity {@from body}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 400 invalid input, 409 duplicate SKU
     */
    public function post(string $sku, string $name, int $quantity = 0): array
    {
        try {
            $product = Product::create($sku, $name, $quantity);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(400, $e->getMessage());
        }

        try {
            $this->products->save($product);
        } catch (SkuAlreadyExists) {
            throw new HttpException(409, 'That SKU already exists.');
        }

        return $this->presentOne($product);
    }

    /**
     * Fetch a single product.
     *
     * @access protected
     * @url GET {id}
     *
     * @param int $id {@from path}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 404
     */
    public function getById(int $id): array
    {
        return $this->presentOne($this->mustFind($id));
    }

    /**
     * Update a product's name or set its quantity to an absolute value.
     *
     * Backs the quantity text field. Sending an absolute value is correct when
     * the user typed a specific number.
     *
     * @access protected
     * @url PATCH {id}
     *
     * @param int    $id       {@from path}
     * @param int    $quantity {@from body}
     * @param string $name     {@from body}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 400 invalid input, 404 unknown product,
     *                       409 the product is retired
     */
    public function patch(int $id, ?int $quantity = null, ?string $name = null): array
    {
        $product = $this->mustFind($id);

        if ($quantity === null && $name === null) {
            throw new HttpException(400, 'Provide a quantity or a name to update.');
        }

        if ($quantity !== null) {
            $this->assertStockIsChangeable($product);
        }

        try {
            if ($name !== null) {
                $product->rename($name);
            }

            if ($quantity !== null) {
                $product->setQuantity($quantity);
            }
        } catch (InvalidArgumentException $e) {
            throw new HttpException(400, $e->getMessage());
        }

        $this->products->save($product);

        return $this->presentOne($product);
    }

    /**
     * Apply a relative change to a product's quantity.
     *
     * Backs the increment and decrement controls. A delta rather than an
     * absolute value, because two clients each adding one to a quantity of five
     * should end at seven. Sending a computed absolute value loses one of them.
     *
     * @access protected
     * @url PATCH {id}/quantity
     *
     * @param int $id    {@from path}
     * @param int $delta {@from body}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 404 unknown product, 409 not enough stock or the
     *                       product is retired
     */
    public function adjustQuantity(int $id, int $delta): array
    {
        $product = $this->mustFind($id);

        $this->assertStockIsChangeable($product);

        try {
            $this->products->adjustQuantity($product, $delta);
        } catch (InsufficientStock) {
            throw new HttpException(409, 'Not enough stock.');
        }

        return $this->presentOne($product);
    }

    /**
     * Retire a product.
     *
     * Backs the trash control. A soft delete, so the SKU stays reserved and the
     * row survives for history. Retired products are hidden from listings
     * unless includeInactive is set.
     *
     * @access protected
     * @url DELETE {id}
     *
     * @param int $id {@from path}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 404
     */
    public function remove(int $id): array
    {
        $product = $this->mustFind($id);
        $product->deactivate();
        $this->products->save($product);

        return $this->presentOne($product);
    }

    /**
     * Bring a retired product back into use.
     *
     * The counterpart to remove, so retiring is not a one-way door. Restoring
     * an active product is not an error, which keeps a repeated click harmless.
     *
     * @access protected
     * @url POST {id}/restore
     *
     * @param int $id {@from path}
     *
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     *
     * @throws HttpException 404
     */
    public function restore(int $id): array
    {
        $product = $this->mustFind($id);

        if (!$product->isActive()) {
            $product->activate();
            $this->products->save($product);
        }

        return $this->presentOne($product);
    }

    /**
     * Refuse stock movements on a retired product.
     *
     * Checked against the entity already in memory, so a rejected change costs
     * no write and the caller is told to restore it first.
     *
     * @throws HttpException 409
     */
    private function assertStockIsChangeable(Product $product): void
    {
        if (!$product->isActive()) {
            throw new HttpException(409, 'That product is retired. Restore it before changing stock.');
        }
    }

    /**
     * @throws HttpException 404
     */
    private function mustFind(int $id): Product
    {
        $product = $this->products->findById($id);

        if (!$product instanceof Product) {
            throw new HttpException(404, 'Product not found.');
        }

        return $product;
    }

    /**
     * @param Page<Product> $page
     *
     * @return array{
     *     items: list<array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}>,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     pageCount: int
     * }
     */
    private function present(Page $page): array
    {
        return [
            'items'     => array_map($this->presentOne(...), $page->items),
            'page'      => $page->page,
            'perPage'   => $page->perPage,
            'total'     => $page->total,
            'pageCount' => $page->pageCount(),
        ];
    }

    /**
     * @return array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}
     */
    private function presentOne(Product $product): array
    {
        return [
            'id'        => (int) $product->getId(),
            'sku'       => $product->getSku(),
            'name'      => $product->getName(),
            'quantity'  => $product->getQuantity(),
            'isActive'  => $product->isActive(),
            'updatedAt' => $product->getUpdatedAt()->format('c'),
        ];
    }
}
