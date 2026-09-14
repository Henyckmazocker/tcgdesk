<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Deck\DeckStatus;
use App\Domain\Repository\DeckRepositoryInterface;
use PDO;
use Throwable;

/**
 * Los mazos en MySQL.
 *
 * Hereda entera la valoración de `MySqlCollectionRepository`, sin reinventar
 * nada, y por los mismos tres motivos:
 *
 *  - **`LEFT JOIN mtg_price_current`, jamás `JOIN`.** Muchísimos printings no
 *    cotizan en Cardmarket; un `JOIN` normal haría desaparecer esas cartas del
 *    mazo, que es el peor fallo posible y además silencioso.
 *  - **El precio se une por `(printing_uuid, finish)`.** Unir solo por printing
 *    infló una colección de prueba en 500 € valorando foils a precio de
 *    no-foil.
 *  - **`COALESCE(pc.price_eur, 0)` en el valor de línea y `price_eur` a pelo en
 *    la columna mostrada**: sumar trata el desconocido como cero, mostrarlo no.
 *    Y el total **se recalcula siempre**; nunca se cachea en una columna de
 *    `mtg_deck`, porque los precios cambian a diario.
 *
 * Lo propio del mazo son dos cosas. Una: **`board = 'tokens'` no cuenta para
 * nada** —ni suma al valor, ni al número de cartas, ni se descuenta de la
 * colección—, así que aparece un `CASE` con esa condición en todos los
 * agregados. Y dos: `mtg_deck_card` **no tiene `user_id`**, cuelga del mazo, de
 * modo que la propiedad se comprueba siempre por el `JOIN` con `mtg_deck` y el
 * `user_id` va al `WHERE` incluso en los `DELETE`.
 */
class MySqlDeckRepository implements DeckRepositoryInterface
{
    /**
     * El contrato de un mazo, común a la lista y a la ficha.
     *
     * Los dos agregados excluyen los tokens con el mismo `CASE`: un token no es
     * una carta que se posea, así que ni cuenta para el tamaño ni vale dinero.
     */
    private const COLUMNAS_MAZO = "
        d.id,
        d.name,
        d.status,
        d.format,
        d.notes,
        d.created_at AS createdAt,
        d.updated_at AS updatedAt,
        COALESCE(SUM(CASE WHEN dc.board <> 'tokens' THEN dc.`count` ELSE 0 END), 0) AS cards,
        COUNT(dc.id) AS cardLines,
        COALESCE(
            SUM(CASE WHEN dc.board <> 'tokens' THEN dc.`count` * COALESCE(pc.price_eur, 0) ELSE 0 END),
            0
        ) AS valueEur
    ";

    /** El mazo con sus cartas colgando, para poder agregarlas de una pasada. */
    private const ORIGEN_MAZO = '
          FROM mtg_deck d
     LEFT JOIN mtg_deck_card dc ON dc.deck_id = d.id
     LEFT JOIN mtg_price_current pc
            ON pc.printing_uuid = dc.printing_uuid
           AND pc.finish        = dc.finish
    ';

    /**
     * `GROUP BY` completo por exigencia de `ONLY_FULL_GROUP_BY`, que MySQL 8
     * trae activado por defecto: agrupar solo por `d.id` daría error, no un
     * resultado arbitrario.
     */
    private const AGRUPACION_MAZO = '
         GROUP BY d.id, d.name, d.status, d.format, d.notes, d.created_at, d.updated_at
    ';

    /** El contrato de una línea de mazo: la carta, su edición y su precio. */
    private const COLUMNAS_CARTA = "
        dc.id,
        dc.deck_id         AS deckId,
        dc.printing_uuid   AS printingUuid,
        dc.finish,
        dc.language,
        dc.condition_grade AS `condition`,
        dc.board,
        dc.`count`,
        p.oracle_id        AS oracleId,
        c.name,
        p.set_code         AS setCode,
        s.name             AS setName,
        s.release_date     AS releaseDate,
        p.collector_number AS collectorNumber,
        p.rarity,
        c.mana_cost        AS manaCost,
        c.colors,
        c.color_identity   AS colorIdentity,
        c.type_line        AS typeLine,
        p.scryfall_id      AS scryfallId,
        pc.price_eur       AS priceEur,
        CASE WHEN dc.board <> 'tokens'
             THEN dc.`count` * COALESCE(pc.price_eur, 0)
             ELSE 0
        END AS lineValue
    ";

    /**
     * El `JOIN` con `mtg_deck` no es decorativo: es lo que comprueba que el mazo
     * es de quien pregunta, porque `mtg_deck_card` no guarda `user_id`.
     */
    private const ORIGEN_CARTA = '
          FROM mtg_deck_card dc
          JOIN mtg_deck     d ON d.id        = dc.deck_id
          JOIN mtg_printing p ON p.uuid      = dc.printing_uuid
          JOIN mtg_card     c ON c.oracle_id = p.oracle_id
          JOIN mtg_set      s ON s.code      = p.set_code
     LEFT JOIN mtg_price_current pc
            ON pc.printing_uuid = dc.printing_uuid
           AND pc.finish        = dc.finish
    ';

    /**
     * **La** escritura de cartas del mazo, y la única.
     *
     * `count = count + VALUES(count)` es lo que hace que añadir dos veces la
     * misma carta en la misma zona SUME en vez de duplicar, y lo que hará
     * idempotente la importación de decklists de M7.
     *
     * `id = LAST_INSERT_ID(id)` no es decorativo: sin él, tras un
     * `ON DUPLICATE KEY UPDATE`, `lastInsertId()` no devuelve el id de la fila
     * actualizada y el cliente no podría releerla.
     */
    private const UPSERT_CARTA = '
        INSERT INTO mtg_deck_card
               (deck_id, printing_uuid, finish, language, condition_grade, board, `count`)
        VALUES (:deck_id, :printing_uuid, :finish, :language, :condition_grade, :board, :count)
        ON DUPLICATE KEY UPDATE
               `count` = `count` + VALUES(`count`),
               id      = LAST_INSERT_ID(id)
    ';

