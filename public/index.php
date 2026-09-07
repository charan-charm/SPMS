<?php

declare(strict_types=1);

/**
 * SPMS front controller.
 *
 * This file is the only web-accessible application entry point.
 * It contains no business logic; it initializes the application and
 * delegates request handling to the central router/dispatcher.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\App;

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

App::create(dirname(__DIR__))->run();
