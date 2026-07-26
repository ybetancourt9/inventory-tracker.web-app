<?php

/**
 * Restler wiring: autoloading, framework defaults, and the URL -> API class map.
 *
 * Kept separate from public/index.php so the routing table can be required by
 * tests and CLI tooling without also dispatching a request.
 */

declare(strict_types=1);

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

// JSON only. The Angular client is the sole consumer, so leaving XML/CSV/HTML
// negotiation enabled would only widen the surface area for no benefit.
Routes::setOverridingResponseMediaTypes(Json::class);
Routes::setOverridingRequestMediaTypes(Json::class);

// CORS is handled at the edge rather than here; the Angular dev server will be
// proxied to this origin, so the browser never makes a cross-origin call.
Defaults::$crossOriginResourceSharing = false;

Routes::mapApiClasses([
    'health' => Health::class,
]);
