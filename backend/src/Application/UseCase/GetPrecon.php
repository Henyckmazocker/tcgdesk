<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\Board;
use App\Domain\Repository\PreconRepositoryInterface;

/**
 * La lista completa de un precon, con su valor.
 *
 * Hermano de `GetDeck` y con sus mismas tres decisiones, por los mismos motivos:
 *
 *  - **Las zonas se agrupan aquí y no en SQL.** Son las mismas filas que ya
 *    vienen del repositorio, recorridas una vez, así que el total y lo que suma
 *    cada zona **no pueden contradecirse**. Y se puede probar con un doble.
 *  - **Solo salen las zonas que tienen cartas.** Seis claves vacías son ruido
 *    que la vista tendría que filtrar igualmente.
 *  - **`tokens` no suma al valor ni cuenta ejemplares.** Es la regla de
 *    `Board::esPoseible()`, y es la que explica que cinco precons de solo fichas
 *    tengan `cardCount = 0` sin que eso sea un error.
 *
 * Lo propio de un precon es la cuarta: **las cartas que el catálogo todavía no
 * conoce salen igual**, marcadas con `known: false` y contadas en
 * `unknownPrintings`. No se pueden valorar —no hay printing, luego no hay
 * precio— así que el valor total sale corto, y el número es lo que permite
 * decirlo en voz alta en vez de que el usuario vea un precio bajo sin
 * explicación. Es un `catalog:import` pendiente, el riesgo #11 del Roadmap.
 */
class GetPrecon
{
    public function __construct(
        private readonly PreconRepositoryInterface $precons
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion
     * @return array{precon: array<string, mixed>, boards: array<string, list<array<string, mixed>>>,
     *               cards: list<array<string, mixed>>, valueEur: float, cardsTotal: int,
     *               unknownPrintings: int}|null
     *         null si no existe ese `fileName` — el 404 de la ruta
     */
    public function __invoke(array $peticion): ?array
    {
        $fichero = isset($peticion['file_name']) && is_scalar($peticion['file_name'])
            ? trim((string) $peticion['file_name'])
            : '';

        if ($fichero === '') {
            return null;
        }

        $precon = $this->precons->find($fichero);

        if ($precon === null) {
            return null;
        }

        $cartas       = [];
        $zonas        = [];
        $valor        = 0.0;
        $ejemplares   = 0;
        $desconocidas = 0;

        foreach ($this->precons->cartas($fichero) as $carta) {
            $zona     = Board::desde($carta['board']);
            $cantidad = (int) $carta['count'];

            // El desconocido se trata como cero al sumar y se sigue enseñando
            // como null en la línea: sumar y mostrar no son lo mismo.
            $carta['lineValue'] = $zona->esPoseible()
                ? round($cantidad * (float) ($carta['priceEur'] ?? 0.0), 2)
                : 0.0;

            if ($zona->esPoseible()) {
                $valor      += $carta['lineValue'];
                $ejemplares += $cantidad;
            }

            if ($carta['known'] === false) {
                $desconocidas++;
            }

            $cartas[]                  = $carta;
            $zonas[$zona->value][]     = $carta;
        }

        return [
            'precon'           => $precon,
            'boards'           => $zonas,
            'cards'            => $cartas,
            'valueEur'         => round($valor, 2),
            'cardsTotal'       => $ejemplares,
            'unknownPrintings' => $desconocidas,
        ];
    }
}
