<?php

declare(strict_types=1);

namespace InventoryTracker\Infrastructure\RateLimit;

use InventoryTracker\Application\RateLimit\RateLimitDecision;
use InventoryTracker\Application\RateLimit\RateLimiter;

/**
 * Fixed-window counter kept in APCu shared memory.
 *
 * Counters live in the php-fpm process pool, so they are shared by every worker
 * on a host but not between hosts, and they reset when the container restarts.
 * That is sufficient for a single-instance deployment; running more than one
 * instance needs a shared store behind this same interface.
 */
final class ApcuRateLimiter implements RateLimiter
{
    public static function isSupported(): bool
    {
        return function_exists('apcu_inc') && filter_var(
            ini_get('apc.enabled'),
            FILTER_VALIDATE_BOOL
        );
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        $window = (int) floor(time() / $windowSeconds);
        $slot   = 'rl:' . $key . ':' . $window;

        // The TTL is what expires the window; nothing is ever deleted by hand.
        $count = apcu_inc($slot, 1, $ok, $windowSeconds);

        if ($ok === false || !is_int($count)) {
            return RateLimitDecision::allowed();
        }

        if ($count <= $limit) {
            return RateLimitDecision::allowed();
        }

        return RateLimitDecision::blocked((($window + 1) * $windowSeconds) - time());
    }
}
