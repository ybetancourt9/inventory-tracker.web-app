<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Support;

use InventoryTracker\Application\RateLimit\RateLimitDecision;
use InventoryTracker\Application\RateLimit\RateLimiter;

/**
 * Counts in a plain array so tests do not depend on APCu or on the clock.
 */
final class InMemoryRateLimiter implements RateLimiter
{
    /** @var array<string, int> */
    private array $counts = [];

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key] <= $limit
            ? RateLimitDecision::allowed()
            : RateLimitDecision::blocked($windowSeconds);
    }

    public function countFor(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }
}