    /**
     * Lo que devuelve cada línea del cruce mazo ↔ colección, en las dos
     * consultas del análisis.
     *
     * `MAX(ci.quantity)` no elige entre varias: el `UNIQUE KEY` de
     * `mtg_collection_item` es el mismo juego de dimensiones más `is_wishlist`,
     * así que el `LEFT JOIN` de abajo aporta como mucho **una** fila por grupo.
     * El `COALESCE(..., 0)` es lo que convierte «no tienes ninguna» en un 0 en
     * vez de un NULL que rompería la comparación del `HAVING`.
     */
    private const COLUMNAS_CRUCE = "
        dc.printing_uuid,
        dc.finish,
        dc.language,
        dc.condition_grade,
        c.name,
        p.set_code,
        SUM(dc.`count`)               AS reclamado,
        COALESCE(MAX(ci.quantity), 0) AS enColeccion,
        MAX(pc.price_eur)             AS priceEur
    ";

    /**
     * El cruce en sí: las cinco dimensiones contra la colección, y el precio.
     *
     * **`:user_id2` es el MISMO valor que `:user_id`, repetido a propósito.** Con
     * `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un marcador
     * nombrado en dos puntos de la misma sentencia, y el usuario hace falta dos
     * veces: en el `JOIN` con `mtg_deck` y en este `LEFT JOIN`.
     *
     * **`is_wishlist = 0`, sin excepción.** Sin ese filtro, una carta que
     * *quieres* contaría como carta que *tienes* y el mazo diría que está
     * completo.
     *
     * Y `LEFT JOIN` en los dos cruces, jamás `JOIN`: una carta que no tienes debe
     * salir diciendo «te faltan 2» en vez de desaparecer del análisis, y una sin
     * cotización en Cardmarket sale con `priceEur` a NULL en vez de esfumarse. El
     * precio se une por `(printing_uuid, finish)` y nunca solo por printing.
     */
    private const CRUCE_COLECCION = '
     LEFT JOIN mtg_collection_item ci
            ON ci.user_id         = :user_id2
           AND ci.printing_uuid   = dc.printing_uuid
           AND ci.finish          = dc.finish
           AND ci.language        = dc.language
           AND ci.condition_grade = dc.condition_grade
           AND ci.is_wishlist     = 0
          JOIN mtg_printing p ON p.uuid      = dc.printing_uuid
          JOIN mtg_card     c ON c.oracle_id = p.oracle_id
     LEFT JOIN mtg_price_current pc
            ON pc.printing_uuid = dc.printing_uuid
           AND pc.finish        = dc.finish
    ';

    /**
     * La agregación del cruce: las cinco dimensiones son la carta, y el nombre y
     * la edición van al `GROUP BY` por `ONLY_FULL_GROUP_BY` —no cambian los
     * grupos, porque `printing_uuid` ya los determina, pero MySQL 8 no lo deduce
     * a través de dos `JOIN`—.
     */
    private const AGRUPACION_CRUCE = '
         GROUP BY dc.printing_uuid, dc.finish, dc.language, dc.condition_grade, c.name, p.set_code
    ';

