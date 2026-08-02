<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Domain\Product;

use InventoryTracker\Domain\Product\ProductQuery;
use InventoryTracker\Domain\Product\ProductSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductQuery::class)]
#[CoversClass(ProductSort::class)]
final class ProductQueryTest extends TestCase
{
    /**
     * The allow-list is the injection defence for ORDER BY, since an identifier
     * cannot be bound as a parameter. Anything unrecognised must fall back
     * rather than reach the query.
     */
    #[DataProvider('rejectedSortInputs')]
    public function testUnrecognisedSortColumnsFallBackToName(?string $input): void
    {
        self::assertSame(ProductSort::Name, ProductQuery::fromInput(sort: $input)->sort);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function rejectedSortInputs(): array
    {
        return [
            'null'              => [null],
            'empty'             => [''],
            'unknown column'     => ['password_hash'],
            'sql injection'      => ['name; DROP TABLE products'],
            'order by injection' => ['name ASC, (SELECT 1)'],
            'wrong case'        => ['NAME'],
            'table qualified'   => ['products.name'],
        ];
    }

    #[DataProvider('acceptedSortInputs')]
    public function testRecognisedSortColumnsAreAccepted(string $input, ProductSort $expected): void
    {
        self::assertSame($expected, ProductQuery::fromInput(sort: $input)->sort);
    }

    /**
     * @return array<string, array{string, ProductSort}>
     */
    public static function acceptedSortInputs(): array
    {
        return [
            'name'      => ['name', ProductSort::Name],
            'sku'       => ['sku', ProductSort::Sku],
            'quantity'  => ['quantity', ProductSort::Quantity],
            'updatedAt' => ['updatedAt', ProductSort::UpdatedAt],
        ];
    }

    public function testEveryAllowedSortMapsToAKnownEntityField(): void
    {
        $fields = ['name', 'sku', 'quantity', 'updatedAt'];

        foreach (ProductSort::cases() as $sort) {
            self::assertContains($sort->field(), $fields);
        }
    }

    #[DataProvider('directionInputs')]
    public function testOnlyDescSetsDescendingOrder(?string $input, bool $descending): void
    {
        $query = ProductQuery::fromInput(direction: $input);

        self::assertSame($descending, $query->descending);
        self::assertSame($descending ? 'DESC' : 'ASC', $query->sqlDirection());
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function directionInputs(): array
    {
        return [
            'asc'        => ['asc', false],
            'desc'       => ['desc', true],
            'upper desc' => ['DESC', true],
            'null'       => [null, false],
            'garbage'    => ['sideways', false],
            'injection'  => ['ASC, (SELECT 1)', false],
        ];
    }

    public function testPerPageIsCappedSoOneRequestCannotAskForTheWholeTable(): void
    {
        self::assertSame(ProductQuery::PER_PAGE_MAX, ProductQuery::fromInput(perPage: 100000)->perPage);
        self::assertSame(1, ProductQuery::fromInput(perPage: 0)->perPage);
        self::assertSame(1, ProductQuery::fromInput(perPage: -5)->perPage);
        self::assertSame(ProductQuery::PER_PAGE_DEFAULT, ProductQuery::fromInput()->perPage);
    }

    public function testPageIsClampedToAtLeastOne(): void
    {
        self::assertSame(1, ProductQuery::fromInput(page: 0)->page);
        self::assertSame(1, ProductQuery::fromInput(page: -3)->page);
        self::assertSame(7, ProductQuery::fromInput(page: 7)->page);
    }

    public function testNegativeThresholdIsClampedSoItCannotMatchEverything(): void
    {
        self::assertSame(0, ProductQuery::fromInput(threshold: -10)->lowStockThreshold);
        self::assertSame(
            ProductQuery::LOW_STOCK_DEFAULT,
            ProductQuery::fromInput(lowStock: true)->lowStockThreshold
        );
    }

    public function testOffsetIsDerivedFromPageAndPerPage(): void
    {
        self::assertSame(0, ProductQuery::fromInput(page: 1, perPage: 25)->offset());
        self::assertSame(25, ProductQuery::fromInput(page: 2, perPage: 25)->offset());
        self::assertSame(80, ProductQuery::fromInput(page: 5, perPage: 20)->offset());
    }

    public function testBlankSearchIsTreatedAsNoSearch(): void
    {
        self::assertFalse(ProductQuery::fromInput(search: '')->hasSearch());
        self::assertFalse(ProductQuery::fromInput(search: '   ')->hasSearch());
        self::assertFalse(ProductQuery::fromInput()->hasSearch());
        self::assertTrue(ProductQuery::fromInput(search: 'widget')->hasSearch());
    }

    public function testSearchTermIsTrimmed(): void
    {
        self::assertSame('widget', ProductQuery::fromInput(search: '  widget  ')->search);
    }

    /**
     * The wildcard is appended only. A leading wildcard would stop the index
     * being usable for a range scan.
     */
    public function testLikePatternIsAPrefixPattern(): void
    {
        $pattern = ProductQuery::fromInput(search: 'widget')->likePattern();

        self::assertSame('widget%', $pattern);
        self::assertStringEndsWith('%', $pattern);
        self::assertStringStartsNotWith('%', $pattern);
    }

    #[DataProvider('wildcardEscapeCases')]
    public function testWildcardsInUserInputAreEscaped(string $search, string $expected): void
    {
        self::assertSame($expected, ProductQuery::fromInput(search: $search)->likePattern());
    }

    /**
     * A user searching for "50%" wants products containing that text, not every
     * product beginning with "50".
     *
     * @return array<string, array{string, string}>
     */
    public static function wildcardEscapeCases(): array
    {
        return [
            'percent'            => ['50%', '50!%%'],
            'underscore'         => ['WID_1', 'WID!_1%'],
            'escape char itself' => ['a!b', 'a!!b%'],
            'multiple'           => ['%_%', '!%!_!%%'],
            'nothing to escape'  => ['widget', 'widget%'],
        ];
    }
}
