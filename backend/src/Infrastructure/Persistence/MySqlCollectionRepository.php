<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Cursor;
use App\Domain\Collection\CollectionCriteria;
use App\Domain\Collection\CollectionItem;
use App\Domain\Collection\Condition;
use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * La colección en MySQL.
 *
 * Todo gira alrededor de **la consulta central de la app** (`self::ORIGEN`), y
 * de ella hay tres detalles que no son casuales:
 *
 *  - **`LEFT JOIN` con los precios, jamás `JOIN`.** Muchísimos printings no
 *    cotizan en Cardmarket. Un `JOIN` normal haría desaparecer esas cartas de la
 *    colección del usuario: el peor bug posible en una app de inventario, y
 *    además silencioso —nadie echa de menos lo que no sabe que falta—.
 *  - **El precio se une por `(printing_uuid, finish)`.** Un foil vale otra cosa
 *    que su versión normal, y `etched` otra distinta. Unir solo por printing
 *    daría valoraciones falsas por sistema.
 *  - **`COALESCE(..., 0)` en el valor de línea, pero `price_eur` a pelo en la
 *    columna mostrada.** El total no debe reventar por una carta sin precio,
 *    pero la ficha tiene que poder decir "sin precio" y no "0 €".
 *
 * Y todo lo que escribe pasa por el `UNIQUE KEY` de seis columnas: añadir suma,
 * no duplica.
 */
class MySqlCollectionRepository implements CollectionRepositoryInterface
{
    /** El contrato de una línea de colección, común a la lista y a la ficha. */
    private const COLUMNAS = "
        ci.id,
        ci.printing_uuid   AS printingUuid,
        ci.finish,
        ci.language,
        ci.condition_grade AS `condition`,
        ci.quantity,
        ci.is_wishlist     AS isWishlist,
        ci.notes,
        ci.created_at      AS createdAt,
        ci.updated_at      AS updatedAt,
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
        (ci.quantity * COALESCE(pc.price_eur, 0)) AS lineValue
    ";

    /**
     * La consulta central. El `LEFT JOIN` de la última línea es EL detalle de
     * este plan; convertirlo en `JOIN` oculta cartas.
     */
    private const ORIGEN = "
          FROM mtg_collection_item ci
          JOIN mtg_printing p ON p.uuid      = ci.printing_uuid
          JOIN mtg_card     c ON c.oracle_id = p.oracle_id
          JOIN mtg_set      s ON s.code      = p.set_code
     LEFT JOIN mtg_price_current pc
            ON pc.printing_uuid = ci.printing_uuid
           AND pc.finish        = ci.finish
    ";

    /**
     * **La** escritura de la colección, y la única: la usan el alta de una carta
     * y la importación de un fichero entero.
     *
     * `quantity = quantity + VALUES(quantity)` es la línea que hace que añadir
     * dos veces la misma carta SUME en vez de duplicar, y la que hace que
     * reimportar un fichero sea seguro.
     *
     * `id = LAST_INSERT_ID(id)` no es decorativo: sin él, tras un
     * ON DUPLICATE KEY UPDATE, lastInsertId() no devuelve el id de la fila que se
     * acaba de actualizar y el cliente no podría releerla.
     *
     * Las notas solo se sobreescriben si vienen: añadir un segundo ejemplar sin
     * comentario no debe borrar el comentario que ya había.
     */
    private const UPSERT = '
        INSERT INTO mtg_collection_item
               (user_id, printing_uuid, finish, language, condition_grade, quantity, is_wishlist, notes)
        VALUES (:user_id, :printing_uuid, :finish, :language, :condition_grade, :quantity, :is_wishlist, :notes)
        ON DUPLICATE KEY UPDATE
               quantity = quantity + VALUES(quantity),
               notes    = COALESCE(VALUES(notes), notes),
               id       = LAST_INSERT_ID(id)
    ';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function upsert(CollectionItem $item): int
    {
        $stmt = $this->db->prepare(self::UPSERT);
        $stmt->execute($item->aFila());

        return (int) $this->db->lastInsertId();
    }

