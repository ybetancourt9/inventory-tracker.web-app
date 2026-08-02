<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Exception;

use RuntimeException;
use Throwable;

final class SkuAlreadyExists extends RuntimeException
{
    public function __construct(private readonly string $sku, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('SKU "%s" already exists.', $sku), 0, $previous);
    }

    public function sku(): string
    {
        return $this->sku;
    }
}
