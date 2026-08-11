<?php

declare(strict_types=1);

namespace App;

use App\Router\ActionRouter;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entrypoint HTTP: sesión, decodificación de la petición, despacho y respuesta.
 *
 * El contenedor lo construye la factoría de config/container.php — la misma que
 * usa bin/tcgdesk.
 */
class Application
{
    private float $startTime;
    private string $requestMethod;
    private string $requestUri;
    private ContainerInterface $container;
    private ActionRouter $router;

    public function __construct()
    {
        $this->startTime     = microtime(true);
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $this->requestUri    = $_SERVER['REQUEST_URI'] ?? '/';

        $this->bootstrap();
        $this->initializeContainer();
    }

    /**
     * CORS preflight, sesión y cabeceras de respuesta.
     */
    private function bootstrap(): void
    {
        if ($this->requestMethod === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        // La sesión se arranca UNA vez aquí, antes de cualquier middleware, para
        // que no haya varios session_start() creando sesiones distintas.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';

            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $isProduction ? '1' : '0');
            ini_set('session.cookie_samesite', $isProduction ? 'Strict' : 'Lax');
            ini_set('session.cookie_domain', '');
            ini_set('session.cookie_path', '/');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', '604800'); // 7 días

            session_name('TCGDESK_SESSION');
            session_start();
        }

        header('Content-Type: application/json');
    }

    private function initializeContainer(): void
    {
        $containerFactory = require __DIR__ . '/../config/container.php';
        $this->container  = $containerFactory();
        $this->router     = $this->container->get(ActionRouter::class);
    }

    public function run(): void
    {
        $action = null;

        try {
            $inputData = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
            if (!is_array($inputData)) {
                $inputData = [];
            }

            $action = $inputData['action'] ?? $_REQUEST['action'] ?? null;

            $result     = $this->router->dispatch($action, $inputData);
            $statusCode = $result['http_code'] ?? (($result['status'] ?? '') === 'success' ? 200 : 400);

            $this->sendResponse($result, $statusCode);
            return;
        } catch (InvalidArgumentException $e) {
            $this->logThrowable('warning', 'Validation Error', $e, $action);
            $response   = ['status' => 'error', 'message' => $e->getMessage(), 'data' => null];
            $statusCode = 400;
        } catch (Throwable $e) {
            $this->logThrowable('error', 'Unexpected Exception', $e, $action);
            $response   = ['status' => 'error', 'message' => 'An unexpected server error occurred.', 'data' => null];
            $statusCode = 500;
        }

        $this->sendResponse($response, $statusCode);
    }

    private function logThrowable(string $level, string $title, Throwable $e, ?string $action): void
    {
        try {
            $this->container->get(LoggerInterface::class)->{$level}($title, [
                'message'         => $e->getMessage(),
                'action'          => $action ?? 'unknown',
                'method'          => $this->requestMethod,
                'uri'             => $this->requestUri,
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
                'exception_class' => get_class($e),
            ]);
        } catch (Throwable $logError) {
            // Si hasta el logger falla, error_log es el último recurso.
            error_log("{$title}: {$e->getMessage()} (logger failed: {$logError->getMessage()})");
        }
    }

    private function sendResponse(array $response, int $statusCode): void
    {
        $duration = microtime(true) - $this->startTime;

        try {
            $this->container->get(LoggerInterface::class)->info('Response sent', [
                'status_code' => $statusCode,
                'duration_ms' => round($duration * 1000, 2),
                'uri'         => $this->requestUri,
            ]);
        } catch (Throwable) {
            // El logging de la respuesta nunca debe impedir enviarla.
        }

        http_response_code($statusCode);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Acceso al contenedor desde fuera (tests, scripts).
     */
    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }
}
