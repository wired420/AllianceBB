<?php

// Router for PHP's built-in development server: `php -S localhost:8080 public/router.php`
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
