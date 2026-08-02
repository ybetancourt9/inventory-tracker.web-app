<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Product;

/**
 * Validated search, sort, filter and paging criteria.
 *
 * Everything arriving from a client is coerced here, so repositories receive
 * values that are already in range and already restricted to the allow-list.
 */
final readonly class ProductQuery
{
    public const PER_PAGE_DEFAULT = 25;
    public const PER_PAGE_MAX = 100;
    public const LOW_STOCK_DEFAULT = 10;

    /**
     * Escape character for LIKE patterns.
     *
     * Not a backslash, because DQL requires a string literal after ESCAPE and a
     * backslash inside one has to survive both the DQL parser and MySQL.
     */
    public const LIKE_ESCAPE = '!';

    public function __construct(
        public ?string $search = null,
        public ProductSort $sort = ProductSort::Name,
        public bool $descending = false,
        public bool $lowStockOnly = false,
        public int $lowStockThreshold = self::LOW_STOCK_DEFAULT,
        public bool $includeInactive = false,
        public int $page = 1,
        public int $perPage = self::PER_PAGE_DEFAULT,
    ) {
    }

    public static function fromInput(
        ?string $search = null,
        ?string $sort = null,
        ?string $direction = null,
        bool $lowStock = false,
        ?int $threshold = null,
        bool $includeInactive = false,
        ?int $page = null,
        ?int $perPage = null,
    ): self {
        $search = $search === null ? null : trim($search);

        return new self(
            search: $search === '' ? null : $search,
            sort: ProductSort::fromInput($sort),
            descending: strtolower((string) $direction) === 'desc',
            lowStockOnly: $lowStock,
            // Clamped so a negative threshold cannot silently match everything.
            lowStockThreshold: max(0, $threshold ?? self::LOW_STOCK_DEFAULT),
            includeInactive: $includeInactive,
            page: max(1, $page ?? 1),
            // Capped so one request cannot ask for the entire table.
            perPage: min(self::PER_PAGE_MAX, max(1, $perPage ?? self::PER_PAGE_DEFAULT)),
        );
    }

    /** Safe to interpolate: derived from a boolean, never from input. */
    public function sqlDirection(): string
    {
        return $this->descending ? 'DESC' : 'ASC';
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasSearch(): bool
    {
        return $this->search !== null;
    }

    /**
     * Prefix pattern for LIKE.
     *
     * The wildcard is only appended, never prepended. `term%` can use an index
     * on the column, while `%term%` forces a full scan and would undo the point
     * of indexing it. Literal % and _ in the input are escaped so they match as
     * characters instead of acting as wildcards.
     */
    public function likePattern(): string
    {
        $e = self::LIKE_ESCAPE;

        $escaped = str_replace(
            [$e, '%', '_'],
            [$e . $e, $e . '%', $e . '_'],
            (string) $this->search,
        );

        return $escaped . '%';
    }
}
