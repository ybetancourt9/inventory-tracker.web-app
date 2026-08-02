<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Domain\Entity;

use InvalidArgumentException;
use InventoryTracker\Domain\Entity\Product;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    public function testCreateStoresNormalisedValues(): void
    {
        $product = Product::create('  wid-001 ', '  Widget Small  ', 12);

        self::assertSame('WID-001', $product->getSku());
        self::assertSame('Widget Small', $product->getName());
        self::assertSame(12, $product->getQuantity());
        self::assertTrue($product->isActive());
        self::assertNull($product->getId());
    }

    public function testQuantityDefaultsToZero(): void
    {
        self::assertSame(0, Product::create('WID-002', 'Widget Large')->getQuantity());
    }

    #[DataProvider('skuNormalisationCases')]
    public function testSkusAreUppercasedAndTrimmed(string $input, string $expected): void
    {
        self::assertSame($expected, Product::normaliseSku($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function skuNormalisationCases(): array
    {
        return [
            'already normalised' => ['WID-001', 'WID-001'],
            'lower case'         => ['wid-001', 'WID-001'],
            'mixed case'         => ['Wid-001', 'WID-001'],
            'padded'             => ["  wid-001\t", 'WID-001'],
        ];
    }

    public function testEmptySkuIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SKU must not be empty.');

        Product::create('   ', 'Widget', 1);
    }

    public function testOverLongSkuIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Product::create(str_repeat('A', 65), 'Widget', 1);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name must not be empty.');

        Product::create('WID-001', '   ', 1);
    }

    public function testOverLongNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Product::create('WID-001', str_repeat('n', 129), 1);
    }

    public function testNegativeQuantityIsRejectedOnCreate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must not be negative.');

        Product::create('WID-001', 'Widget', -1);
    }

    public function testSetQuantityRejectsNegativeValues(): void
    {
        $product = Product::create('WID-001', 'Widget', 5);

        $this->expectException(InvalidArgumentException::class);

        $product->setQuantity(-1);
    }

    public function testAdjustQuantityIncrementsAndDecrements(): void
    {
        $product = Product::create('WID-001', 'Widget', 5);

        $product->adjustQuantity(3);
        self::assertSame(8, $product->getQuantity());

        $product->adjustQuantity(-6);
        self::assertSame(2, $product->getQuantity());
    }

    /**
     * The minus button must not be able to drive stock below zero.
     */
    public function testAdjustQuantityCannotGoBelowZero(): void
    {
        $product = Product::create('WID-001', 'Widget', 1);

        $this->expectException(InvalidArgumentException::class);

        $product->adjustQuantity(-2);
    }

    public function testDeactivateAndActivateToggleTheSoftDeleteFlag(): void
    {
        $product = Product::create('WID-001', 'Widget', 1);

        $product->deactivate();
        self::assertFalse($product->isActive());

        $product->activate();
        self::assertTrue($product->isActive());
    }

    public function testRenameNormalisesTheNewName(): void
    {
        $product = Product::create('WID-001', 'Widget', 1);
        $product->rename('  Widget Renamed  ');

        self::assertSame('Widget Renamed', $product->getName());
    }

    public function testTimestampsMatchOnCreation(): void
    {
        $product = Product::create('WID-001', 'Widget', 1);

        self::assertSame(
            $product->getCreatedAt()->getTimestamp(),
            $product->getUpdatedAt()->getTimestamp()
        );
    }
}
