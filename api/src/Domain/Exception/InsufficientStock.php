<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Exception;

use RuntimeException;

/**
 * Raised when an adjustment would drive a product's quantity below zero.
 */
final class InsufficientStock extends RuntimeException
{
    public function __construct(private readonly string $sku, private readonly int $delta)
    {
        parent::__construct(sprintf('Not enough stock of "%s" to apply %+d.', $sku, $delta));
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function delta(): int
    {
        return $this->delta;
    }
}
