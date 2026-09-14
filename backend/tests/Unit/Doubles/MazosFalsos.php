<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Deck\DeckStatus;
use App\Domain\Repository\DeckRepositoryInterface;

/**
 * Mazos de mentira, en memoria.
 *
 * No es un mock que cuenta llamadas: **reproduce el `UNIQUE KEY uq_deck_card` de
 * seis columnas, la suma del `ON DUPLICATE KEY UPDATE` y la fusión al mover una
 * línea**. Sin eso, los tests de `AddCardToDeck` y `ChangeDeckCardIdentity` no
 * probarían lo único que hay que probarles y pasarían igual con un repositorio
 * roto. Mismo criterio que `ColeccionFalsa`, que es su hermana.
 *
 * Y para `DeleteDeck` con `with_cards` recibe **la propia `ColeccionFalsa`**: el
 * descuento cruza las dos tablas en una transacción, así que el doble tiene que
 * poder enseñar cómo queda la colección después —a 0 y con la fila borrada
 * cuando el mazo pedía más de lo que había—.
 */
class MazosFalsos implements DeckRepositoryInterface
{
    /** @var array<int, array<string, mixed>> id → fila de mtg_deck */
    public array $mazos = [];

    /** @var array<int, array<string, mixed>> id → fila de mtg_deck_card */
    public array $cartas = [];

    /**
     * Precios de mentira: `"printing_uuid|finish"` → euros.
     *
     * Vacío por defecto, que es el caso de la carta **sin cotización**: el cruce
     * tiene que seguir enseñándola con `priceEur` a NULL en vez de esconderla,
     * que es lo que haría un `JOIN` en vez del `LEFT JOIN`.
     *
     * @var array<string, float>
     */
    public array $precios = [];

    /**
     * `printing_uuid` → `oracle_id`, para poder probar la legalidad.
     *
     * Lo que no esté aquí **es su propio `oracle_id`**: en los tests el uuid de
     * mentira ya identifica la carta, y obligar a declarar el oracle de todas
     * las cartas de todos los tests haría ruido en los que no miran legalidad.
     * Se declara solo cuando hace falta distinguirlos —dos ediciones del mismo
     * *Sol Ring*, que comparten legalidad y no comparten uuid—.
     *
     * @var array<string, string>
     */
    public array $oracles = [];

    /**
     * `"oracle_id|format"` → status de `mtg_legality`.
     *
     * **Vacío por defecto, y ese vacío es el caso importante**: sin fila para
     * ese formato la carta es `not_legal`, que es la ausencia y no un valor que
     * nadie escriba. Reproducirlo así es lo que hace que el doble pruebe lo
     * mismo que el `LEFT JOIN`.
     *
     * @var array<string, string>
     */
    public array $legalidades = [];

    /**
     * Las filas de `mtg_format`, para `formatoConocido()` y `formatosConocidos()`.
     *
     * Por defecto **todos los que aparezcan en `legalidades`**, que es lo normal
     * y además reproduce de dónde sale la tabla de verdad: `catalog:import` la
     * rellena con un `SELECT DISTINCT format FROM mtg_legality`. Poner aquí una
     * lista explícita sirve para lo que el derivado no puede decir: un formato
     * que existe pero del que esta carta no tiene fila —`modern` para *Sol
     * Ring*—, frente a uno que el usuario teclea mal y que no existe en absoluto.
     *
     * @var list<string>|null
     */
    public ?array $formatos = null;

    private int $siguienteMazo = 1;

    private int $siguienteCarta = 1;

    public function __construct(
        public readonly ?ColeccionFalsa $coleccion = null
    ) {
    }

    public function create(Deck $deck): int
    {
        $id = $this->siguienteMazo++;

        $this->mazos[$id] = $deck->aFila() + ['id' => $id];

        return $id;
    }

