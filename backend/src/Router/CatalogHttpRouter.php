<?php

declare(strict_types=1);

namespace App\Router;

use App\Application\UseCase\SearchCards;
use App\Domain\Repository\CardRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Las rutas `GET /api/catalog/*`: la única divergencia del endpoint único.
 *
 * Todo lo demás en TCGDesk entra por `POST /index.php` con la acción en el body.
 * El catálogo no, y el motivo es concreto: es **lectura pública, paginable y
 * cacheable**, y con un POST único no hay forma de que el navegador, un proxy o
 * un CDN cacheen nada, ni de compartir el enlace de una búsqueda.
 *
 * La divergencia se paga una sola vez y se queda contenida aquí: `public/index.php`
 * desvía antes de instanciar `Application`, y ninguna otra parte del backend se
 * entera. Solo GET y solo lectura; cualquier escritura sigue siendo una acción.
 */
class CatalogHttpRouter
{
    /** El catálogo cambia cuando se reingiere, no entre peticiones. */
    private const CACHE_SEGUNDOS = 300;

    public function __construct(
        private readonly SearchCards $buscar,
        private readonly CardRepositoryInterface $cartas,
        private readonly LoggerInterface $logger
    ) {
    }

    /** ¿Esta petición es del catálogo? Lo decide public/index.php con esto. */
    public static function atiende(string $metodo, string $uri): bool
    {
        return $metodo === 'GET' && str_starts_with(self::ruta($uri), '/api/catalog');
    }

    public function handle(string $uri): void
    {
        $ruta = self::ruta($uri);

        try {
            $respuesta = match (true) {
                $ruta === '/api/catalog/sets'                       => $this->sets(),
                $ruta === '/api/catalog/cards'                      => $this->cards(),
                (bool) preg_match('#^/api/catalog/cards/([^/]+)$#', $ruta, $m) => $this->card($m[1]),
                default                                             => [404, ['error' => 'not_found']],
            };
        } catch (Throwable $e) {
            $this->logger->error('Catalog HTTP falló', [
                'uri'             => $uri,
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            $respuesta = [500, ['error' => 'internal_error']];
        }

        [$codigo, $cuerpo] = $respuesta;

        $this->responder($codigo, $cuerpo);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function sets(): array
    {
        return [200, $this->cartas->allSets()];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function cards(): array
    {
        $resultado = ($this->buscar)($_GET);

        return [200, [
            'items'      => $resultado['items'],
            'nextCursor' => $resultado['nextCursor'],
        ]];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function card(string $uuid): array
    {
        $carta = $this->cartas->findByUuid(rawurldecode($uuid));

        if ($carta === null) {
            return [404, ['error' => 'printing_not_found']];
        }

        return [200, $carta];
    }

    /** La ruta sin query string ni barra final. */
    private static function ruta(string $uri): string
    {
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';

        return rtrim($ruta, '/') ?: '/';
    }

    /** @param array<string|int, mixed> $cuerpo */
    private function responder(int $codigo, array $cuerpo): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json');

        // Solo se cachea lo que salió bien: un 404 cacheado cinco minutos
        // sobrevive a la reingesta que habría hecho aparecer la carta.
        if ($codigo === 200) {
            header('Cache-Control: public, max-age=' . self::CACHE_SEGUNDOS);
        } else {
            header('Cache-Control: no-store');
        }

        echo json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
