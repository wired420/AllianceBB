<?php

declare(strict_types=1);

use AllianceBB\App;
use AllianceBB\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot(dirname(__DIR__));

$handler = static function () use ($app): void {
    $app->handle(Request::fromGlobals())->send();
};

if (function_exists('frankenphp_handle_request')) {
    // FrankenPHP worker mode: the app stays booted between requests.
    ignore_user_abort(true);
    $max = (int) ($_SERVER['MAX_REQUESTS'] ?? 1000);
    for ($n = 0; $n < $max; $n++) {
        $keepRunning = frankenphp_handle_request($handler);
        gc_collect_cycles();
        // Plugins, theme or code changed: exit so FrankenPHP starts a fresh worker.
        if (!$keepRunning || $app->configChanged()) {
            break;
        }
    }
} else {
    // Classic PHP (php -S, PHP-FPM): one boot per request.
    $handler();
}
