<?php

declare(strict_types=1);

namespace InventoryTracker\Infrastructure\RateLimit;

use InventoryTracker\Application\RateLimit\RateLimitDecision;
use InventoryTracker\Application\RateLimit\RateLimiter;

/**
 * Allows everything. Used only where APCu is unavailable, such as CLI commands.
 *
 * Reaching this in a web request means the deployment lost its rate limiting,
 * so it says so in the log rather than failing quietly.
 */
final class NullRateLimiter implements RateLimiter
{
    public function __construct()
    {
        if (PHP_SAPI !== 'cli') {
            error_log('[rate-limit] APCu unavailable; authentication endpoints are unthrottled.');
        }
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        return RateLimitDecision::allowed();
    }
}
