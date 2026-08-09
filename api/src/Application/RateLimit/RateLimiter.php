<?php

declare(strict_types=1);

namespace InventoryTracker\Application\RateLimit;

/**
 * Counts attempts against a key and reports when a caller has had too many.
 */
interface RateLimiter
{
    /**
     * Record one attempt and decide whether it may proceed.
     *
     * @param string $key           identifies who is being limited
     * @param int    $limit         attempts permitted per window
     * @param int    $windowSeconds length of the window
     */
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision;
}
