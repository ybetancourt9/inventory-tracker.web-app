<?php

declare(strict_types=1);

namespace InventoryTracker\Api;

use InventoryTracker\Infrastructure\Doctrine\EntityManagerProvider;
use Throwable;

/**
 * Liveness / readiness probe. Reports dependency state rather than always
 * returning 200, so a load balancer can act on it.
 */
final class Health
{
    /**
     * Report service and dependency health.
     *
     * @return array{status: string, service: string, php: string, database: string, checkedAt: string}
     */
    public function get(): array
    {
        $database = $this->checkDatabase();

        return [
            'status'    => $database === 'up' ? 'ok' : 'degraded',
            'service'   => 'inventory-tracker-api',
            'php'       => PHP_VERSION,
            'database'  => $database,
            'checkedAt' => gmdate('c'),
        ];
    }

    /**
     * Round-trips a query so the result reflects a usable connection, not just
     * a configured one. Failure detail is logged, never returned.
     */
    private function checkDatabase(): string
    {
        try {
            EntityManagerProvider::get()->getConnection()->executeQuery('SELECT 1')->fetchOne();

            return 'up';
        } catch (Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return 'down';
        }
    }
}
