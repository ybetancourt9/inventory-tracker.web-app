<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

abstract class IntegrationTestCase extends TestCase
{
    protected EntityManagerInterface $entityManager;

    protected Connection $connection;

    /** Unique per test, so fixtures are isolated without deleting anything. */
    protected string $prefix;

    protected function setUp(): void
    {
        $entityManager = require dirname(__DIR__, 2) . '/config/bootstrap.php';

        if (!$entityManager instanceof EntityManagerInterface) {
            self::fail('config/bootstrap.php did not return an EntityManager.');
        }

        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        $this->connection->beginTransaction();
        $this->prefix = 'ITX' . strtoupper(bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }

        $this->connection->close();
    }
}
