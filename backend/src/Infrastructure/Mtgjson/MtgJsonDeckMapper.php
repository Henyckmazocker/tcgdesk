<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Traduce los mazos preconstruidos de MTGJSON a las filas de `mtg_precon` y
 * `mtg_precon_card`.
 *
 * **Clase aparte de `MtgJsonMapper` a propósito.** Aquél conoce la forma de
 * `AllPrintings.json.gz` —sets, cartas, printings, idiomas, legalidades— y nada
 * de lo que hay aquí le sirve: `DeckList.json` y los ficheros de `AllDeckFiles`
 * son otros dos documentos, con otras claves y otro ciclo de vida (los precons se
 * reingieren cuando sale una caja, no cuando sale un set). Meterlo dentro habría
 * sumado 150 líneas a una clase que ya es la más testeada del repositorio, sin
 * compartir ni una línea de lógica.
 *
 * Es la única pieza que conoce la forma del JSON de mazos, y no toca la base de
 * datos: recibe strings y arrays y devuelve arrays. Por eso es lo que se testea
 * de verdad, con un fichero de mazo recortado en vez de los 571 KB reales.
 */
final class MtgJsonDeckMapper
{
    /**
     * Las seis zonas que publica un fichero de mazo, con el puntero JSON por el
     * que json-machine las saca.
     *
     * Son las mismas seis del ENUM `mtg_precon_card.board`: MTGJSON sí trae
     * `planes`, `schemes` y `tokens` (los Planechase y Archenemy los usan).
     * `displayCommander` queda fuera a propósito: es cosmético —qué carta enseña
     * la caja— y no una zona de juego.
     */
    private const PUNTEROS = [
        '/data/mainBoard' => 'main',
        '/data/sideBoard' => 'side',
        '/data/commander' => 'commander',
        '/data/planes'    => 'planes',
        '/data/schemes'   => 'schemes',
        '/data/tokens'    => 'tokens',
    ];

    /**
     * Fila de `mtg_precon` a partir de una entrada de `DeckList.json`.
     *
     * **La clave natural es `fileName`, no `name`.** Hay precons con el mismo
     * nombre en ediciones distintas —«Sneak Attack» sale más de una vez— y
     * `SneakAttack_ZNC` es único. Una entrada sin `fileName` no es indexable y se
     * descarta devolviendo null.
     *
     * MTGJSON llama `source` a lo que nuestra columna llama `source_url`.
     *
     * @param  array<string, mixed> $entrada
     * @return array<string, mixed>|null
     */
    public function precon(array $entrada): ?array
    {
        $fichero = trim((string) ($entrada['fileName'] ?? ''));

        if ($fichero === '') {
            return null;
        }

        $lanzamiento = trim((string) ($entrada['releaseDate'] ?? ''));
        $fuente      = trim((string) ($entrada['source'] ?? ''));

        return [
            'file_name'    => $fichero,
            'set_code'     => strtoupper(trim((string) ($entrada['code'] ?? ''))),
            'name'         => trim((string) ($entrada['name'] ?? $fichero)),
            'deck_type'    => trim((string) ($entrada['type'] ?? '')),
            'release_date' => $lanzamiento !== '' ? $lanzamiento : null,
            'source_url'   => $fuente !== '' ? $fuente : null,
        ];
    }

    /**
     * El acabado de una carta del mazo.
     *
     * **`isEtched` es un campo APARTE de `isFoil`, y se mira primero.** Un etched
     * no es un foil raro: tiene precio propio en `mtg_price_daily.finish` y
     * columna propia en `mtg_printing.has_etched`, así que mapear los dos a
     * `foil` falsearía el valor de todo lo posterior a Commander Legends. MTGJSON
     * además marca `isFoil` **y** `isEtched` a la vez en algunas entradas: por eso
     * el orden de las comprobaciones importa y no es un `match` cualquiera.
     *
     * @param array<string, mixed> $carta
     */
    public function finish(array $carta): Finish
    {
        if (!empty($carta['isEtched'])) {
            return Finish::Etched;
        }

        if (!empty($carta['isFoil'])) {
            return Finish::Foil;
        }

        return Finish::Normal;
    }

