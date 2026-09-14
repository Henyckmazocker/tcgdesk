<?php

declare(strict_types=1);

namespace App\Router;

use App\Application\UseCase\GetPrecon;
use App\Application\UseCase\SearchCards;
use App\Application\UseCase\SearchPrecons;
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
 *
 * **Los precons entran aquí, y por eso no hay un router nuevo.** El `CLAUDE.md`
 * pedía discutir un tercer caso antes de abrirlo; se discutió el 2026-09-10 y se
 * aprobó en el Plan - Catálogo de Precons, porque los 3.029 mazos oficiales
 * cumplen los tres criterios que justificaron el desvío del catálogo: son
 * **lectura de dato público y reconstruible** (zona 1, se rehacen con
 * `decks:import`), **no necesitan sesión ni CSRF**, y la lista quiere
 * **paginarse por cursor, cachearse y compartirse por URL**. Son dos casos más
 * del `match` de abajo y nada más.
 *
 * Lo que NO entra: **meter un precon en tu colección sigue siendo una acción
 * `POST`** con `Auth → Csrf`. La divergencia es solo de lectura y no se extiende.
 */
class CatalogHttpRouter
{
    /** El catálogo cambia cuando se reingiere, no entre peticiones. */
    private const CACHE_SEGUNDOS = 300;

    public function __construct(
        private readonly SearchCards $buscar,
        private readonly CardRepositoryInterface $cartas,
        private readonly SearchPrecons $buscarPrecons,
        private readonly GetPrecon $verPrecon,
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
                $ruta === '/api/catalog/decks'                      => $this->decks(),
                (bool) preg_match('#^/api/catalog/decks/([^/]+)$#', $ruta, $m) => $this->deck($m[1]),
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

    /**
     * La lista de precons, paginada por cursor.
     *
     * Mismo contrato que `/api/catalog/cards` —`items` + `nextCursor`— para que
     * el scroll infinito del frontend sea el mismo código. `$_GET` entero: quien
     * normaliza y acota los filtros es `PreconSearchCriteria`.
     *
     * **La primera página lleva además `deckTypes` y `sets`**: los valores que
     * el filtro de `/precons` puede ofrecer, sacados de un `GROUP BY` sobre
     * `mtg_precon`. Van aquí y no en una ruta propia porque `/api/catalog/decks/…`
     * ya es el patrón de la ficha —`/decks/types` se leería como un `fileName`
     * y respondería `precon_not_found`— y porque quien pinta el desplegable es
     * quien pide la lista, en la misma petición y con la misma caché.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function decks(): array
    {
        $resultado = ($this->buscarPrecons)($_GET);

        $cuerpo = [
            'items'      => $resultado['items'],
            'nextCursor' => $resultado['nextCursor'],
        ];

        // Solo en la primera página; en las siguientes no vienen y la clave no
        // sale, en vez de salir vacía y hacer creer que no hay tipos.
        if (isset($resultado['deckTypes'], $resultado['sets'])) {
            $cuerpo['deckTypes'] = $resultado['deckTypes'];
            $cuerpo['sets']      = $resultado['sets'];
        }

        return [200, $cuerpo];
    }

    /**
     * La ficha de un precon: su lista completa, con precios y valor total.
     *
     * El `fileName` es la clave natural (`SneakAttack_ZNC`) y no el nombre: hay
     * precons homónimos en ediciones distintas.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function deck(string $fileName): array
    {
        $precon = ($this->verPrecon)(['file_name' => rawurldecode($fileName)]);

        if ($precon === null) {
            return [404, ['error' => 'precon_not_found']];
        }

        return [200, $precon];
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
