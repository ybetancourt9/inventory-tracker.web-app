<?php

/**
 * Single entry point for the API.
 *
 * nginx rewrites every non-file request here (see docker/nginx/api.conf), and
 * refuses to execute any other .php path, so this is the only reachable script.
 */

declare(strict_types=1);

use Luracast\Restler\Restler;

require __DIR__ . '/../config/restler.php';

(new Restler())->handle();
