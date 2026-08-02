<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Api;

use InventoryTracker\Api\Products;
use InventoryTracker\Domain\Entity\Product;
use InventoryTracker\Tests\Support\InMemoryProductRepository;
use Luracast\Restler\Exceptions\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Products::class)]
final class ProductsTest extends TestCase
{
    private InMemoryProductRepository $repository;

    private Products $endpoint;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository();
        $this->endpoint = new Products($this->repository);

        $this->repository->add(
            Product::create('WID-001', 'Widget Small', 120),
            Product::create('WID-002', 'Widget Large', 8),
            Product::create('GAD-100', 'Gadget Basic', 3),
            Product::create('BOL-050', 'Bolt M6 Zinc', 900),
            Product::create('NUT-050', 'Nut M6 Stainless', 0),
        );
    }

    public function testDefaultListingIsSortedByNameAscending(): void
    {
        $result = $this->endpoint->get();

        self::assertSame(5, $result['total']);
        self::assertSame(
            ['Bolt M6 Zinc', 'Gadget Basic', 'Nut M6 Stainless', 'Widget Large', 'Widget Small'],
            array_column($result['items'], 'name'),
        );
    }

    public function testSearchMatchesANamePrefix(): void
    {
        $result = $this->endpoint->get(search: 'Widget');

        self::assertSame(2, $result['total']);
        self::assertSame(['WID-002', 'WID-001'], array_column($result['items'], 'sku'));
    }

    public function testSearchMatchesASkuPrefix(): void
    {
        $result = $this->endpoint->get(search: 'BOL');

        self::assertSame(1, $result['total']);
        self::assertSame('BOL-050', $result['items'][0]['sku']);
    }

    public function testSearchThatMatchesNothingReturnsAnEmptyPage(): void
    {
        $result = $this->endpoint->get(search: 'nonexistent');

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['pageCount']);
    }

    public function testSortByQuantityDescending(): void
    {
        $result = $this->endpoint->get(sort: 'quantity', direction: 'desc');

        self::assertSame([900, 120, 8, 3, 0], array_column($result['items'], 'quantity'));
    }

    public function testSortBySku(): void
    {
        $result = $this->endpoint->get(sort: 'sku');

        self::assertSame(
            ['BOL-050', 'GAD-100', 'NUT-050', 'WID-001', 'WID-002'],
            array_column($result['items'], 'sku'),
        );
    }

    /**
     * An unrecognised sort column must not error, and must not be passed
     * through. It falls back to the default.
     */
    public function testUnknownSortColumnFallsBackToNameOrder(): void
    {
        $result = $this->endpoint->get(sort: 'quantity; DROP TABLE products');

        self::assertSame(
            ['Bolt M6 Zinc', 'Gadget Basic', 'Nut M6 Stainless', 'Widget Large', 'Widget Small'],
            array_column($result['items'], 'name'),
        );
    }

    public function testLowStockFilterUsesTheDefaultThreshold(): void
    {
        $result = $this->endpoint->get(lowStock: true);

        self::assertSame(3, $result['total']);
        self::assertSame([0, 3, 8], $this->sortedQuantities($result['items']));
    }

    public function testLowStockFilterHonoursAnExplicitThreshold(): void
    {
        $result = $this->endpoint->get(lowStock: true, threshold: 4);

        self::assertSame(2, $result['total']);
        self::assertSame([0, 3], $this->sortedQuantities($result['items']));
    }

    public function testInactiveProductsAreHiddenUnlessRequested(): void
    {
        $retired = Product::create('OLD-001', 'Retired Item', 5);
        $retired->deactivate();
        $this->repository->add($retired);

        self::assertSame(5, $this->endpoint->get()['total']);
        self::assertSame(6, $this->endpoint->get(includeInactive: true)['total']);
    }

    public function testPagingSplitsTheResultSetAndReportsPageCount(): void
    {
        $first = $this->endpoint->get(perPage: 2, page: 1);
        $second = $this->endpoint->get(perPage: 2, page: 2);
        $third = $this->endpoint->get(perPage: 2, page: 3);

        self::assertSame(3, $first['pageCount']);
        self::assertSame(5, $first['total']);
        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(1, $third['items']);

        $names = array_merge(
            array_column($first['items'], 'name'),
            array_column($second['items'], 'name'),
            array_column($third['items'], 'name'),
        );

        self::assertSame(
            ['Bolt M6 Zinc', 'Gadget Basic', 'Nut M6 Stainless', 'Widget Large', 'Widget Small'],
            $names,
        );
    }

    public function testPerPageIsCapped(): void
    {
        self::assertSame(100, $this->endpoint->get(perPage: 99999)['perPage']);
    }

    public function testPostCreatesAProduct(): void
    {
        $created = $this->endpoint->post('new-001', '  New Thing  ', 7);

        self::assertSame('NEW-001', $created['sku']);
        self::assertSame('New Thing', $created['name']);
        self::assertSame(7, $created['quantity']);
        self::assertTrue($created['isActive']);
        self::assertGreaterThan(0, $created['id']);

        self::assertSame(6, $this->endpoint->get()['total']);
    }

    public function testPostRejectsADuplicateSkuRegardlessOfCase(): void
    {
        $failure = $this->captureFailure('wid-001', 'Duplicate', 1);

        self::assertSame(409, $failure['code']);
        self::assertSame('That SKU already exists.', $failure['message']);
    }

    public function testPostRejectsAnEmptySku(): void
    {
        self::assertSame(400, $this->captureFailure('   ', 'Nameless', 1)['code']);
    }

    public function testPostRejectsANegativeQuantity(): void
    {
        $failure = $this->captureFailure('NEG-001', 'Negative', -1);

        self::assertSame(400, $failure['code']);
        self::assertStringContainsString('negative', $failure['message']);
    }

    public function testGetByIdReturnsTheProduct(): void
    {
        $id = $this->idOf('WID-001');

        self::assertSame('WID-001', $this->endpoint->getById($id)['sku']);
    }

    public function testGetByIdOnAnUnknownIdIs404(): void
    {
        self::assertSame(404, $this->captureIdFailure(fn() => $this->endpoint->getById(9999)));
    }

    public function testPatchSetsAnAbsoluteQuantity(): void
    {
        $updated = $this->endpoint->patch($this->idOf('WID-001'), quantity: 42);

        self::assertSame(42, $updated['quantity']);
        self::assertSame(42, $this->endpoint->getById($this->idOf('WID-001'))['quantity']);
    }

    public function testPatchRenamesAProduct(): void
    {
        $updated = $this->endpoint->patch($this->idOf('WID-001'), name: '  Widget Renamed  ');

        self::assertSame('Widget Renamed', $updated['name']);
        self::assertSame(120, $updated['quantity'], 'Quantity should be untouched.');
    }

    public function testPatchWithNothingToChangeIs400(): void
    {
        self::assertSame(400, $this->captureIdFailure(
            fn() => $this->endpoint->patch($this->idOf('WID-001'))
        ));
    }

    public function testPatchRejectsANegativeQuantity(): void
    {
        self::assertSame(400, $this->captureIdFailure(
            fn() => $this->endpoint->patch($this->idOf('WID-001'), quantity: -1)
        ));
    }

    public function testAdjustQuantityIncrementsAndDecrements(): void
    {
        $id = $this->idOf('WID-002');

        self::assertSame(9, $this->endpoint->adjustQuantity($id, 1)['quantity']);
        self::assertSame(8, $this->endpoint->adjustQuantity($id, -1)['quantity']);
        self::assertSame(13, $this->endpoint->adjustQuantity($id, 5)['quantity']);
    }

    /**
     * The decrement control must not be able to drive stock below zero. This is
     * a conflict with current state rather than a malformed request.
     */
    public function testAdjustQuantityBelowZeroIs409(): void
    {
        self::assertSame(409, $this->captureIdFailure(
            fn() => $this->endpoint->adjustQuantity($this->idOf('NUT-050'), -1)
        ));
    }

    public function testAdjustQuantityBelowZeroReportsATerseReason(): void
    {
        try {
            $this->endpoint->adjustQuantity($this->idOf('NUT-050'), -1);
            self::fail('Expected the adjustment to fail.');
        } catch (HttpException $e) {
            self::assertSame('Not enough stock.', $e->getMessage());
        }
    }

    public function testAdjustQuantityOnAnUnknownIdIs404(): void
    {
        self::assertSame(404, $this->captureIdFailure(
            fn() => $this->endpoint->adjustQuantity(9999, 1)
        ));
    }

    public function testRemoveIsASoftDeleteThatHidesTheProduct(): void
    {
        $id = $this->idOf('WID-001');

        $removed = $this->endpoint->remove($id);
        self::assertFalse($removed['isActive']);

        self::assertSame(4, $this->endpoint->get()['total']);
        self::assertSame(5, $this->endpoint->get(includeInactive: true)['total']);

        // The row survives, so the SKU stays reserved.
        self::assertSame('WID-001', $this->endpoint->getById($id)['sku']);
    }

    public function testRemoveOnAnUnknownIdIs404(): void
    {
        self::assertSame(404, $this->captureIdFailure(fn() => $this->endpoint->remove(9999)));
    }

    private function idOf(string $sku): int
    {
        $product = $this->repository->findBySku($sku);
        self::assertInstanceOf(Product::class, $product);

        return (int) $product->getId();
    }

    private function captureIdFailure(callable $call): int
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e->getCode();
        }

        self::fail('Expected the request to fail.');
    }

    /**
     * @param list<array{id: int, sku: string, name: string, quantity: int, isActive: bool, updatedAt: string}> $items
     *
     * @return list<int>
     */
    private function sortedQuantities(array $items): array
    {
        $quantities = array_column($items, 'quantity');
        sort($quantities);

        return $quantities;
    }

    /**
     * @return array{code: int, message: string}
     */
    private function captureFailure(string $sku, string $name, int $quantity): array
    {
        try {
            $this->endpoint->post($sku, $name, $quantity);
        } catch (HttpException $e) {
            return ['code' => $e->getCode(), 'message' => $e->getMessage()];
        }

        self::fail('Expected the request to fail.');
    }
}
