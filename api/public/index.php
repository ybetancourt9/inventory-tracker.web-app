<?php

/**
 * Single entry point for the API, and the composition root.
 *
 * nginx rewrites every non-file request here (see docker/nginx/api.conf), and
 * refuses to execute any other .php path, so this is the only reachable script.
 */

declare(strict_types=1);

use InventoryTracker\Application\Auth\AuthenticatedUser;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Application\Auth\TokenVerifier;
use InventoryTracker\Application\RateLimit\RateLimiter;
use InventoryTracker\Domain\Repository\ProductRepositoryInterface;
use InventoryTracker\Domain\Repository\UserRepositoryInterface;
use InventoryTracker\Infrastructure\Doctrine\EntityManagerProvider;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineProductRepository;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineUserRepository;
use InventoryTracker\Infrastructure\RateLimit\ApcuRateLimiter;
use InventoryTracker\Infrastructure\RateLimit\NullRateLimiter;
use Luracast\Restler\Container;
use Luracast\Restler\Restler;

require __DIR__ . '/../config/restler.php';

$container = new Container();
$restler   = new Restler($container);

$container->instance(
    UserRepositoryInterface::class,
    new DoctrineUserRepository(EntityManagerProvider::get())
);

$container->instance(
    ProductRepositoryInterface::class,
    new DoctrineProductRepository(EntityManagerProvider::get())
);

$container->instance(
    RateLimiter::class,
    ApcuRateLimiter::isSupported() ? new ApcuRateLimiter() : new NullRateLimiter()
);

$container->instance(TokenIssuer::class, TokenIssuer::fromEnvironment());
$container->instance(TokenVerifier::class, TokenVerifier::fromEnvironment());

// One shared instance per request, which is what lets JwtAuthenticator hand the
// verified identity to the controller Restler instantiates afterwards.
$container->instance(AuthenticatedUser::class, new AuthenticatedUser());

$restler->handle();