    /**
     * La importación entera, con **la misma sentencia** que el alta de una carta.
     *
     * Que sea `self::UPSERT` y no un SQL propio es justo lo que hace cierta la
     * promesa del Plan - Importación de Colecciones: reimportar el mismo fichero
     * SUMA cantidades y **no crea filas nuevas**, porque choca contra el mismo
     * `UNIQUE KEY` de seis columnas contra el que choca el botón "Añadir". Una
     * segunda escritura escrita a mano aquí sería una segunda forma de entrar a
     * la tabla, y las dos se desincronizarían en cuanto una cambiara.
     *
     * Dos decisiones del método:
     *
     *  - **Una transacción para el lote entero.** Un `printing_uuid` que no está
     *    en el catálogo revienta por clave foránea, y el plan pide que un fichero
     *    corrupto dé un error claro y no una importación a medias: o entran las
     *    3.412 filas o no entra ninguna.
     *  - **`rowCount()` distingue insertada de sumada.** Tras un
     *    `ON DUPLICATE KEY UPDATE`, MySQL devuelve 1 si insertó y 2 si actualizó
     *    (0 si la fila no cambió, que aquí no pasa porque la cantidad siempre
     *    sube). Es lo que alimenta el `inserted` / `updated` del contrato sin
     *    releer la tabla.
     */
    public function upsertLote(array $items): array
    {
        if ($items === []) {
            return ['inserted' => 0, 'updated' => 0, 'totalQuantity' => 0];
        }

        $stmt = $this->db->prepare(self::UPSERT);

        $insertadas   = 0;
        $actualizadas = 0;
        $ejemplares   = 0;

        // Si ya hay una transacción abierta manda quien la abrió: anidar
        // beginTransaction() en PDO no crea una transacción, la ignora.
        $propia = !$this->db->inTransaction();

        if ($propia) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($items as $item) {
                $stmt->execute($item->aFila());

                if ($stmt->rowCount() === 1) {
                    $insertadas++;
                } else {
                    $actualizadas++;
                }

                $ejemplares += $item->quantity;
            }

            if ($propia) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return [
            'inserted'      => $insertadas,
            'updated'       => $actualizadas,
            'totalQuantity' => $ejemplares,
        ];
    }

