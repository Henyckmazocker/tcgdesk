<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;

/**
 * El cruce mazo ↔ colección: qué le falta a un mazo y qué mazos se están
 * peleando por la misma carta.
 *
 * **Este use case NO cambia ningún estado, y es su regla principal.** La
 * sobreasignación —dos mazos construidos que piden 2 Sol Ring cada uno cuando
 * solo tienes 3— se devuelve como una lista de conflictos **con los mazos
 * nombrados**, y es la UI la que ofrece el botón de desmontar. Un `UPDATE`
 * automático sobre `mtg_deck.status` disparado por una condición calculada es
 * justo el cambio que luego nadie sabe explicar: el usuario abriría la app y se
 * encontraría un mazo desmontado sin haber tocado nada. Aquí se informa; decide
 * él.
 *
 * Dos preguntas distintas, y por eso dos consultas:
 *
 *  - **`consumo()`** mira todos los mazos `built` a la vez. Es la única que puede
 *    ver un conflicto, porque un conflicto es siempre entre varios mazos.
 *  - **`faltantes()`** mira un mazo solo, sin importar su estado, y responde «te
 *    faltan N». Es lo que necesita un mazo en construcción.
 *
 * Los conflictos se devuelven **enteros aunque se pregunte por un mazo**: la
 * sobreasignación es una propiedad de la colección, no de un mazo, y recortarla
 * a «los conflictos de este» escondería justo al otro mazo implicado, que es el
 * que la UI tiene que nombrar para poder ofrecer desmontarlo.
 */
class AnalyzeDeckAvailability
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * `deck_id` es **opcional**: sin él esto es el aviso global de
     * sobreasignación —lo que pinta `/decks`—, y con él, además, el detalle de
     * lo que le falta a ese mazo —lo que pinta `/deck/:id`—.
     *
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{deck: array<string, mixed>|null, lines: list<array<string, mixed>>,
     *               missing: int, missingValueEur: float,
     *               conflicts: list<array<string, mixed>>, overallocated: bool}|null
     *         null solo cuando se pidió un mazo que no existe o no es de este
     *         usuario, para que el controller responda 404
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $conflictos = $this->mazos->consumo($userId);

        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : null;

        if ($deckId === null) {
            return $this->respuesta(null, [], $conflictos);
        }

        // El mazo se comprueba con `findById()`, que ya filtra por `user_id`:
        // `faltantes()` de un mazo ajeno devolvería una lista vacía, y una lista
        // vacía significa «no te falta nada», que es una respuesta muy distinta
        // de «ese mazo no es tuyo».
        $mazo = $this->mazos->findById($userId, $deckId);

        if ($mazo === null) {
            return null;
        }

        return $this->respuesta($mazo, $this->mazos->faltantes($userId, $deckId), $conflictos);
    }

    /**
     * Los totales se suman aquí, sobre las mismas líneas que se devuelven, y no
     * en otra consulta agregada: así el «te faltan 7» y la lista **no pueden
     * contradecirse**. Mismo criterio que `GetDeck` con las zonas.
     *
     * @param  array<string, mixed>|null        $mazo
     * @param  list<array<string, mixed>>       $lineas
     * @param  list<array<string, mixed>>       $conflictos
     * @return array{deck: array<string, mixed>|null, lines: list<array<string, mixed>>,
     *               missing: int, missingValueEur: float,
     *               conflicts: list<array<string, mixed>>, overallocated: bool}
     */
    private function respuesta(?array $mazo, array $lineas, array $conflictos): array
    {
        $faltan = 0;
        $coste  = 0.0;

        foreach ($lineas as $linea) {
            $faltan += (int) $linea['missing'];
            $coste  += (float) $linea['missingValueEur'];
        }

        return [
            'deck'            => $mazo,
            'lines'           => $lineas,
            'missing'         => $faltan,
            // El coste de completarlo trata el precio desconocido como cero al
            // sumar —igual que la colección—, pero cada línea conserva su
            // `priceEur` a NULL para poder decir «sin precio» en vez de «0 €».
            'missingValueEur' => round($coste, 2),
            'conflicts'       => $conflictos,
            'overallocated'   => $conflictos !== [],
        ];
    }
}