    /**
     * Filas de `mtg_precon_card` a partir del JSON de UN fichero de mazo.
     *
     * Recibe el JSON como string —no un array ya decodificado— porque un fichero
     * de mazo mide hasta 571 KB: cada entrada de carta trae la carta ENTERA (47
     * campos, `foreignData`, `rulings`, `legalities`, `purchaseUrls`) para que
     * nosotros nos quedemos con cuatro. json-machine la decodifica de una en una
     * y la suelta antes de la siguiente; un `json_decode()` del fichero completo
     * multiplicaría por mil el pico, que es exactamente lo que M0 midió.
     *
     * **Devuelve las filas DEDUPLICADAS por (uuid, board, finish), sumando los
     * `count`.** No es paranoia gratuita: esas cuatro columnas son la PK de la
     * tabla, y un `INSERT … ON DUPLICATE KEY UPDATE` multi-fila con la clave
     * repetida DENTRO de la misma sentencia aborta la sentencia entera, así que
     * una sola repetición de MTGJSON se llevaría por delante el mazo completo.
     *
     * @param  string $json         El fichero de mazo completo
     * @param  string $ficheroMazo  `fileName` sin extensión: SneakAttack_ZNC
     * @return list<array<string, mixed>>
     */
    public function cartas(string $json, string $ficheroMazo): array
    {
        $cartas = Items::fromString($json, [
            'pointer' => array_keys(self::PUNTEROS),
            'decoder' => new ExtJsonDecoder(true),
        ]);

        $filas = [];

        // json-machine reclama al TERMINAR la iteración los punteros que no
        // encontró, y un mazo sin `schemes` es lo normal, no un error: MTGJSON
        // omite las zonas vacías en muchos ficheros. Se traga la queja **después**
        // del bucle, con las filas de las zonas que sí estaban ya recogidas; un
        // fallo de sintaxis de verdad sigue saliendo por `SyntaxErrorException`.
        try {
            foreach ($cartas as $carta) {
                $uuid = trim((string) ($carta['uuid'] ?? ''));

                // Sin uuid no hay nada que referenciar en el catálogo. No se ha
                // dado en los 3.029, pero una fila con la PK a medias es peor que
                // ninguna.
                if ($uuid === '') {
                    continue;
                }

                $board  = self::PUNTEROS[$cartas->getCurrentJsonPointer()] ?? Board::porDefecto()->value;
                $finish = $this->finish($carta)->value;
                $clave  = $uuid . '|' . $board . '|' . $finish;

                if (isset($filas[$clave])) {
                    $filas[$clave]['count'] += max(1, (int) ($carta['count'] ?? 1));
                    continue;
                }

                $filas[$clave] = [
                    'precon_file'   => $ficheroMazo,
                    'printing_uuid' => $uuid,
                    'board'         => $board,
                    'finish'        => $finish,
                    'count'         => max(1, (int) ($carta['count'] ?? 1)),
                ];
            }
        } catch (PathNotFoundException) {
            // Zonas ausentes en este mazo. Nada que hacer.
        }

        return array_values($filas);
    }

    /**
     * Ejemplares que trae la caja, para `mtg_precon.card_count`.
     *
     * **Los `tokens` no cuentan**, por la misma regla que el resto del repositorio
     * (`Board::esPoseible()`): un token se genera, no se compra, y un Planechase
     * que dijera «117 cartas» porque cuenta sus fichas mentiría en la ficha.
     *
     * @param list<array<string, mixed>> $filas
     */
    public function ejemplares(array $filas): int
    {
        $total = 0;

        foreach ($filas as $fila) {
            if (Board::intentar($fila['board'] ?? null)?->esPoseible() === false) {
                continue;
            }

            $total += (int) ($fila['count'] ?? 0);
        }

        return $total;
    }
}