    public function update(int $userId, int $deckId, array $campos): ?array
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return null;
        }

        // Solo las claves que vinieron: una edición parcial no puede borrar lo
        // que el cliente no mencionó.
        foreach ($campos as $columna => $valor) {
            $this->mazos[$deckId][$columna] = $valor;
        }

        return $this->findById($userId, $deckId);
    }

    public function delete(int $userId, int $deckId): bool
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return false;
        }

        unset($this->mazos[$deckId]);

        // ON DELETE CASCADE de fk_deckcard_deck.
        foreach ($this->cartas as $id => $carta) {
            if ($carta['deck_id'] === $deckId) {
                unset($this->cartas[$id]);
            }
        }

        return true;
    }

    public function deleteConCartas(int $userId, int $deckId): ?array
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return null;
        }

        $descontados = 0;
        $faltantes   = [];

        foreach ($this->lineasADescontar($deckId) as $linea) {
            $pedidas  = $linea['pedidas'];
            $enPoder  = 0;
            $itemId   = null;

            foreach ($this->coleccion?->filas ?? [] as $id => $fila) {
                // Las cinco dimensiones + is_wishlist = 0: sin ese filtro,
                // desmontar un mazo descontaría de la lista de deseos.
                if (
                    $fila['user_id'] === $userId
                    && $fila['printing_uuid'] === $linea['printing_uuid']
                    && $fila['finish'] === $linea['finish']
                    && $fila['language'] === $linea['language']
                    && $fila['condition_grade'] === $linea['condition_grade']
                    && (int) $fila['is_wishlist'] === 0
                ) {
                    $enPoder = (int) $fila['quantity'];
                    $itemId  = $id;

                    break;
                }
            }

            $quitadas = min($enPoder, $pedidas);

            if ($itemId !== null) {
                // changeQuantity(0) BORRA la fila: no se dejan fantasmas que
                // sigan contando como "cartas únicas" en el dashboard.
                $this->coleccion?->changeQuantity($userId, $itemId, $enPoder - $quitadas);
            }

            $descontados += $quitadas;

            if ($pedidas - $quitadas > 0) {
                $faltantes[] = [
                    'printingUuid' => $linea['printing_uuid'],
                    'name'         => $linea['printing_uuid'],
                    'setCode'      => null,
                    'finish'       => $linea['finish'],
                    'language'     => $linea['language'],
                    'condition'    => $linea['condition_grade'],
                    'requested'    => $pedidas,
                    'removed'      => $quitadas,
                    'missing'      => $pedidas - $quitadas,
                ];
            }
        }

        $this->delete($userId, $deckId);

        return ['removedFromCollection' => $descontados, 'shortfall' => $faltantes];
    }

    public function findById(int $userId, int $deckId): ?array
    {
        return $this->esSuyo($userId, $deckId) ? $this->mazoAContrato($this->mazos[$deckId]) : null;
    }

    /**
     * El enlace del mazo, con el `UNIQUE KEY uq_deck_share_token` reproducido.
     *
     * Ese único se reproduce a mano porque es lo que hace que **regenerar
     * invalide de verdad el anterior**: si el doble guardara una lista de
     * tokens por mazo, `findByShareToken()` seguiría resolviendo el viejo y el
     * test de la revocación pasaría en verde sin probar nada. Aquí la columna es
     * una sola y el valor anterior se pierde, igual que en MySQL.
     */
    public function fijarShareToken(int $userId, int $deckId, ?string $token): bool
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return false;
        }

        $this->mazos[$deckId]['share_token'] = $token;

        return true;
    }

    public function findByShareToken(string $token): ?array
    {
        foreach ($this->mazos as $mazo) {
            // `NULL` no casa con nada, como el `=` de SQL: un mazo sin compartir
            // no se resuelve ni pasándole una cadena vacía.
            if (($mazo['share_token'] ?? null) === null) {
                continue;
            }

            if ($mazo['share_token'] === $token) {
                return ['userId' => $mazo['user_id'], 'deckId' => $mazo['id']];
            }
        }

        return null;
    }

    public function allByUser(int $userId, ?DeckStatus $status = null): array
    {
        $mazos = [];

        foreach ($this->mazos as $mazo) {
            if ($mazo['user_id'] !== $userId) {
                continue;
            }

            if ($status !== null && $mazo['status'] !== $status->value) {
                continue;
            }

            $mazos[] = $this->mazoAContrato($mazo);
        }

        return $mazos;
    }

    public function cards(int $userId, int $deckId): array
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return [];
        }

        $cartas = [];

        foreach ($this->cartas as $carta) {
            if ($carta['deck_id'] === $deckId) {
                $cartas[] = $this->cartaAContrato($carta);
            }
        }

        return $cartas;
    }

    public function addCard(int $userId, DeckCard $card): ?int
    {
        if (!$this->esSuyo($userId, $card->deckId)) {
            return null;
        }

        $fila  = $card->aFila();
        $clave = $this->clave($fila);

        foreach ($this->cartas as $id => $existente) {
            if ($this->clave($existente) === $clave) {
                // count = count + VALUES(count)
                $this->cartas[$id]['count'] += $fila['count'];

                return $id;
            }
        }

        $id = $this->siguienteCarta++;

        $this->cartas[$id] = $fila + ['id' => $id];

        return $id;
    }

    public function addCards(int $userId, int $deckId, array $cards): ?int
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return null;
        }

        $ejemplares = 0;

        foreach ($cards as $carta) {
            // Se reusa `addCard()` para que el lote sume por el MISMO camino que
            // la línea suelta: si el `UNIQUE KEY` se reprodujera dos veces, un
            // día una sumaría y la otra duplicaría.
            $this->addCard($userId, $carta->enElMazo($deckId));

            $ejemplares += $carta->count;
        }

        return $ejemplares;
    }

    public function findCardById(int $userId, int $deckId, int $cardId): ?array
    {
        return $this->esDeEsteMazo($userId, $deckId, $cardId)
            ? $this->cartaAContrato($this->cartas[$cardId])
            : null;
    }

    public function changeCardCount(int $userId, int $deckId, int $cardId, int $count): ?array
    {
        if ($count <= 0) {
            $this->removeCard($userId, $deckId, $cardId);

            return null;
        }

        if (!$this->esDeEsteMazo($userId, $deckId, $cardId)) {
            return null;
        }

        $this->cartas[$cardId]['count'] = $count;

        return $this->findCardById($userId, $deckId, $cardId);
    }

    public function removeCard(int $userId, int $deckId, int $cardId): bool
    {
        if (!$this->esDeEsteMazo($userId, $deckId, $cardId)) {
            return false;
        }

        unset($this->cartas[$cardId]);

        return true;
    }

    public function changeCardIdentity(
        int $userId,
        int $deckId,
        int $cardId,
        ?Finish $finish = null,
        ?CardLanguage $language = null,
        ?Condition $condition = null,
        ?Board $board = null
    ): ?array {
        if (!$this->esDeEsteMazo($userId, $deckId, $cardId)) {
            return null;
        }

        $origen  = $this->cartas[$cardId];
        $destino = [
            'finish'          => $finish?->value    ?? $origen['finish'],
            'language'        => $language?->value  ?? $origen['language'],
            'condition_grade' => $condition?->value ?? $origen['condition_grade'],
            'board'           => $board?->value     ?? $origen['board'],
        ];

        if ($this->clave($destino + $origen) === $this->clave($origen)) {
            return ['card' => $this->findCardById($userId, $deckId, $cardId), 'merged' => false];
        }

        // La colisión con el UNIQUE KEY es lo que distingue este use case de un
        // UPDATE cualquiera, así que el doble tiene que reproducirla: si el
        // destino ya existe, las dos líneas se funden sumando `count`.
        foreach ($this->cartas as $id => $carta) {
            if ($id !== $cardId && $this->clave($carta) === $this->clave($destino + $origen)) {
                $this->cartas[$id]['count'] += $origen['count'];
                unset($this->cartas[$cardId]);

                return ['card' => $this->findCardById($userId, $deckId, $id), 'merged' => true];
            }
        }

        $this->cartas[$cardId] = $destino + $origen;

        return ['card' => $this->findCardById($userId, $deckId, $cardId), 'merged' => false];
    }

    /**
     * La sobreasignación: **solo** los mazos `built`, y **solo** las líneas en
     * las que se pide más de lo que hay.
     *
     * El doble reproduce la asimetría de los tres estados a mano porque es
     * justamente lo que puede escribirse mal en el SQL: filtrar por
     * `!= 'dismantled'` metería los mazos en construcción en el consumo.
     */
    public function consumo(int $userId): array
    {
        $conflictos = [];

        foreach ($this->cruce($userId, null) as $linea) {
            if ($linea['claimed'] <= $linea['inCollection']) {
                continue;
            }

            $conflictos[] = $linea;
        }

        return $conflictos;
    }

    /**
     * Un mazo solo, **sin filtrar por estado** y **sin recortar a lo que falta**:
     * salen todas sus líneas, con lo reclamado, lo que hay y lo libre.
     */
    public function faltantes(int $userId, int $deckId): array
    {
        $lineas = [];

        foreach ($this->cruce($userId, $deckId) as $linea) {
            // `decks` es cosa del conflicto, no del detalle de un mazo.
            unset($linea['decks']);

            $lineas[] = $linea;
        }

        return $lineas;
    }

    /**
     * Lo mismo que `faltantes()` pero de todos sus mazos a la vez.
     *
     * Se construye **reusando `cruce()`**, el mismo camino que `faltantes()`, a
     * propósito: si el doble agregara por su cuenta, un día el lote y la línea
     * suelta dirían números distintos y el test que los compara pasaría en verde
     * probando dos implementaciones falsas.
     *
     * Y reproduce lo único que se puede leer mal del contrato: **un mazo sin
     * cartas no sale en el array**, porque no tiene ni una línea que cruzar.
     */
    public function faltantesDeTodos(int $userId): array
    {
        $porMazo = [];

        foreach ($this->mazos as $mazo) {
            if ($mazo['user_id'] !== $userId) {
                continue;
            }

            $lineas = $this->cruce($userId, $mazo['id']);

            // Sin líneas no hay fila: es el GROUP BY de la consulta real, no un
            // 0 escrito aquí.
            if ($lineas === []) {
                continue;
            }

            $faltan = 0;

            foreach ($lineas as $linea) {
                $faltan += $linea['missing'];
            }

            $porMazo[$mazo['id']] = $faltan;
        }

        return $porMazo;
    }

    /**
     * Las versiones que el usuario tiene de esa carta, con lo que le queda
     * libre según lo que ya reclaman sus mazos **construidos**.
     *
     * Reproduce las tres reglas que hacen útil el desplegable de M5 y que un
     * mock que solo devolviera una lista fija no probaría: parte de la
     * colección y no de los mazos, **filtra `is_wishlist = 0`** y resta lo
     * reclamado por los `built` (los tokens fuera, `Board::esPoseible()`).
     */
    public function variantesEnColeccion(int $userId, string $printingUuid): array
    {
        $reclamado = [];

        foreach ($this->cartas as $carta) {
            $mazo = $this->mazos[$carta['deck_id']] ?? null;

            if ($mazo === null || $mazo['user_id'] !== $userId) {
                continue;
            }

            // Solo los construidos consumen: `DeckStatus::consumeColeccion()`.
            if ($mazo['status'] !== DeckStatus::Built->value) {
                continue;
            }

            if ($carta['printing_uuid'] !== $printingUuid) {
                continue;
            }

            if (!Board::desde($carta['board'])->esPoseible()) {
                continue;
            }

            $clave = implode('|', [$carta['finish'], $carta['language'], $carta['condition_grade']]);

            $reclamado[$clave] = ($reclamado[$clave] ?? 0) + $carta['count'];
        }

        $variantes = [];

        foreach ($this->coleccion?->filas ?? [] as $fila) {
            if (
                $fila['user_id'] !== $userId
                || $fila['printing_uuid'] !== $printingUuid
                || (int) $fila['is_wishlist'] !== 0
            ) {
                continue;
            }

            $clave    = implode('|', [$fila['finish'], $fila['language'], $fila['condition_grade']]);
            $cantidad = (int) $fila['quantity'];
            $pedidas  = $reclamado[$clave] ?? 0;
            $precio   = $this->precios[$printingUuid . '|' . $fila['finish']] ?? null;

            $variantes[] = [
                'finish'          => $fila['finish'],
                'language'        => $fila['language'],
                'condition_grade' => $fila['condition_grade'],
                'quantity'        => $cantidad,
                'claimed'         => $pedidas,
                'free'            => max(0, $cantidad - $pedidas),
                'priceEur'        => $precio,
            ];
        }

        // El mismo ORDER BY de la consulta real.
        usort(
            $variantes,
            static fn (array $a, array $b): int => [$a['finish'], $a['language'], $a['condition_grade']]
                <=> [$b['finish'], $b['language'], $b['condition_grade']]
        );

        return $variantes;
    }

    /**
     * El `LEFT JOIN` con `mtg_legality`, a mano.
     *
     * Lo que importa de este doble: **una carta sin fila para ese formato sigue
     * saliendo en el array**, con `null`. Si se saltara —que es lo que haría un
     * `JOIN` a secas— el test de `not_legal` pasaría sin probar nada.
     *
     * Los tokens fuera, como en la consulta real.
     */
    public function legalidad(int $userId, int $deckId, string $formato): array
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return [];
        }

        $legalidad = [];

        foreach ($this->cartas as $carta) {
            if ($carta['deck_id'] !== $deckId) {
                continue;
            }

            if (!Board::desde($carta['board'])->esPoseible()) {
                continue;
            }

            $oracle = $this->oracleDe($carta['printing_uuid']);

            // La ausencia de fila entra como null: es el LEFT JOIN.
            $legalidad[$oracle] = $this->legalidades[$oracle . '|' . $formato] ?? null;
        }

        return $legalidad;
    }

    public function formatoConocido(string $formato): bool
    {
        // Una pertenencia a `formatosConocidos()` y no una búsqueda aparte: la
        // tabla real es una sola y las dos consultas la leen. Si el doble
        // resolviera cada método por su cuenta, podría decir que `edh` no existe
        // y ofrecerlo en la lista a la vez, y ningún test lo cazaría.
        return in_array($formato, $this->formatosConocidos(), true);
    }

    public function formatosConocidos(): array
    {
        if ($this->formatos !== null) {
            $formatos = $this->formatos;
        } else {
            // El `SELECT DISTINCT format FROM mtg_legality` con el que la ingesta
            // puebla `mtg_format`, hecho a mano sobre las claves del doble.
            $formatos = [];

            foreach (array_keys($this->legalidades) as $clave) {
                $partes     = explode('|', (string) $clave, 2);
                $formatos[] = $partes[1] ?? '';
            }
        }

        $formatos = array_values(array_unique(array_filter($formatos, fn (string $f) => $f !== '')));
        sort($formatos);

        return $formatos;
    }

    /** El `oracle_id` de un printing, que por defecto es el propio uuid. */
    private function oracleDe(string $printingUuid): string
    {
        return $this->oracles[$printingUuid] ?? $printingUuid;
    }

    /**
     * El cruce mazo ↔ colección hecho a mano: agrega por las cinco dimensiones,
     * deja fuera los tokens y busca la fila equivalente de la colección con
     * `is_wishlist = 0`.
     *
     * Con `$deckId` a null mira **todos los mazos construidos** del usuario, que
     * es `consumo()`; con un `$deckId` mira ese y solo ese, sea cual sea su
     * estado, que es `faltantes()`.
     *
     * @return list<array<string, mixed>>
     */
    private function cruce(int $userId, ?int $deckId): array
    {
        $agregadas = [];

        foreach ($this->cartas as $carta) {
            $mazo = $this->mazos[$carta['deck_id']] ?? null;

            if ($mazo === null || $mazo['user_id'] !== $userId) {
                continue;
            }

            if ($deckId === null) {
                // Solo `built` consume colección: `DeckStatus::consumeColeccion()`.
                if ($mazo['status'] !== DeckStatus::Built->value) {
                    continue;
                }
            } elseif ($carta['deck_id'] !== $deckId) {
                continue;
            }

            // Un token no es una carta que se posea: `Board::esPoseible()`.
            if (!Board::desde($carta['board'])->esPoseible()) {
                continue;
            }

            $clave = implode('|', [
                $carta['printing_uuid'],
                $carta['finish'],
                $carta['language'],
                $carta['condition_grade'],
            ]);

            if (!isset($agregadas[$clave])) {
                $agregadas[$clave] = [
                    'printingUuid' => $carta['printing_uuid'],
                    'name'         => $carta['printing_uuid'],
                    'setCode'      => null,
                    'finish'       => $carta['finish'],
                    'language'     => $carta['language'],
                    'condition'    => $carta['condition_grade'],
                    'claimed'      => 0,
                    'decks'        => [],
                ];
            }

            $agregadas[$clave]['claimed'] += $carta['count'];

            // Qué mazos reclaman esta carta, y cuánto pide cada uno: sin los
            // nombres, un conflicto no se puede ni enseñar ni resolver.
            $agregadas[$clave]['decks'][$mazo['id']] = [
                'id'      => $mazo['id'],
                'name'    => $mazo['name'],
                'claimed' => ($agregadas[$clave]['decks'][$mazo['id']]['claimed'] ?? 0) + $carta['count'],
            ];
        }

        $lineas = [];

        foreach ($agregadas as $linea) {
            $enColeccion = $this->enLaColeccion($userId, $linea);
            $faltan      = max(0, $linea['claimed'] - $enColeccion);
            $precio      = $this->precios[$linea['printingUuid'] . '|' . $linea['finish']] ?? null;

            $linea['decks']           = array_values($linea['decks']);
            $linea['inCollection']    = $enColeccion;
            $linea['free']            = max(0, $enColeccion - $linea['claimed']);
            $linea['missing']         = $faltan;
            $linea['priceEur']        = $precio;
            $linea['missingValueEur'] = round($faltan * (float) ($precio ?? 0), 2);

            $lineas[] = $linea;
        }

        return $lineas;
    }

    /**
     * Cuántas tiene de verdad: las cinco dimensiones **y `is_wishlist = 0`**. Sin
     * ese filtro, una carta que *quieres* contaría como carta que *tienes*.
     *
     * @param array<string, mixed> $linea
     */
    private function enLaColeccion(int $userId, array $linea): int
    {
        foreach ($this->coleccion?->filas ?? [] as $fila) {
            if (
                $fila['user_id'] === $userId
                && $fila['printing_uuid'] === $linea['printingUuid']
                && $fila['finish'] === $linea['finish']
                && $fila['language'] === $linea['language']
                && $fila['condition_grade'] === $linea['condition']
                && (int) $fila['is_wishlist'] === 0
            ) {
                return (int) $fila['quantity'];
            }
        }

        return 0;
    }

    /**
     * Lo que el mazo reclama, agregado por las cinco dimensiones y **sin
     * tokens**: la misma carta en el main y en el side son dos líneas del mazo
     * pero UNA sola fila de colección.
     *
     * @return list<array<string, mixed>>
     */
    private function lineasADescontar(int $deckId): array
    {
        $agregadas = [];

        foreach ($this->cartas as $carta) {
            if ($carta['deck_id'] !== $deckId || $carta['board'] === Board::Tokens->value) {
                continue;
            }

            $clave = implode('|', [
                $carta['printing_uuid'],
                $carta['finish'],
                $carta['language'],
                $carta['condition_grade'],
            ]);

            if (!isset($agregadas[$clave])) {
                $agregadas[$clave] = [
                    'printing_uuid'   => $carta['printing_uuid'],
                    'finish'          => $carta['finish'],
                    'language'        => $carta['language'],
                    'condition_grade' => $carta['condition_grade'],
                    'pedidas'         => 0,
                ];
            }

            $agregadas[$clave]['pedidas'] += $carta['count'];
        }

        return array_values($agregadas);
    }

    /** El UNIQUE KEY uq_deck_card, tal cual. */
    private function clave(array $fila): string
    {
        return implode('|', [
            $fila['deck_id'],
            $fila['printing_uuid'],
            $fila['finish'],
            $fila['language'],
            $fila['condition_grade'],
            $fila['board'],
        ]);
    }

    private function esSuyo(int $userId, int $deckId): bool
    {
        return isset($this->mazos[$deckId]) && $this->mazos[$deckId]['user_id'] === $userId;
    }

    private function esDeEsteMazo(int $userId, int $deckId, int $cardId): bool
    {
        return isset($this->cartas[$cardId])
            && $this->cartas[$cardId]['deck_id'] === $deckId
            && $this->esSuyo($userId, $deckId);
    }

    /** @return array<string, mixed> */
    private function mazoAContrato(array $mazo): array
    {
        $ejemplares = 0;
        $lineas     = 0;

        foreach ($this->cartas as $carta) {
            if ($carta['deck_id'] !== $mazo['id']) {
                continue;
            }

            $lineas++;

            // Los tokens no cuentan para el tamaño del mazo.
            if ($carta['board'] !== Board::Tokens->value) {
                $ejemplares += $carta['count'];
            }
        }

        return [
            'id'        => $mazo['id'],
            'name'      => $mazo['name'],
            'status'    => $mazo['status'],
            'format'    => $mazo['format'],
            'notes'     => $mazo['notes'],
            'createdAt' => null,
            'updatedAt' => null,
            'cards'     => $ejemplares,
            'cardLines' => $lineas,
            'valueEur'  => 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private function cartaAContrato(array $carta): array
    {
        return [
            'id'           => $carta['id'],
            'deckId'       => $carta['deck_id'],
            'printingUuid' => $carta['printing_uuid'],
            'oracleId'     => $this->oracleDe($carta['printing_uuid']),
            'name'         => $carta['printing_uuid'],
            'finish'       => $carta['finish'],
            'language'     => $carta['language'],
            'condition'    => $carta['condition_grade'],
            'board'        => $carta['board'],
            'count'        => $carta['count'],
            'priceEur'     => null,
            'lineValue'    => 0.0,
        ];
    }
}
