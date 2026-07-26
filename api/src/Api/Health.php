<?php

declare(strict_types=1);

namespace InventoryTracker\Api;

use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Liveness / readiness probe.
 *
 * Deliberately the first endpoint: a successful GET /health exercises the whole
 * chain end to end -- nginx -> php-fpm -> Composer autoload -> injected
 * environment -> Doctrine -> MySQL -- so a failure anywhere in the stack shows
 * up here rather than inside a feature endpoint.
 *
 * This is also the endpoint an AWS target group would poll, which is why it
 * reports dependency state instead of unconditionally returning 200.
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
     * Round-trip a trivial query to prove the connection is genuinely usable,
     * not merely configured.
     *
     * Failure detail is logged rather than returned: an unauthenticated probe
     * should never hand a caller the DB host, user, or driver error text.
     */
    private function checkDatabase(): string
    {
        try {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = require dirname(__DIR__, 2) . '/config/bootstrap.php';

            $entityManager->getConnection()->executeQuery('SELECT 1')->fetchOne();

            return 'up';
        } catch (Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return 'down';
        }
    }
}
