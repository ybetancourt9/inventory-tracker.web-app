<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Product;

/**
 * Sortable columns.
 *
 * This enum is the allow-list. ORDER BY takes an identifier, which cannot be
 * bound as a parameter, so the only safe approach is to map client input onto a
 * fixed set of known columns. tryFrom() returns null for anything else, so an
 * unrecognised value can never reach the query.
 */
enum ProductSort: string
{
    case Name = 'name';
    case Sku = 'sku';
    case Quantity = 'quantity';
    case UpdatedAt = 'updatedAt';

    public static function fromInput(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Name;
    }

    /** Entity field name, safe to interpolate because it comes from this enum. */
    public function field(): string
    {
        return $this->value;
    }

    /** Database column name, for the places that build SQL directly. */
    public function column(): string
    {
        return match ($this) {
            self::Name => 'name',
            self::Sku => 'sku',
            self::Quantity => 'quantity',
            self::UpdatedAt => 'updated_at',
        };
    }
}
