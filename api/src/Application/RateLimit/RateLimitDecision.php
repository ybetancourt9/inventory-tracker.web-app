<?php

declare(strict_types=1);

namespace InventoryTracker\Application\RateLimit;

/**
 * The outcome of recording one attempt against a limit.
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, 0);
    }

    public static function blocked(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }
}
