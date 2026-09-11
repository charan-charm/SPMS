<?php

declare(strict_types=1);

/**
 * W1.1 — MANUAL Brevo integration entry point.
 *
 * The ONLY sanctioned way for SPMS to send real Brevo email. Exists for
 * the owner-supervised real-delivery verification (docs/10 D-054). It
 * is never used by the automated test suite: tests/run-tests.php pins
 * BREVO_ALLOW_REAL_SEND=0, and BrevoMailTransport refuses to send
 * without that flag.
 *
 * Usage:
 *   php -S 127.0.0.1:8099 scripts/manual-brevo-server.php
 *
 * The flag is set inside this process only; the developer's .env is
 * never modified.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

Config::initialize(dirname(__DIR__));
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'brevo',
    'BREVO_ALLOW_REAL_SEND' => '1',
]);

require dirname(__DIR__) . '/public/index.php';
