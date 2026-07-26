<?php

declare(strict_types=1);

namespace InventoryTracker\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class EntityManagerProvider
{
    private static ?EntityManagerInterface $entityManager = null;

    public static function get(): EntityManagerInterface
    {
        if (self::$entityManager instanceof EntityManagerInterface) {
            return self::$entityManager;
        }

        $entityManager = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new RuntimeException('config/bootstrap.php did not return an EntityManager.');
        }

        return self::$entityManager = $entityManager;
    }
}
