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

$params = [
    'driver'   => 'pdo_mysql',
    'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'     => (int) ($_ENV['DB_PORT'] ?? 3306),
    'dbname'   => $_ENV['DB_NAME'] ?? '',
    'user'     => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset'  => 'utf8mb4',
    // Without this DBAL opens a connection just to detect the platform, which
    // also makes offline commands such as orm:generate-proxies fail.
    'serverVersion' => $_ENV['DB_SERVER_VERSION'] ?? '8.0.46',
];

// Setting a CA turns on TLS. The local container speaks plaintext over the
// compose network, so this stays unset there and is required against RDS.
$sslCa = $_ENV['DB_SSL_CA'] ?? '';

if ($sslCa !== '') {
    $params['driverOptions'][PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    $params['driverOptions'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] =
        ($_ENV['DB_SSL_VERIFY'] ?? 'true') !== 'false';
}

$connection = DriverManager::getConnection($params, $config);

return new EntityManager($connection, $config);
