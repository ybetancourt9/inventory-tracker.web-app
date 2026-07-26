<?php

declare(strict_types=1);

use InventoryTracker\Api\Auth;
use InventoryTracker\Api\Filters\JwtAuthenticator;
use InventoryTracker\Api\Health;
use Luracast\Restler\Defaults;
use Luracast\Restler\MediaTypes\Json;
use Luracast\Restler\Routes;

require_once __DIR__ . '/../vendor/autoload.php';

$isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';

// Controls Restler's own error verbosity: in production mode it stops echoing
// stack traces and internal detail back to the client.
Defaults::$productionMode = $isProduction;

Defaults::$charset = 'utf-8';

Routes::setOverridingResponseMediaTypes(Json::class);
Routes::setOverridingRequestMediaTypes(Json::class);

Defaults::$crossOriginResourceSharing = false;

Routes::addAuthenticator(JwtAuthenticator::class);

Routes::mapApiClasses([
    'health' => Health::class,
    'auth'   => Auth::class,
]);