    public function changeQuantity(int $userId, int $itemId, int $quantity): ?array
    {
        // Cantidad cero BORRA la fila. Dejarla a 0 llenaría la colección de
        // fantasmas que siguen contando como "cartas únicas" en el dashboard.
        if ($quantity <= 0) {
            $this->remove($userId, $itemId);

            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_collection_item
                SET quantity = :quantity
              WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([
            'quantity' => $quantity,
            'id'       => $itemId,
            'user_id'  => $userId,
        ]);

        return $this->findById($userId, $itemId);
    }

    public function changeGrade(int $userId, int $itemId, Condition $condicion): ?array
    {
        // Cambiar de estado es mover la línea ENTERA a otra combinación de
        // `uq_item`, que es `moveLine()` sin `quantity`. Todo lo que este método
        // hacía a mano —la transacción, el FOR UPDATE, la fusión— vive ahora
        // allí: con dos copias, el camino viejo y el de cumplir un deseo se
        // desincronizarían en cuanto uno de los dos cambiara.
        return $this->moveLine($userId, $itemId, ['condition' => $condicion]);
    }

    /**
     * Mover una línea dentro del `UNIQUE KEY`, total o parcialmente.
     *
     * Tiene **tres caminos y no uno** porque los dos riesgos que hay aquí se
     * contradicen, y esto es lo que los concilia (medido contra la BD de dev):
     *
     *  - **Destino ocupado** → `UPDATE` sumando allí y descuento aquí. El
     *    `FOR UPDATE` cae sobre una fila real, así que serializa de verdad: la
     *    segunda sesión espera al `COMMIT` de la primera.
     *  - **Destino libre y movimiento total** → `UPDATE` de las columnas de la
     *    clave sobre la propia fila, que así **conserva su `id`**. Es un
     *    contrato con la vista —puede actualizar la línea en su sitio sin
     *    recargar— y está testeado; por eso este camino NO puede ir por
     *    `INSERT ... ODKU`, que crearía fila nueva y borraría la vieja.
     *  - **Destino libre y movimiento parcial** → `INSERT ... ON DUPLICATE KEY
     *    UPDATE`. Aquí no vale leer el hueco con `FOR UPDATE` e insertar
     *    detrás: sobre una combinación que todavía no existe ese `FOR UPDATE`
     *    toma un *gap lock*, y los gap locks de InnoDB son **compartidos** —dos
     *    sesiones lo obtienen a la vez y la perdedora muere con `1213
     *    Deadlock`—. El `ODKU` deja que arbitre el `UNIQUE KEY`: si otra sesión
     *    se adelantó, suma en vez de chocar.
     *
     * @param  array{condition?: Condition, isWishlist?: bool} $destino
     * @return array{item: array<string, mixed>|null, merged: bool, origen: array<string, mixed>|null}|null
     */
    public function moveLine(int $userId, int $itemId, array $destino, ?int $quantity = null): ?array
    {
        // El método ENTERO vive dentro de una transacción, y no por prudencia
        // genérica: mover una línea son DOS escrituras (poner allí, quitar
        // aquí). Si la segunda no llegara a ejecutarse, esos ejemplares estarían
        // contados dos veces; si fuera la primera la que falla, no estarían en
        // ninguna parte. Solo una de las dos situaciones es visible para el
        // usuario, y ninguna es aceptable en un inventario.
        $this->db->beginTransaction();

        try {
            // FOR UPDATE bloquea la fila de origen hasta el commit. Sin él, dos
            // movimientos simultáneos sobre la misma línea podrían leer los dos
            // la misma cantidad y perder uno de los dos. Esta fila existe
            // siempre, así que es un bloqueo de fila y serializa bien.
            $stmt = $this->db->prepare(
                'SELECT printing_uuid, finish, language, condition_grade, quantity, is_wishlist, notes
                   FROM mtg_collection_item
                  WHERE id = :id AND user_id = :user_id
                  FOR UPDATE'
            );

            $stmt->execute(['id' => $itemId, 'user_id' => $userId]);

            $origen = $stmt->fetch();

            if ($origen === false) {
                $this->db->commit();

                return null;
            }

            $cantidadOrigen = (int) $origen['quantity'];
            $aMover         = $quantity ?? $cantidadOrigen;

            // `quantity` es SMALLINT **UNSIGNED**: restar por debajo de cero no
            // deja un número negativo, revienta la sentencia a mitad de la
            // transacción. Se comprueba antes de escribir nada. El use case
            // vuelve a validarlo con el mensaje que lee el usuario; esto es la
            // última defensa del esquema, no la primera.
            if ($aMover <= 0 || $aMover > $cantidadOrigen) {
                throw new InvalidArgumentException(
                    "No se pueden mover {$aMover} ejemplares de una línea que tiene {$cantidadOrigen}."
                );
            }

            // Lo que no venga en $destino se hereda del origen: el que cumple un
            // deseo sin decir el estado se queda con el estado que deseaba.
            $condicionDestino = isset($destino['condition'])
                ? $destino['condition']->value
                : (string) $origen['condition_grade'];

            $wishlistOrigen  = (int) $origen['is_wishlist'];
            $wishlistDestino = isset($destino['isWishlist'])
                ? (int) $destino['isWishlist']
                : $wishlistOrigen;

            // Ya está donde se le pide: ni se mueve ni se funde nada. Se
            // responde la línea tal cual en vez de un error, porque pedir lo que
            // ya es cierto no es un fallo del cliente. Es el único caso en el que
            // `origen` e `item` son la misma fila.
            if ($condicionDestino === $origen['condition_grade'] && $wishlistDestino === $wishlistOrigen) {
                $this->db->commit();

                $linea = $this->findById($userId, $itemId);

                return ['item' => $linea, 'merged' => false, 'origen' => $linea];
            }

            $destinoId = $this->idDeLaCombinacion($userId, $origen, $condicionDestino, $wishlistDestino);

            if ($destinoId !== null) {
                // DESTINO OCUPADO: las dos filas son la misma carta en la misma
                // combinación, así que se suman allí y se descuenta aquí.
                $sumar = $this->db->prepare(
                    'UPDATE mtg_collection_item
                        SET quantity = quantity + :quantity
                      WHERE id = :id AND user_id = :user_id'
                );

                $sumar->execute([
                    'quantity' => $aMover,
                    'id'       => $destinoId,
                    'user_id'  => $userId,
                ]);

                $resultadoId = $destinoId;
                $fundida     = true;
                $origenVive  = $this->descontarOrigen($userId, $itemId, $cantidadOrigen - $aMover);
            } elseif ($aMover === $cantidadOrigen) {
                // DESTINO LIBRE Y MOVIMIENTO TOTAL: basta con reescribir las
                // columnas de la clave, y así la línea conserva su `id` —la
                // vista puede actualizarla en su sitio sin recargar—. No queda
                // nada en la combinación de partida, de ahí el `origen` a null.
                $mover = $this->db->prepare(
                    'UPDATE mtg_collection_item
                        SET condition_grade = :condition_grade,
                            is_wishlist     = :is_wishlist
                      WHERE id = :id AND user_id = :user_id'
                );

                $mover->execute([
                    'condition_grade' => $condicionDestino,
                    'is_wishlist'     => $wishlistDestino,
                    'id'              => $itemId,
                    'user_id'         => $userId,
                ]);

                $resultadoId = $itemId;
                $fundida     = false;
                $origenVive  = false;
            } else {
                // DESTINO LIBRE Y MOVIMIENTO PARCIAL: la línea se parte en dos.
                // Por `self::UPSERT` y no por un INSERT propio, que es la misma
                // razón de siempre —una segunda puerta a la tabla se
                // desincroniza— y además trae gratis lo que hace falta aquí: el
                // `ON DUPLICATE KEY UPDATE` que evita el deadlock del gap lock y
                // el `id = LAST_INSERT_ID(id)` sin el cual no se podría releer
                // la fila resultante. Las notas viajan con el trozo que se mueve:
                // partir una línea no es motivo para perder su comentario.
                $crear = $this->db->prepare(self::UPSERT);

                $crear->execute([
                    'user_id'         => $userId,
                    'printing_uuid'   => $origen['printing_uuid'],
                    'finish'          => $origen['finish'],
                    'language'        => $origen['language'],
                    'condition_grade' => $condicionDestino,
                    'quantity'        => $aMover,
                    'is_wishlist'     => $wishlistDestino,
                    'notes'           => $origen['notes'],
                ]);

                $resultadoId = (int) $this->db->lastInsertId();
                $fundida     = false;
                $origenVive  = $this->descontarOrigen($userId, $itemId, $cantidadOrigen - $aMover);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return [
            'item'   => $this->findById($userId, $resultadoId),
            'merged' => $fundida,
            'origen' => $origenVive ? $this->findById($userId, $itemId) : null,
        ];
    }

    /**
     * Deja en el origen lo que no se ha movido, y lo BORRA si no queda nada.
     *
     * Borrar y no dejar un cero es lo mismo que hace `changeQuantity(0)`, y por
     * lo mismo: una fila a `quantity = 0` seguiría contando como carta única en
     * los agregados del dashboard y en el valor de la lista de deseos.
     *
     * @return bool true si la fila de origen sobrevive al movimiento
     */
    private function descontarOrigen(int $userId, int $itemId, int $restante): bool
    {
        if ($restante <= 0) {
            $this->remove($userId, $itemId);

            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_collection_item
                SET quantity = :quantity
              WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([
            'quantity' => $restante,
            'id'       => $itemId,
            'user_id'  => $userId,
        ]);

        return true;
    }

    public function remove(int $userId, int $itemId): bool
    {
        // El user_id va en el WHERE del DELETE, no en una comprobación previa:
        // `id` es un autoincremental global y sin este filtro bastaría con
        // probar números para borrar la colección de otra persona.
        $stmt = $this->db->prepare('DELETE FROM mtg_collection_item WHERE id = :id AND user_id = :user_id');

        $stmt->execute(['id' => $itemId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function findById(int $userId, int $itemId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . self::ORIGEN . '
              WHERE ci.id = :id AND ci.user_id = :user_id
              LIMIT 1'
        );

        $stmt->execute(['id' => $itemId, 'user_id' => $userId]);

        $fila = $stmt->fetch();

        return $fila === false ? null : $this->aContrato($fila);
    }

    public function search(int $userId, CollectionCriteria $criterios): array
    {
        $where  = ['ci.user_id = :user_id', 'ci.is_wishlist = :is_wishlist'];
        $params = ['user_id' => $userId, 'is_wishlist' => $criterios->isWishlist ? 1 : 0];

        $this->clausulasDeFiltro($criterios, $where, $params);

        $posicion = Cursor::offsetDe(Cursor::decodificar($criterios->cursor));

        // Una fila de más: si llega, hay página siguiente. Ahorra el COUNT(*)
        // que costaría otra pasada solo para saber si el scroll debe seguir.
        $sql = 'SELECT ' . self::COLUMNAS . self::ORIGEN . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ' . $this->clausulaDeOrden($criterios) . '
                 LIMIT ' . ($criterios->limit + 1)
                 . ($posicion > 0 ? ' OFFSET ' . $posicion : '');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $filas  = $stmt->fetchAll();
        $hayMas = count($filas) > $criterios->limit;

        if ($hayMas) {
            array_pop($filas);
        }

        return [
            'items'      => array_map([$this, 'aContrato'], $filas),
            // La colección de una persona son miles de filas, no 110.384: aquí
            // el cursor por posición no tiene el coste que tendría en el
            // catálogo, y permite ordenar por precio —columna con NULL— sin las
            // acrobacias de la comparación por tuplas.
            'nextCursor' => $hayMas ? Cursor::porPosicion($posicion + count($filas)) : null,
        ];
    }

    public function wishedPrintingUuids(int $userId): array
    {
        // Una sola consulta y sobre UNA sola tabla: ni `self::ORIGEN` ni el
        // `JOIN` de precios. El catálogo pide esto al entrar para pintar 60
        // corazones, así que lo que no se traiga no cuesta nada.
        //
        // El `DISTINCT` es del contrato, no una precaución: el corazón habla de
        // la impresión, y la misma impresión puede estar deseada en varias
        // líneas (un foil y un no-foil, dos idiomas, dos estados).
        $stmt = $this->db->prepare(
            'SELECT DISTINCT printing_uuid
               FROM mtg_collection_item
              WHERE user_id = :user_id AND is_wishlist = 1'
        );

        $stmt->execute(['user_id' => $userId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function allLines(int $userId, bool $isWishlist): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . self::ORIGEN . '
              WHERE ci.user_id = :user_id AND ci.is_wishlist = :is_wishlist
              ORDER BY pc.price_eur IS NULL, pc.price_eur DESC, ci.id'
        );

        $stmt->execute(['user_id' => $userId, 'is_wishlist' => $isWishlist ? 1 : 0]);

        return array_map([$this, 'aContrato'], $stmt->fetchAll());
    }

    public function setProgress(int $userId, bool $isWishlist): array
    {
        // UNA consulta agregada, no una por edición: el catálogo tiene 868 sets
        // y un N+1 aquí serían 868 viajes a MySQL para pintar una pantalla.
        //
        // `COUNT(DISTINCT ci.printing_uuid)` es el numerador del plan, y el
        // DISTINCT no sobra: la misma impresión puede estar en varias líneas
        // (un foil y un no-foil, dos idiomas, dos estados) y seguiría siendo
        // UNA carta de las que pide la edición.
        //
        // `SUM(ci.quantity)` sí puede sumarse a pelo porque el LEFT JOIN de
        // precios es por la PK `(printing_uuid, finish)` de mtg_price_current:
        // aporta como mucho una fila por línea y no multiplica el agregado.
        //
        // `total_set_size` se devuelve TAL CUAL, NULL incluido: la división es
        // del use case, que es donde se puede probar sin base de datos qué se
        // enseña cuando la edición no declara tamaño.
        $stmt = $this->db->prepare(
            'SELECT p.set_code       AS setCode,
                    s.name           AS setName,
                    s.release_date   AS releaseDate,
                    s.total_set_size AS totalSetSize,
                    COUNT(DISTINCT ci.printing_uuid) AS ownedPrintings,
                    COUNT(*)                         AS items,
                    SUM(ci.quantity)                 AS copies,
                    SUM(ci.quantity * COALESCE(pc.price_eur, 0)) AS valueEur
               ' . self::ORIGEN . '
              WHERE ci.user_id = :user_id AND ci.is_wishlist = :is_wishlist
              GROUP BY p.set_code, s.name, s.release_date, s.total_set_size'
        );

        $stmt->execute(['user_id' => $userId, 'is_wishlist' => $isWishlist ? 1 : 0]);

        $sets = array_map(
            static fn (array $f): array => [
                'setCode'        => $f['setCode'],
                'setName'        => $f['setName'],
                'releaseDate'    => $f['releaseDate'],
                // NULL se conserva: "no se sabe cuántas cartas tiene esta
                // edición" no es lo mismo que "tiene cero".
                'totalSetSize'   => $f['totalSetSize'] !== null ? (int) $f['totalSetSize'] : null,
                'ownedPrintings' => (int) $f['ownedPrintings'],
                'items'          => (int) $f['items'],
                'copies'         => (int) $f['copies'],
                'valueEur'       => round((float) $f['valueEur'], 2),
            ],
            $stmt->fetchAll()
        );

        return [
            'sets' => $sets,
            // Un escalar sobre 868 filas. Es lo que convierte "23 ediciones" en
            // "23 de 868", que es el dato que de verdad dice algo.
            'catalogSets' => (int) $this->db->query('SELECT COUNT(*) FROM mtg_set')->fetchColumn(),
        ];
    }

    /**
     * El `id` de la fila que ocuparía el `UNIQUE KEY` tras el movimiento, o
     * null si esa combinación está libre.
     *
     * Se consulta con `FOR UPDATE` dentro de la misma transacción que el
     * movimiento, y eso sirve para lo que de verdad hace falta: cuando la fila
     * de destino **existe**, es un bloqueo de fila y las dos sesiones se
     * serializan —medido contra la BD de dev: la segunda esperó cuatro segundos
     * al `COMMIT` de la primera y siguió—.
     *
     * Lo que **no** hace, aunque este comentario lo afirmara hasta el
     * 2026-09-12: guardar el hueco. Sobre una combinación que todavía no existe,
     * `FOR UPDATE` toma un *gap lock*, y los gap locks de InnoDB son
     * **compartidos**: dos sesiones lo obtienen a la vez y chocan al insertar
     * con `1213 Deadlock`. Por eso el movimiento parcial no inserta detrás de
     * esta lectura —va por `INSERT ... ON DUPLICATE KEY UPDATE`— y por eso el
     * movimiento total a un destino libre sigue siendo un `UPDATE` en el sitio,
     * con el mismo riesgo de deadlock que ya tenía y ni uno más.
     *
     * @param array<string, mixed> $origen            La fila que se mueve
     * @param string               $condicionDestino  `condition_grade` de destino
     * @param int                  $wishlistDestino   `is_wishlist` de destino (0 o 1)
     */
    private function idDeLaCombinacion(
        int $userId,
        array $origen,
        string $condicionDestino,
        int $wishlistDestino
    ): ?int {
        $stmt = $this->db->prepare(
            'SELECT id
               FROM mtg_collection_item
              WHERE user_id         = :user_id
                AND printing_uuid   = :printing_uuid
                AND finish          = :finish
                AND language        = :language
                AND condition_grade = :condition_grade
                AND is_wishlist     = :is_wishlist
              FOR UPDATE'
        );

        $stmt->execute([
            'user_id'         => $userId,
            'printing_uuid'   => $origen['printing_uuid'],
            'finish'          => $origen['finish'],
            'language'        => $origen['language'],
            'condition_grade' => $condicionDestino,
            'is_wishlist'     => $wishlistDestino,
        ]);

        $fila = $stmt->fetch();

        return $fila === false ? null : (int) $fila['id'];
    }

    /**
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    private function clausulasDeFiltro(CollectionCriteria $criterios, array &$where, array &$params): void
    {
        if ($criterios->setCode !== null) {
            $where[]            = 'p.set_code = :set_code';
            $params['set_code'] = $criterios->setCode;
        }

        if ($criterios->rarity !== null) {
            $where[]          = 'p.rarity = :rarity';
            $params['rarity'] = $criterios->rarity;
        }

        if ($criterios->finish !== null) {
            $where[]          = 'ci.finish = :finish';
            $params['finish'] = $criterios->finish->value;
        }

        if ($criterios->language !== null) {
            $where[]            = 'ci.language = :language';
            $params['language'] = $criterios->language->value;
        }

        if ($criterios->condition !== null) {
            $where[]                   = 'ci.condition_grade = :condition_grade';
            $params['condition_grade'] = $criterios->condition->value;
        }

        // Identidad de color: la carta debe incluir TODOS los colores pedidos.
        // Letra a letra para no depender del orden en que MTGJSON las escriba.
        foreach ($criterios->coloresComoLetras() as $i => $color) {
            $where[]             = "c.color_identity LIKE :color{$i}";
            $params["color{$i}"] = '%' . $color . '%';
        }

        // El filtro de precio es sobre el precio unitario del acabado concreto,
        // que es lo que se ve en la ficha. Una carta sin precio queda fuera del
        // rango: no es que valga 0, es que no se sabe.
        if ($criterios->priceMin !== null) {
            $where[]             = 'pc.price_eur >= :price_min';
            $params['price_min'] = $criterios->priceMin;
        }

        if ($criterios->priceMax !== null) {
            $where[]             = 'pc.price_eur <= :price_max';
            $params['price_max'] = $criterios->priceMax;
        }
    }

    /**
     * El `ORDER BY`. Los valores salen de la lista blanca de
     * `CollectionCriteria`, nunca del cliente: PDO no admite marcador aquí.
     */
    private function clausulaDeOrden(CollectionCriteria $criterios): string
    {
        // Los NULL de precio van SIEMPRE al final, se ordene como se ordene: "no
        // sé cuánto vale" no es ni lo más barato ni lo más caro.
        $orden = match ($criterios->sort) {
            'name'       => 'c.name ASC',
            'release'    => "COALESCE(s.release_date, '0001-01-01') DESC",
            'rarity'     => "FIELD(p.rarity, 'mythic', 'rare', 'uncommon', 'common', 'special', 'bonus')",
            'price_asc'  => 'pc.price_eur IS NULL, pc.price_eur ASC',
            'quantity'   => 'ci.quantity DESC',
            'added'      => 'ci.created_at DESC',
            default      => 'pc.price_eur IS NULL, pc.price_eur DESC',
        };

        // `ci.id` como desempate: sin un orden TOTAL, dos filas empatadas pueden
        // salir en distinto orden entre llamadas y la paginación repetiría o se
        // saltaría cartas.
        return $orden . ', ci.id ASC';
    }

    /**
     * Tipos de verdad: PDO devuelve todo como string y el cliente necesita
     * distinguir un precio ausente (`null`, "sin precio") de un cero.
     *
     * @param  array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function aContrato(array $f): array
    {
        return [
            'id'              => (int) $f['id'],
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
            'quantity'        => (int) $f['quantity'],
            'isWishlist'      => (bool) $f['isWishlist'],
            'notes'           => $f['notes'],
            'createdAt'       => $f['createdAt'],
            'updatedAt'       => $f['updatedAt'],
            // NULL se conserva a propósito: es lo que permite a la ficha decir
            // "sin precio" en vez de "0 €".
            'priceEur'        => $f['priceEur'] !== null ? (float) $f['priceEur'] : null,
            'lineValue'       => (float) $f['lineValue'],
        ];
    }
}