    /** El mismo orden en las dos consultas: por carta, y luego por versión. */
    private const ORDEN_CRUCE = '
         ORDER BY c.name, dc.finish, dc.language, dc.condition_grade
    ';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function create(Deck $deck): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mtg_deck (user_id, name, status, format, notes)
             VALUES (:user_id, :name, :status, :format, :notes)'
        );

        $stmt->execute($deck->aFila());

        return (int) $this->db->lastInsertId();
    }

    public function update(int $userId, int $deckId, array $campos): ?array
    {
        // Sin campos no hay UPDATE que hacer, pero sí hay que responder el mazo:
        // el cliente ha pedido una edición vacía, no ha fallado.
        if ($campos === []) {
            return $this->findById($userId, $deckId);
        }

        $asignaciones = [];

        foreach (array_keys($campos) as $columna) {
            // Las claves vienen de la lista blanca de `Deck::camposDesdePeticion()`,
            // que es la única barrera posible: PDO no admite marcador para el
            // nombre de una columna.
            $asignaciones[] = "{$columna} = :{$columna}";
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_deck SET ' . implode(', ', $asignaciones) . '
              WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute($campos + ['id' => $deckId, 'user_id' => $userId]);

        return $this->findById($userId, $deckId);
    }

    public function delete(int $userId, int $deckId): bool
    {
        // El user_id va en el WHERE del DELETE, no en una comprobación previa:
        // `id` es un autoincremental global y sin este filtro bastaría con
        // probar números para borrar el mazo de otra persona. Las cartas se van
        // solas por el ON DELETE CASCADE de `fk_deckcard_deck`.
        $stmt = $this->db->prepare('DELETE FROM mtg_deck WHERE id = :id AND user_id = :user_id');

        $stmt->execute(['id' => $deckId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function deleteConCartas(int $userId, int $deckId): ?array
    {
        // El método ENTERO vive dentro de una transacción, y no por prudencia
        // genérica: si el borrado del mazo llegara y el descuento no, el usuario
        // tendría cartas que ya no están en ninguna caja; si fuera al revés,
        // habría perdido cartas sin que desapareciera nada.
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM mtg_deck WHERE id = :id AND user_id = :user_id FOR UPDATE'
            );

            $stmt->execute(['id' => $deckId, 'user_id' => $userId]);

            if ($stmt->fetch() === false) {
                $this->db->commit();

                return null;
            }

            $descontados = 0;
            $faltantes   = [];

            foreach ($this->lineasADescontar($deckId) as $linea) {
                $resultado = $this->descontarDeLaColeccion($userId, $linea);

                $descontados += $resultado['removed'];

                if ($resultado['missing'] > 0) {
                    $faltantes[] = $resultado;
                }
            }

            $borrar = $this->db->prepare('DELETE FROM mtg_deck WHERE id = :id AND user_id = :user_id');
            $borrar->execute(['id' => $deckId, 'user_id' => $userId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return ['removedFromCollection' => $descontados, 'shortfall' => $faltantes];
    }

    public function findById(int $userId, int $deckId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_MAZO . self::ORIGEN_MAZO . '
              WHERE d.id = :id AND d.user_id = :user_id'
              . self::AGRUPACION_MAZO
        );

        $stmt->execute(['id' => $deckId, 'user_id' => $userId]);

        $fila = $stmt->fetch();

        return $fila === false ? null : $this->mazoAContrato($fila);
    }

    /**
     * El enlace del mazo: un `UPDATE` de una columna, con dos cosas que no se
     * leen en el SQL.
     *
     * **La propiedad se comprueba aparte y no por el `rowCount()`.** MySQL
     * devuelve 0 filas afectadas cuando el `UPDATE` escribe el valor que ya
     * había —y aquí eso pasa constantemente: revocar dos veces escribe `NULL`
     * sobre `NULL`—, así que deducir de un 0 que el mazo no es tuyo convertiría
     * un `deck_unshare` idempotente en un 404 falso. `esSuyo()` cuesta una
     * lectura de clave primaria y dice lo que el `rowCount()` no puede.
     *
     * **El `user_id` va igualmente al `WHERE` del `UPDATE`**, aunque `esSuyo()`
     * ya lo haya mirado: entre las dos sentencias no hay transacción, y es la
     * regla de este repositorio —el `user_id` va al `WHERE` incluso en los
     * `DELETE`—. Duplicarlo no cuesta nada; quitarlo deja la escritura
     * dependiendo de que nadie toque el orden de dos líneas.
     */
    public function fijarShareToken(int $userId, int $deckId, ?string $token): bool
    {
        if (!$this->esSuyo($userId, $deckId)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_deck SET share_token = :token WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute(['token' => $token, 'id' => $deckId, 'user_id' => $userId]);

        return true;
    }

    /**
     * El token → el mazo y su dueño. Lectura de índice único
     * (`uq_deck_share_token`), no un recorrido.
     *
     * `share_token` es `NULL` mientras el mazo no se comparte y el `=` de SQL
     * **nunca casa con NULL**, así que los mazos no compartidos son invisibles
     * aquí sin necesidad de un `IS NOT NULL` que alguien pudiera quitar.
     */
    public function findByShareToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id FROM mtg_deck WHERE share_token = :token'
        );

        $stmt->execute(['token' => $token]);

        $fila = $stmt->fetch();

        return $fila === false
            ? null
            : ['userId' => (int) $fila['user_id'], 'deckId' => (int) $fila['id']];
    }

    public function allByUser(int $userId, ?DeckStatus $status = null): array
    {
        $where  = ['d.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if ($status !== null) {
            $where[]          = 'd.status = :status';
            $params['status'] = $status->value;
        }

        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_MAZO . self::ORIGEN_MAZO . '
              WHERE ' . implode(' AND ', $where)
              . self::AGRUPACION_MAZO . '
              ORDER BY d.updated_at DESC, d.id DESC'
        );

        $stmt->execute($params);

        return array_map([$this, 'mazoAContrato'], $stmt->fetchAll());
    }

    public function cards(int $userId, int $deckId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_CARTA . self::ORIGEN_CARTA . '
              WHERE dc.deck_id = :deck_id AND d.user_id = :user_id
              -- `board` es un ENUM y MySQL lo ordena por el orden de DECLARACIÓN,
              -- no alfabéticamente: sale main, side, commander, companion,
              -- planes, schemes, tokens, que es exactamente el orden de
              -- `Board::cases()`. Así la agrupación de `GetDeck` y esta consulta
              -- no pueden discrepar, que es lo que pasaría con un FIELD() escrito
              -- a mano en el que hay que acordarse de tocar los dos sitios.
              ORDER BY dc.board, c.name, dc.id'
        );

        $stmt->execute(['deck_id' => $deckId, 'user_id' => $userId]);

        return array_map([$this, 'cartaAContrato'], $stmt->fetchAll());
    }

    public function addCard(int $userId, DeckCard $card): ?int
    {
        // El mazo se comprueba ANTES de escribir porque `mtg_deck_card` no lleva
        // `user_id`: sin esto, un deck_id ajeno pasaría la clave foránea sin
        // problema y la carta acabaría en el mazo de otro.
        if (!$this->esSuyo($userId, $card->deckId)) {
            return null;
        }

        $stmt = $this->db->prepare(self::UPSERT_CARTA);
        $stmt->execute($card->aFila());

        return (int) $this->db->lastInsertId();
    }

    public function addCards(int $userId, int $deckId, array $cards): ?int
    {
        // La propiedad se comprueba UNA vez para todo el lote: es la diferencia
        // con llamar `addCard()` cien veces, que preguntaría cien.
        if (!$this->esSuyo($userId, $deckId)) {
            return null;
        }

        if ($cards === []) {
            return 0;
        }

        // Una sola sentencia preparada y N ejecuciones, el patrón de
        // `upsertLote()`. Sin transacción propia a propósito: en la importación
        // la abre `ApplyImport`, que necesita meter dentro también la colección.
        $stmt       = $this->db->prepare(self::UPSERT_CARTA);
        $ejemplares = 0;

        foreach ($cards as $carta) {
            $fila = $carta->aFila();
            // El mazo lo pone quien llama: una línea con otro `deck_id` iría a
            // parar a un mazo que nadie ha comprobado.
            $fila['deck_id'] = $deckId;

            $stmt->execute($fila);

            $ejemplares += $carta->count;
        }

        return $ejemplares;
    }

    public function findCardById(int $userId, int $deckId, int $cardId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_CARTA . self::ORIGEN_CARTA . '
              WHERE dc.id = :id AND dc.deck_id = :deck_id AND d.user_id = :user_id
              LIMIT 1'
        );

        $stmt->execute(['id' => $cardId, 'deck_id' => $deckId, 'user_id' => $userId]);

        $fila = $stmt->fetch();

        return $fila === false ? null : $this->cartaAContrato($fila);
    }

    public function changeCardCount(int $userId, int $deckId, int $cardId, int $count): ?array
    {
        // Cantidad cero BORRA la línea. Dejarla a 0 falsearía el tamaño del
        // mazo, que es justo lo que M6 mira para avisar del mínimo de 60 o 100.
        if ($count <= 0) {
            $this->removeCard($userId, $deckId, $cardId);

            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_deck_card dc
               JOIN mtg_deck d ON d.id = dc.deck_id
                SET dc.`count` = :count
              WHERE dc.id = :id AND dc.deck_id = :deck_id AND d.user_id = :user_id'
        );

        $stmt->execute([
            'count'   => $count,
            'id'      => $cardId,
            'deck_id' => $deckId,
            'user_id' => $userId,
        ]);

        return $this->findCardById($userId, $deckId, $cardId);
    }

    public function removeCard(int $userId, int $deckId, int $cardId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE dc FROM mtg_deck_card dc
               JOIN mtg_deck d ON d.id = dc.deck_id
              WHERE dc.id = :id AND dc.deck_id = :deck_id AND d.user_id = :user_id'
        );

        $stmt->execute(['id' => $cardId, 'deck_id' => $deckId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
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
        // Copia literal del patrón de `MySqlCollectionRepository::changeGrade()`,
        // y por el mismo motivo de esquema: las cuatro columnas están DENTRO de
        // `uq_deck_card`, así que el cambio MUEVE la fila a otra combinación de
        // la clave, que puede estar ocupada. Si el destino existe hay que sumar
        // allí y borrar aquí, y esas dos escrituras deben ocurrir en la misma
        // transacción: a medias, esos ejemplares estarían contados dos veces o
        // en ninguna parte.
        $this->db->beginTransaction();

        try {
            // FOR UPDATE bloquea la fila de origen hasta el commit. Sin él, dos
            // cambios simultáneos sobre la misma línea podrían leer los dos la
            // misma cantidad y perder uno de los dos movimientos.
            $stmt = $this->db->prepare(
                'SELECT dc.printing_uuid, dc.finish, dc.language, dc.condition_grade, dc.board, dc.`count`
                   FROM mtg_deck_card dc
                   JOIN mtg_deck d ON d.id = dc.deck_id
                  WHERE dc.id = :id AND dc.deck_id = :deck_id AND d.user_id = :user_id
                  FOR UPDATE'
            );

            $stmt->execute(['id' => $cardId, 'deck_id' => $deckId, 'user_id' => $userId]);

            $origen = $stmt->fetch();

            if ($origen === false) {
                $this->db->commit();

                return null;
            }

            $destino = [
                'finish'          => $finish?->value    ?? $origen['finish'],
                'language'        => $language?->value  ?? $origen['language'],
                'condition_grade' => $condition?->value ?? $origen['condition_grade'],
                'board'           => $board?->value     ?? $origen['board'],
            ];

            // Ya es esa versión: ni se mueve ni se funde nada. Se responde la
            // línea tal cual en vez de un error, porque pedir lo que ya es
            // cierto no es un fallo del cliente.
            if (
                $destino['finish'] === $origen['finish']
                && $destino['language'] === $origen['language']
                && $destino['condition_grade'] === $origen['condition_grade']
                && $destino['board'] === $origen['board']
            ) {
                $this->db->commit();

                return ['card' => $this->findCardById($userId, $deckId, $cardId), 'merged' => false];
            }

            $destinoId = $this->idDeLaCombinacion($deckId, $origen['printing_uuid'], $destino);

            if ($destinoId === null) {
                // Destino libre: basta con reescribir las columnas, y así la
                // línea conserva su `id` —la tabla puede actualizarla en su
                // sitio sin recargar—.
                $mover = $this->db->prepare(
                    'UPDATE mtg_deck_card
                        SET finish          = :finish,
                            language        = :language,
                            condition_grade = :condition_grade,
                            board           = :board
                      WHERE id = :id AND deck_id = :deck_id'
                );

                $mover->execute($destino + ['id' => $cardId, 'deck_id' => $deckId]);

                $resultadoId = $cardId;
                $fundida     = false;
            } else {
                // Destino ocupado: las dos líneas son la misma carta en el mismo
                // estado y en la misma zona, así que se suman y el origen
                // desaparece.
                $sumar = $this->db->prepare(
                    'UPDATE mtg_deck_card SET `count` = `count` + :count
                      WHERE id = :id AND deck_id = :deck_id'
                );

                $sumar->execute([
                    'count'   => (int) $origen['count'],
                    'id'      => $destinoId,
                    'deck_id' => $deckId,
                ]);

                $borrar = $this->db->prepare(
                    'DELETE FROM mtg_deck_card WHERE id = :id AND deck_id = :deck_id'
                );

                $borrar->execute(['id' => $cardId, 'deck_id' => $deckId]);

                $resultadoId = $destinoId;
                $fundida     = true;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return ['card' => $this->findCardById($userId, $deckId, $resultadoId), 'merged' => $fundida];
    }

    public function consumo(int $userId): array
    {
        // La consulta del plan, con los alias de este fichero (`dc` es
        // mtg_deck_card y `d` es mtg_deck).
        //
        // `d.status = 'built'` y NO `!= 'dismantled'`: los tres estados no son
        // simétricos y solo el construido consume colección
        // (`DeckStatus::consumeColeccion()`). Meter aquí los `building` haría que
        // la app avisara de conflictos que no existen.
        //
        // El `HAVING` es lo que convierte esto en una lista de conflictos: sin
        // sobreasignación no hay nada que enseñar.
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_CRUCE . "
               FROM mtg_deck_card dc
               JOIN mtg_deck d ON d.id      = dc.deck_id
                              AND d.user_id = :user_id
                              AND d.status  = 'built'"
              . self::CRUCE_COLECCION . "
              WHERE dc.board <> 'tokens'"
              . self::AGRUPACION_CRUCE . '
             HAVING reclamado > enColeccion'
              . self::ORDEN_CRUCE
        );

        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);

        $conflictos = array_map([$this, 'lineaDelCruce'], $stmt->fetchAll());

        if ($conflictos === []) {
            return [];
        }

        // Un conflicto sin nombres no sirve de nada: lo que la UI ofrece es
        // desmontar UNO de los mazos implicados, así que hay que decir cuáles
        // son. Y esto NO cambia ningún estado: informa.
        $porClave = $this->mazosQueReclaman($userId);

        foreach ($conflictos as $i => $conflicto) {
            $conflictos[$i]['decks'] = $porClave[$this->claveDelCruce(
                $conflicto['printingUuid'],
                $conflicto['finish'],
                $conflicto['language'],
                $conflicto['condition']
            )] ?? [];
        }

        return $conflictos;
    }

    public function faltantes(int $userId, int $deckId): array
    {
        // El mismo cruce, con dos diferencias deliberadas: un solo `deck_id` y
        // **sin** filtrar por `status`. Lo que se quiere saber de un mazo en
        // construcción es justo cuánto le falta, y `building` nunca pasaría un
        // filtro de `built`. Tampoco hay `HAVING`: aquí salen todas las líneas,
        // porque el contrato pide `claimed`/`inCollection`/`free` de cada una y
        // el precio solo de lo que falta.
        //
        // El `user_id` sigue en el `JOIN` aunque venga el `deck_id`: `mtg_deck.id`
        // es un autoincremental global y sin él bastaría con probar números para
        // leer el mazo de otro.
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_CRUCE . '
               FROM mtg_deck_card dc
               JOIN mtg_deck d ON d.id = dc.deck_id AND d.user_id = :user_id'
              . self::CRUCE_COLECCION . "
              WHERE dc.deck_id = :deck_id AND dc.board <> 'tokens'"
              . self::AGRUPACION_CRUCE
              . self::ORDEN_CRUCE
        );

        $stmt->execute([
            'user_id'  => $userId,
            'user_id2' => $userId,
            'deck_id'  => $deckId,
        ]);

        return array_map([$this, 'lineaDelCruce'], $stmt->fetchAll());
    }

    public function faltantesDeTodos(int $userId): array
    {
        // El MISMO cruce de `faltantes()` —sus mismas constantes, sus mismos
        // filtros— con el `deck_id` dentro del `GROUP BY` en vez de dentro del
        // `WHERE`. No es una consulta nueva: es la de un mazo hecha de todos a
        // la vez, que es lo que pide la regla del repositorio.
        //
        // Va en DOS niveles y no en uno porque el recorte a 0 es POR LÍNEA: la
        // interna agrega las cinco dimensiones y saca `GREATEST(0, pedidas -
        // tengo)`, y la externa suma esos faltantes por mazo. Con un solo nivel,
        // las tres copias que te sobran de un *Sol Ring* taparían las tres que
        // te faltan de otra carta y el mazo diría que está completo — es
        // exactamente la suma que hace `AnalyzeDeckAvailability::respuesta()`.
        //
        // Sin filtro por `status`, igual que `faltantes()`: lo que se quiere
        // saber de un mazo en construcción es justo cuánto le falta. Y el
        // `user_id` sigue en el `JOIN` con `mtg_deck`, repetido como
        // `:user_id2` en el cruce con la colección porque con
        // `ATTR_EMULATE_PREPARES = false` MySQL no admite el mismo marcador dos
        // veces.
        $stmt = $this->db->prepare(
            'SELECT lineas.deck_id, SUM(lineas.faltan) AS faltan
               FROM (SELECT dc.deck_id,
                            GREATEST(0, SUM(dc.`count`) - COALESCE(MAX(ci.quantity), 0)) AS faltan
                       FROM mtg_deck_card dc
                       JOIN mtg_deck d ON d.id = dc.deck_id AND d.user_id = :user_id'
                      . self::CRUCE_COLECCION . "
                      WHERE dc.board <> 'tokens'
                      GROUP BY dc.deck_id, dc.printing_uuid, dc.finish, dc.language,
                               dc.condition_grade, c.name, p.set_code) lineas
              GROUP BY lineas.deck_id"
        );

        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);

        $porMazo = [];

        foreach ($stmt->fetchAll() as $fila) {
            $porMazo[(int) $fila['deck_id']] = (int) $fila['faltan'];
        }

        return $porMazo;
    }

    public function variantesEnColeccion(int $userId, string $printingUuid): array
    {
        // Las versiones que el usuario TIENE de esa carta, con lo que ya le
        // reclaman sus mazos construidos restado aparte.
        //
        // Se parte de `mtg_collection_item` y no de `mtg_deck_card` a propósito:
        // lo que la UI ofrece elegir es lo que hay en las cajas, no lo que
        // algún mazo pide. Por eso el subselect de lo reclamado entra por
        // `LEFT JOIN`: una versión que tienes y que no reclama nadie sale con
        // `reclamado = 0` en vez de desaparecer, que es justamente la que el
        // usuario quiere ver.
        //
        // **`is_wishlist = 0`, sin excepción**: una carta que quieres no es una
        // carta que tengas, y ofrecerla aquí haría que el mazo pidiera algo que
        // no está en ninguna caja.
        //
        // Los cuatro marcadores son dos valores repetidos con nombres distintos:
        // con `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un
        // marcador nombrado en dos puntos de la misma sentencia. Misma trampa
        // que `:user_id2` en el cruce de arriba.
        $stmt = $this->db->prepare(
            "SELECT ci.finish,
                    ci.language,
                    ci.condition_grade,
                    ci.quantity,
                    COALESCE(reclamo.reclamado, 0) AS reclamado,
                    pc.price_eur AS priceEur
               FROM mtg_collection_item ci
          LEFT JOIN (
                    SELECT dc.finish, dc.language, dc.condition_grade,
                           SUM(dc.`count`) AS reclamado
                      FROM mtg_deck_card dc
                      JOIN mtg_deck d ON d.id      = dc.deck_id
                                     AND d.user_id = :user_id2
                                     AND d.status  = 'built'
                     WHERE dc.printing_uuid = :printing_uuid2
                       AND dc.board <> 'tokens'
                     GROUP BY dc.finish, dc.language, dc.condition_grade
                    ) reclamo
                 ON reclamo.finish          = ci.finish
                AND reclamo.language        = ci.language
                AND reclamo.condition_grade = ci.condition_grade
          LEFT JOIN mtg_price_current pc
                 ON pc.printing_uuid = ci.printing_uuid
                AND pc.finish        = ci.finish
              WHERE ci.user_id       = :user_id
                AND ci.printing_uuid = :printing_uuid
                AND ci.is_wishlist   = 0
              ORDER BY ci.finish, ci.language, ci.condition_grade"
        );

        $stmt->execute([
            'user_id'        => $userId,
            'user_id2'       => $userId,
            'printing_uuid'  => $printingUuid,
            'printing_uuid2' => $printingUuid,
        ]);

        return array_map([$this, 'varianteAContrato'], $stmt->fetchAll());
    }

    public function legalidad(int $userId, int $deckId, string $formato): array
    {
        // `LEFT JOIN`, y es LO ÚNICO importante de esta consulta: `mtg_legality`
        // solo tiene fila donde la carta tiene estatus, así que un `JOIN` a secas
        // haría DESAPARECER de la lista justo las cartas que hay que marcar. La
        // ausencia sale aquí como un NULL y la traduce `LegalityStatus`.
        //
        // `DISTINCT` porque un mazo lleva la misma carta en varias líneas —cuatro
        // Lightning Bolt en NM y dos en LP son dos líneas, un solo `oracle_id`— y
        // la legalidad es de la carta, no de la edición ni del estado. Además
        // evita el `GROUP BY` que `ONLY_FULL_GROUP_BY` exigiría por `l.status`.
        //
        // Y los tokens fuera, como en todos los agregados de este repositorio:
        // un token no se juega ni se posee (`Board::esPoseible()`).
        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.oracle_id AS oracleId, l.status
               FROM mtg_deck_card dc
               JOIN mtg_deck      d ON d.id   = dc.deck_id
               JOIN mtg_printing  p ON p.uuid = dc.printing_uuid
          LEFT JOIN mtg_legality  l ON l.oracle_id = p.oracle_id
                                   AND l.format    = :format
              WHERE dc.deck_id = :deck_id
                AND d.user_id  = :user_id
                AND dc.board  <> 'tokens'"
        );

        $stmt->execute([
            'deck_id' => $deckId,
            'user_id' => $userId,
            'format'  => $formato,
        ]);

        $legalidad = [];

        foreach ($stmt->fetchAll() as $fila) {
            $legalidad[(string) $fila['oracleId']] = $fila['status'];
        }

        return $legalidad;
    }

    public function formatoConocido(string $formato): bool
    {
        // Se pregunta a `mtg_format`, que es el `SELECT DISTINCT format FROM
        // mtg_legality` ya hecho —lo deja escrito `catalog:import` al terminar—.
        //
        // Antes esto iba contra `mtg_legality` con un `EXISTS`, y el truco tenía
        // su motivo: la PK es `(oracle_id, format)`, así que filtrar por formato
        // recorre el índice y el `EXISTS` cortaba en la primera fila (3 ms,
        // contra los 66 ms del `DISTINCT` completo). Con `mtg_format` ese apaño
        // ya no hace falta: `format` ES la clave primaria de una tabla de 21
        // filas, y esto es una lectura de índice única. El `DISTINCT` de 66 ms
        // no ha desaparecido —lo paga la ingesta, una vez cada tres meses— pero
        // deja de pagarse en cada carga de la ficha de un mazo.
        //
        // Medido el 2026-09-12 contra la BD de dev, en caliente y sobre 50
        // llamadas: 0,09 ms por llamada aquí frente a 0,13 ms del `EXISTS`
        // viejo. La diferencia es poca porque el `EXISTS` ya cortaba pronto; lo
        // que de verdad cambia es `formatosConocidos()`, justo debajo.
        $stmt = $this->db->prepare(
            'SELECT EXISTS(SELECT 1 FROM mtg_format WHERE format = :format) AS conocido'
        );

        $stmt->execute(['format' => $formato]);

        return (bool) $stmt->fetchColumn();
    }

    public function formatosConocidos(): array
    {
        // La tabla entera, que son 21 filas: no hay `LIMIT` que poner ni cursor
        // que pasear. El `ORDER BY` es el de la PK, así que MySQL lo sirve del
        // índice sin ordenar nada.
        //
        // Y **no** es un `SELECT DISTINCT format FROM mtg_legality`. Escribirlo
        // así funcionaría y costaría una ficha de mazo entera: medido el
        // 2026-09-12 sobre las 332.757 filas de dev, **62,8 ms** el `DISTINCT`
        // contra **0,08 ms** esto. Es la deuda entera que `mtg_format` salda.
        $stmt = $this->db->query('SELECT format FROM mtg_format ORDER BY format');

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Una versión de la carta tal y como la pinta el desplegable de M5.
     *
     * `free` se recorta a 0 igual que en `lineaDelCruce()`: «te quedan -2
     * libres» no se puede enseñar. Y `priceEur` conserva el NULL, que es lo que
     * permite decir «sin precio» en vez de «0 €».
     *
     * La clave del estado sale como `condition_grade` —y no como `condition`,
     * que es el nombre que usan las líneas del mazo— porque esto se manda tal
     * cual de vuelta en `deck_card_change`, y ese contrato lo llama así.
     *
     * @param  array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function varianteAContrato(array $f): array
    {
        $cantidad = (int) $f['quantity'];

        return [
            'finish'          => $f['finish'],
            'language'        => $f['language'],
            'condition_grade' => $f['condition_grade'],
            'quantity'        => $cantidad,
            'claimed'         => (int) $f['reclamado'],
            'free'            => max(0, $cantidad - (int) $f['reclamado']),
            'priceEur'        => $f['priceEur'] !== null ? (float) $f['priceEur'] : null,
        ];
    }

    /**
     * Lo que el mazo reclama de la colección, **agregado por las cinco
     * dimensiones** y sin los tokens.
     *
     * Se agrega y no se recorre línea a línea porque la misma carta puede estar
     * en el main y en el side: son dos líneas del mazo pero **una sola** fila de
     * colección, y descontarlas por separado partiría el `shortfall` en dos
     * mitades que no dicen nada («te faltaba 1» dos veces en vez de «te faltan
     * 2»).
     *
     * @return list<array<string, mixed>>
     */
    private function lineasADescontar(int $deckId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dc.printing_uuid, dc.finish, dc.language, dc.condition_grade,
                    c.name, p.set_code, SUM(dc.`count`) AS pedidas
               FROM mtg_deck_card dc
               JOIN mtg_printing  p ON p.uuid      = dc.printing_uuid
               JOIN mtg_card      c ON c.oracle_id = p.oracle_id
              WHERE dc.deck_id = :deck_id AND dc.board <> 'tokens'
              GROUP BY dc.printing_uuid, dc.finish, dc.language, dc.condition_grade, c.name, p.set_code"
        );

        $stmt->execute(['deck_id' => $deckId]);

        return $stmt->fetchAll();
    }

    /**
     * Resta una línea del mazo de la colección, **sin fallar si no llega**.
     *
     * La discrepancia es el caso esperado y no el error: vendiste la carta y
     * nunca actualizaste el mazo. Se resta hasta 0, se borra la fila si llega a
     * cero —igual que `changeQuantity(0)`, para no dejar fantasmas que sigan
     * contando como «cartas únicas» en el dashboard— y lo que falta se devuelve
     * para que la UI lo enseñe.
     *
     * `is_wishlist = 0` en el `WHERE`: sin ese filtro, desmontar un mazo
     * descontaría de la lista de deseos.
     *
     * @param  array<string, mixed> $linea
     * @return array<string, mixed> la línea con `requested`, `removed` y `missing`
     */
    private function descontarDeLaColeccion(int $userId, array $linea): array
    {
        $pedidas = (int) $linea['pedidas'];

        $stmt = $this->db->prepare(
            'SELECT id, quantity
               FROM mtg_collection_item
              WHERE user_id         = :user_id
                AND printing_uuid   = :printing_uuid
                AND finish          = :finish
                AND language        = :language
                AND condition_grade = :condition_grade
                AND is_wishlist     = 0
              FOR UPDATE'
        );

        $stmt->execute([
            'user_id'         => $userId,
            'printing_uuid'   => $linea['printing_uuid'],
            'finish'          => $linea['finish'],
            'language'        => $linea['language'],
            'condition_grade' => $linea['condition_grade'],
        ]);

        $fila     = $stmt->fetch();
        $enPoder  = $fila === false ? 0 : (int) $fila['quantity'];
        $quitadas = min($enPoder, $pedidas);

        if ($fila !== false) {
            if ($enPoder - $quitadas <= 0) {
                $borrar = $this->db->prepare(
                    'DELETE FROM mtg_collection_item WHERE id = :id AND user_id = :user_id'
                );

                $borrar->execute(['id' => (int) $fila['id'], 'user_id' => $userId]);
            } else {
                $restar = $this->db->prepare(
                    'UPDATE mtg_collection_item SET quantity = quantity - :quantity
                      WHERE id = :id AND user_id = :user_id'
                );

                $restar->execute([
                    'quantity' => $quitadas,
                    'id'       => (int) $fila['id'],
                    'user_id'  => $userId,
                ]);
            }
        }

        return [
            'printingUuid' => $linea['printing_uuid'],
            'name'         => $linea['name'],
            'setCode'      => $linea['set_code'],
            'finish'       => $linea['finish'],
            'language'     => $linea['language'],
            'condition'    => $linea['condition_grade'],
            'requested'    => $pedidas,
            'removed'      => $quitadas,
            'missing'      => $pedidas - $quitadas,
        ];
    }

    /**
     * El `id` de la línea que ocuparía el `UNIQUE KEY` tras el cambio, o null si
     * esa combinación está libre.
     *
     * Se consulta con `FOR UPDATE` dentro de la misma transacción que el
     * movimiento: sobre una combinación que todavía no existe, MySQL toma el
     * hueco del índice único y nadie puede insertarla entre esta lectura y el
     * `UPDATE` de más abajo.
     *
     * @param array<string, string> $destino
     */
    private function idDeLaCombinacion(int $deckId, string $printingUuid, array $destino): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id
               FROM mtg_deck_card
              WHERE deck_id         = :deck_id
                AND printing_uuid   = :printing_uuid
                AND finish          = :finish
                AND language        = :language
                AND condition_grade = :condition_grade
                AND board           = :board
              FOR UPDATE'
        );

        $stmt->execute($destino + ['deck_id' => $deckId, 'printing_uuid' => $printingUuid]);

        $fila = $stmt->fetch();

        return $fila === false ? null : (int) $fila['id'];
    }

    /**
     * Qué mazos construidos reclaman cada línea, y cuánto pide cada uno.
     *
     * Es una segunda consulta y no un `GROUP_CONCAT` metido en la primera a
     * propósito: el nombre del mazo lo escribe el usuario y puede llevar comas,
     * dos puntos o cualquier separador que se eligiera para pegar id y nombre, y
     * recomponerlo después sería adivinar. Y es UNA consulta más, no una por
     * conflicto: los mazos de una persona son veinte filas, no un catálogo.
     *
     * @return array<string, list<array<string, mixed>>> clave de las cinco
     *         dimensiones → los mazos que la reclaman
     */
    private function mazosQueReclaman(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dc.printing_uuid, dc.finish, dc.language, dc.condition_grade,
                    d.id   AS deckId,
                    d.name AS deckName,
                    SUM(dc.`count`) AS reclamado
               FROM mtg_deck_card dc
               JOIN mtg_deck d ON d.id      = dc.deck_id
                              AND d.user_id = :user_id
                              AND d.status  = 'built'
              WHERE dc.board <> 'tokens'
              GROUP BY dc.printing_uuid, dc.finish, dc.language, dc.condition_grade, d.id, d.name
              ORDER BY d.name, d.id"
        );

        $stmt->execute(['user_id' => $userId]);

        $porClave = [];

        foreach ($stmt->fetchAll() as $fila) {
            $clave = $this->claveDelCruce(
                (string) $fila['printing_uuid'],
                (string) $fila['finish'],
                (string) $fila['language'],
                (string) $fila['condition_grade']
            );

            $porClave[$clave][] = [
                'id'      => (int) $fila['deckId'],
                'name'    => $fila['deckName'],
                'claimed' => (int) $fila['reclamado'],
            ];
        }

        return $porClave;
    }

    /** Las cinco dimensiones hechas clave, para casar las dos consultas. */
    private function claveDelCruce(
        string $printingUuid,
        string $finish,
        string $language,
        string $condition
    ): string {
        return implode('|', [$printingUuid, $finish, $language, $condition]);
    }

    /**
     * Una línea del cruce, con los tipos de verdad y las tres cuentas ya hechas.
     *
     * `free` y `missing` son excluyentes por construcción —o te sobran o te
     * faltan— y las dos se recortan a 0 en vez de dejar un negativo: «te faltan
     * -2» no se puede pintar.
     *
     * `priceEur` conserva el NULL a propósito, que es lo que permite decir «sin
     * precio» en vez de «0 €»; `missingValueEur` sí trata el desconocido como
     * cero, porque sumar con NULL da NULL. Mismo criterio que la colección.
     *
     * @param  array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function lineaDelCruce(array $f): array
    {
        $reclamado   = (int) $f['reclamado'];
        $enColeccion = (int) $f['enColeccion'];
        $faltan      = max(0, $reclamado - $enColeccion);

        return [
            'printingUuid'    => $f['printing_uuid'],
            'name'            => $f['name'],
            'setCode'         => $f['set_code'],
            'finish'          => $f['finish'],
            'language'        => $f['language'],
            'condition'       => $f['condition_grade'],
            'claimed'         => $reclamado,
            'inCollection'    => $enColeccion,
            'free'            => max(0, $enColeccion - $reclamado),
            'missing'         => $faltan,
            'priceEur'        => $f['priceEur'] !== null ? (float) $f['priceEur'] : null,
            'missingValueEur' => round($faltan * (float) ($f['priceEur'] ?? 0), 2),
        ];
    }

    private function esSuyo(int $userId, int $deckId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM mtg_deck WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $deckId, 'user_id' => $userId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Tipos de verdad: PDO devuelve todo como string.
     *
     * @param  array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function mazoAContrato(array $f): array
    {
        return [
            'id'        => (int) $f['id'],
            'name'      => $f['name'],
            'status'    => $f['status'],
            'format'    => $f['format'],
            'notes'     => $f['notes'],
            'createdAt' => $f['createdAt'],
            'updatedAt' => $f['updatedAt'],
            'cards'     => (int) $f['cards'],
            'cardLines' => (int) $f['cardLines'],
            'valueEur'  => round((float) $f['valueEur'], 2),
        ];
    }

    /**
     * @param  array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function cartaAContrato(array $f): array
    {
        return [
            'id'              => (int) $f['id'],
            'deckId'          => (int) $f['deckId'],
            'printingUuid'    => $f['printingUuid'],
            'oracleId'        => $f['oracleId'],
            'name'            => $f['name'],
            'setCode'         => $f['setCode'],
            'setName'         => $f['setName'],
            'releaseDate'     => $f['releaseDate'],
            'collectorNumber' => $f['collectorNumber'],
            'rarity'          => $f['rarity'],
            'manaCost'        => $f['manaCost'],
            'colors'          => $f['colors'],
            'colorIdentity'   => $f['colorIdentity'],
            'typeLine'        => $f['typeLine'],
            'scryfallId'      => $f['scryfallId'],
            'finish'          => $f['finish'],
            'language'        => $f['language'],
            'condition'       => $f['condition'],
            'board'           => $f['board'],
            'count'           => (int) $f['count'],
            // NULL se conserva a propósito: es lo que permite decir "sin precio"
            // en vez de "0 €".
            'priceEur'        => $f['priceEur'] !== null ? (float) $f['priceEur'] : null,
            'lineValue'       => (float) $f['lineValue'],
        ];
    }
}
