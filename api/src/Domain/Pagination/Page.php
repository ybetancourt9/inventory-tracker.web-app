<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Pagination;

/**
 * One page of results plus the total number of matches.
 *
 * @template T
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }
}
