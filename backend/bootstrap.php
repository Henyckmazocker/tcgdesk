<?php

declare(strict_types=1);

/**
 * Bootstrap común de TCGDesk.
 *
 * Carga el autoloader y el entorno, y NO construye nada más. Lo usan los dos
 * entrypoints: public/index.php (HTTP) y bin/tcgdesk (CLI). Mantenerlo tonto es
 * lo que permite que la capa CLI no arrastre nada del ciclo de petición.
 */

require_once __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Variables de entorno desde backend/.env
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
            $_ENV[$key] = $value;
        }
    }
}

// docker-compose pasa la configuración por el entorno del proceso; el .env solo
// rellena lo que falte. getenv() gana sobre el fichero.
foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'LOG_PATH', 'GOOGLE_CLIENT_ID'] as $key) {
    $fromProcess = getenv($key);
    if ($fromProcess !== false && $fromProcess !== '') {
        $_ENV[$key] = $fromProcess;
    }
}

$_ENV['APP_ENV']  = $_ENV['APP_ENV'] ?? 'development';
$_ENV['DB_HOST']  = $_ENV['DB_HOST'] ?? 'mysql';
$_ENV['DB_PORT']  = $_ENV['DB_PORT'] ?? '3306';

foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
    if (!isset($_ENV[$required]) || $_ENV[$required] === '') {
        throw new RuntimeException("{$required} must be set in environment variables");
    }
}

// ---------------------------------------------------------------------------
// Errores y zona horaria
// ---------------------------------------------------------------------------
if ($_ENV['APP_ENV'] === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
