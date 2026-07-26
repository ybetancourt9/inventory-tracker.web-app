<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Proxy\ProxyFactory;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

$isDevMode = ($_ENV['APP_ENV'] ?? 'production') !== 'production';

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../src/Domain/Entity'],
    isDevMode: $isDevMode,
    proxyDir: __DIR__ . '/../var/proxies',
);

$config->setProxyNamespace('InventoryTracker\\Proxies');

$config->setAutoGenerateProxyClasses(
    $isDevMode ? ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS : ProxyFactory::AUTOGENERATE_NEVER
);

$connection = DriverManager::getConnection([
    'driver'   => 'pdo_mysql',
    'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'     => (int) ($_ENV['DB_PORT'] ?? 3306),
    'dbname'   => $_ENV['DB_NAME'] ?? '',
    'user'     => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset'  => 'utf8mb4',
], $config);

return new EntityManager($connection, $config);
