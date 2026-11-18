<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Belum terpasang (tidak ada lock & .env tanpa APP_KEY) → arahkan ke installer web.
$envPath = __DIR__.'/../.env';
if (! is_file(__DIR__.'/../storage/app/installed.lock') && is_file(__DIR__.'/install.php')
    && (! is_file($envPath) || preg_match('/^APP_KEY=\S+/m', $envText = (string) file_get_contents($envPath)) !== 1 || str_contains($envText, 'SNET_INSTALLING=1'))) {
    header('Location: '.rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/').'/install.php', true, 302);
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
