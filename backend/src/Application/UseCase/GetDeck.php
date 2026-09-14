<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\LegalityStatus;
use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Un mazo con sus cartas, **agrupadas por zona**.
 *
 * La agrupación se hace aquí y no en SQL a propósito: son las mismas filas que
 * ya vienen del repositorio, recorridas una vez, así que el total del mazo y lo
 * que suma cada zona **no pueden contradecirse** —el mismo criterio por el que
 * `ValueCollection` recibe las líneas y no unos totales ya sumados—. Y se puede
 * probar con un doble.
 *
 * Las zonas salen en el orden del enum (`commander`, `companion`, `main`,
 * `side`…) y **solo las que tienen cartas**: siete claves vacías en la respuesta
 * son ruido que la vista tendría que filtrar igualmente.
 *
 * `valueEur` **no se suma aquí**: viene del mazo, donde lo calcula el mismo
 * `LEFT JOIN` de precios que la colección, con los tokens fuera.
 *
 * Y aquí viaja también **la legalidad, que es un aviso y nunca un bloqueo**: el
 * mazo se guarda igual, se enseña igual y no hay ni un camino en el que esto
 * devuelva un error. Lo único que hace es marcar. Va dentro de `deck_get` y no
 * en una acción propia por lo mismo que la disponibilidad: pedir la ficha y los
 * avisos por separado haría que la pantalla cambiara sola delante del usuario.
 */
class GetDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{deck: array<string, mixed>, boards: array<string, list<array<string, mixed>>>,
     *               cards: list<array<string, mixed>>, valueEur: float,
     *               legality: array<string, mixed>}|null
     *         null si el mazo no existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $mazo = $this->mazos->findById($userId, $deckId);

        if ($mazo === null) {
            return null;
        }

        $cartas = $this->mazos->cards($userId, $deckId);
        $zonas  = [];

        foreach (Board::cases() as $zona) {
            $deLaZona = array_values(array_filter(
                $cartas,
                static fn (array $carta): bool => $carta['board'] === $zona->value
            ));

            if ($deLaZona !== []) {
                $zonas[$zona->value] = $deLaZona;
            }
        }

        return [
            'deck'     => $mazo,
            'boards'   => $zonas,
            'cards'    => $cartas,
            'valueEur' => (float) ($mazo['valueEur'] ?? 0.0),
            'legality' => $this->legalidad($userId, $deckId, $mazo['format'] ?? null, $cartas),
        ];
    }

    /**
     * El bloque de avisos de legalidad del mazo: qué cartas marcar y si el mazo
     * llega al tamaño mínimo.
     *
     * Cuatro decisiones que no se leen en el código:
     *
     *  - **`statuses` lleva SOLO lo que se marca**, nunca las cartas legales.
     *    Un Commander legal devolvería cien entradas para no enseñar ninguna
     *    marca; y la vista no necesita distinguir «legal» de «no evaluado»,
     *    porque para eso están `format` y `known`.
     *  - **La clave es el `oracle_id`**, no la línea del mazo: la legalidad es de
     *    la carta, no de la edición ni del estado, así que cuatro líneas de la
     *    misma carta comparten marca y la marca sigue estando ahí para una línea
     *    que se acabe de añadir.
     *  - **Un formato que `mtg_legality` no conoce no marca nada.** El formato lo
     *    teclea el usuario; con `edh` en vez de `commander` no habría ni una fila
     *    y las cien cartas saldrían `not_legal`, que es una alarma falsa y
     *    completa. `known: false` es lo que deja decirlo en vez de gritarlo.
     *  - **`formatosDisponibles` va SIEMPRE, pase lo que pase con el resto.** Es
     *    la lista que llena el desplegable de la ficha, y el mazo que más la
     *    necesita es justo el que se cae por el retorno de abajo: uno sin
     *    formato, o con uno mal tecleado, es al que hay que dejarle elegir. Si
     *    solo se rellenara en el camino largo, el desplegable saldría vacío
     *    exactamente donde hace falta. Cuesta una lectura de `mtg_format`
     *    (0,08 ms), no el `SELECT DISTINCT` de 66 ms que el #17 vino a saldar.
     *
     * El tamaño se cuenta aquí, sobre las mismas cartas que se devuelven y con
     * `Board::esPoseible()` —los tokens fuera—, para que la cifra del aviso y la
     * lista **no puedan contradecirse**. Mismo criterio que las zonas.
     *
     * @param  list<array<string, mixed>> $cartas
     * @return array<string, mixed>
     */
    private function legalidad(int $userId, int $deckId, ?string $formato, array $cartas): array
    {
        $tamano = 0;

        foreach ($cartas as $carta) {
            if (Board::desde($carta['board'])->esPoseible()) {
                $tamano += (int) $carta['count'];
            }
        }

        $bloque = [
            'format'              => $formato,
            'known'               => false,
            'statuses'            => [],
            'banned'              => 0,
            'restricted'          => 0,
            'notLegal'            => 0,
            'size'                => $tamano,
            'minSize'             => null,
            'belowMinimum'        => false,
            'formatosDisponibles' => $this->mazos->formatosConocidos(),
        ];

        // Sin formato no hay reglas que aplicar: `mtg_deck.format` es NULLable y
        // un mazo sin formato es un mazo perfectamente válido. Ni una consulta.
        if ($formato === null || $formato === '' || !$this->mazos->formatoConocido($formato)) {
            return $bloque;
        }

        $porEstado = [
            LegalityStatus::Banned->value     => 'banned',
            LegalityStatus::Restricted->value => 'restricted',
            LegalityStatus::NotLegal->value   => 'notLegal',
        ];

        foreach ($this->mazos->legalidad($userId, $deckId, $formato) as $oracleId => $fila) {
            // `null` (la ausencia de fila) entra aquí y sale `not_legal`.
            $estado = LegalityStatus::desdeFila($fila);

            if (!$estado->esAviso()) {
                continue;
            }

            $bloque['statuses'][$oracleId] = $estado->value;
            $bloque[$porEstado[$estado->value]]++;
        }

        $minimo = Deck::tamanoMinimo($formato);

        $bloque['known']        = true;
        $bloque['minSize']      = $minimo;
        $bloque['belowMinimum'] = $minimo !== null && $tamano < $minimo;

        return $bloque;
    }
}
