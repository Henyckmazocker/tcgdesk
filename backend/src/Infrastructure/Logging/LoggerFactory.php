<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Construye el logger de la aplicación (Monolog 3).
 *
 * A fichero rotado en storage/logs, más stderr en desarrollo para que los logs
 * salgan por `docker compose logs backend` sin tener que entrar al contenedor.
 */
class LoggerFactory
{
    public static function create(string $channel = 'app'): LoggerInterface
    {
        $logger = new Logger($channel);

        $logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../../../storage/logs';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0775, true);
        }

        $level = self::resolveLevel($_ENV['LOG_LEVEL'] ?? 'debug');

        // 14 días de retención: suficiente para depurar sin llenar el disco.
        $logger->pushHandler(new RotatingFileHandler("{$logPath}/{$channel}.log", 14, $level));

        if (($_ENV['APP_ENV'] ?? 'development') !== 'production') {
            $logger->pushHandler(new StreamHandler('php://stderr', $level));
        }

        return $logger;
    }

    private static function resolveLevel(string $name): Level
    {
        return match (strtolower($name)) {
            'emergency' => Level::Emergency,
            'alert'     => Level::Alert,
            'critical'  => Level::Critical,
            'error'     => Level::Error,
            'warning'   => Level::Warning,
            'notice'    => Level::Notice,
            'info'      => Level::Info,
            default     => Level::Debug,
        };
    }
}
