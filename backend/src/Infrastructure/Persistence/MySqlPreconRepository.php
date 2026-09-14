<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Cursor;
use App\Domain\Catalog\PreconPlayability;
use App\Domain\Catalog\PreconSearchCriteria;
use App\Domain\Repository\PreconRepositoryInterface;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;

/**
 * Los precons, con `INSERT … ON DUPLICATE KEY UPDATE` multi-fila.
 *
 * Mismo mecanismo que `MySqlCatalogRepository`: cada lote es UNA sentencia con N
 * tuplas de `VALUES`, porque 112.577 filas de carta con una sentencia por fila
 * son otros tantos viajes a MySQL.
 *
 * El `ON DUPLICATE KEY UPDATE` no es rendimiento, es **el mecanismo de
 * idempotencia**: relanzar `decks:import` vuelve a mandar las mismas 112.577
 * filas y ninguna se duplica, porque la PK de cuatro columnas
 * `(precon_file, printing_uuid, board, finish)` las hace casar.
 */
class MySqlPreconRepository implements PreconRepositoryInterface
{
    /**
     * Filas por sentencia.
     *
     * El techo real son los 65.535 marcadores de un prepared statement de MySQL.
     * La tabla más ancha de aquí (`mtg_precon`, 6 columnas) da 6.000 marcadores
     * con 1.000 filas: holgado.
     */
    private const TAMANO_LOTE = 1000;

    /** Los `card_count` van de tres marcadores por fila, así que el lote baja. */
    private const TAMANO_LOTE_CONTEO = 500;

    /**
     * La cabecera de un precon, común a la lista y a la ficha.
     *
     * El nombre de la edición entra por `LEFT JOIN`, no por `JOIN`: `mtg_precon`
     * no tiene FK a `mtg_set` —se ingieren por separado, igual que las cartas—
     * y un precon de una edición que el catálogo aún no conoce tiene que salir
     * en la lista con `setName` a null, no desaparecer de ella.
     */
    private const COLUMNAS_PRECON = '
        pr.file_name    AS fileName,
        pr.name,
        pr.deck_type    AS deckType,
        pr.set_code     AS setCode,
        s.name          AS setName,
        pr.release_date AS releaseDate,
        pr.card_count   AS cardCount
    ';

    private const ORIGEN_PRECON = '
          FROM mtg_precon pr
     LEFT JOIN mtg_set s ON s.code = pr.set_code
    ';

    /**
     * Una línea de precon: la carta, su edición y su precio vigente.
     *
     * `known` es la marca de las huérfanas —las 254 filas cuyo `printing_uuid`
     * todavía no está en `mtg_printing`—: la línea sale igual, con el uuid y la
     * cantidad que MTGJSON publicó, y la vista puede decir que falta importar el
     * catálogo en vez de enseñar una fila vacía sin explicación.
     */
    private const COLUMNAS_CARTA = '
        pc.printing_uuid    AS printingUuid,
        pc.board,
        pc.finish,
        pc.`count`,
        (p.uuid IS NOT NULL) AS known,
        c.name,
        p.oracle_id         AS oracleId,
        p.set_code          AS setCode,
        s.name              AS setName,
        p.collector_number  AS collectorNumber,
        p.rarity,
        c.mana_cost         AS manaCost,
        c.colors,
        c.color_identity    AS colorIdentity,
        c.type_line         AS typeLine,
        p.scryfall_id       AS scryfallId,
        cur.price_eur       AS priceEur
    ';

    /**
     * Cuatro `LEFT JOIN` y ni un `JOIN`.
     *
     * El primero es el que importa: `mtg_precon_card` **no tiene FK a
     * `mtg_printing`** a propósito (ver `PreconRepositoryInterface`), así que hay
     * filas cuyo uuid no está en el catálogo. Con un `JOIN` a secas esas cartas
     * se caerían de la lista sin que nadie se enterase, y los tres de abajo
     * cuelgan de él: si el printing no está, tampoco están su carta ni su set.
     *
     * El precio va por `(printing_uuid, finish)`, como en la colección y en los
     * mazos: unir solo por printing valoraría el foil a precio de no-foil.
     */
    private const ORIGEN_CARTA = '
          FROM mtg_precon_card pc
     LEFT JOIN mtg_printing p   ON p.uuid      = pc.printing_uuid
     LEFT JOIN mtg_card     c   ON c.oracle_id = p.oracle_id
     LEFT JOIN mtg_set      s   ON s.code      = p.set_code
     LEFT JOIN mtg_price_current cur
            ON cur.printing_uuid = pc.printing_uuid
           AND cur.finish        = pc.finish
    ';

    public function __construct(
        private readonly PDO $db,
        private readonly BooleanExpressionBuilder $expresion
    ) {
    }

    public function upsertPrecons(array $filas): int
    {
        return $this->upsert('mtg_precon', $filas, ['file_name']);
    }

    public function upsertCartas(array $filas): int
    {
        return $this->upsert('mtg_precon_card', $filas, ['precon_file', 'printing_uuid', 'board', 'finish']);
    }

    public function actualizarCardCount(array $conteos): int
    {
        if ($conteos === []) {
            return 0;
        }

        $enviadas = 0;

        // Un UPDATE con CASE por lote y no un upsert: la fila ya existe (la puso
        // la fase 1) y un INSERT … ON DUPLICATE KEY tendría que repetir aquí
        // set_code, name y deck_type, que son NOT NULL, sólo para tocar una
        // columna. El ELSE deja intacto lo que no venga en el lote.
        foreach (array_chunk($conteos, self::TAMANO_LOTE_CONTEO, true) as $lote) {
            $casos     = [];
            $valores   = [];
            $ficheros  = [];

            foreach ($lote as $fichero => $ejemplares) {
                $casos[]   = 'WHEN ? THEN ?';
                $valores[] = (string) $fichero;
                $valores[] = (int) $ejemplares;
                $ficheros[] = (string) $fichero;
            }

            // Con ATTR_EMULATE_PREPARES = false no se puede reutilizar un
            // marcador en dos puntos de la misma sentencia, así que los
            // file_name del CASE se repiten en el IN con marcadores propios.
            $marcadores = implode(', ', array_fill(0, count($ficheros), '?'));

            $sql = 'UPDATE mtg_precon SET card_count = CASE file_name '
                . implode(' ', $casos)
                . ' ELSE card_count END WHERE file_name IN (' . $marcadores . ')';

            $this->db->prepare($sql)->execute(array_merge($valores, $ficheros));
            $enviadas += count($lote);
        }

        return $enviadas;
    }

    public function contadores(): array
    {
        $out = [];

        foreach (['mtg_precon', 'mtg_precon_card'] as $tabla) {
            $out[$tabla] = (int) $this->db->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
        }

        return $out;
    }

    public function buscar(PreconSearchCriteria $criterios): array
    {
        $where  = [];
        $params = [];

        $this->clausulasDeFiltro($criterios, $where, $params);
        $this->clausulaDeCursor($criterios, $where, $params);

        // Una fila de más: si llega, hay página siguiente. Evita el COUNT(*) que
        // costaría otra pasada sobre el mismo conjunto solo para saber si el
        // scroll infinito debe seguir. Mismo truco que la búsqueda de cartas.
        $sql = 'SELECT ' . self::COLUMNAS_PRECON . self::ORIGEN_PRECON
             . ' WHERE ' . ($where === [] ? '1' : implode(' AND ', $where))
             . ' ORDER BY pr.name ASC, pr.file_name ASC'
             . ' LIMIT ' . ($criterios->limit + 1);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $filas  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($filas) > $criterios->limit;

        if ($hayMas) {
            array_pop($filas);
        }

        $items = array_map([$this, 'aContrato'], $filas);

        return [
            'items'      => $items,
            'nextCursor' => $hayMas && $items !== []
                ? Cursor::porColumna(
                    (string) $items[count($items) - 1]['name'],
                    (string) $items[count($items) - 1]['fileName']
                )
                : null,
        ];
    }

    public function facetas(): array
    {
        // Dos agregados sobre 3.029 filas: milisegundos, y la respuesta que los
        // lleva se cachea 5 minutos como el resto del catálogo. Se agrupa por
        // `deck_type` —que tiene su propio índice— y por `set_code`, y de ahí
        // salen los 48 tipos y las 295 ediciones sin que nadie los copie.
        $tipos = $this->db->query(
            'SELECT deck_type AS type, COUNT(*) AS total
               FROM mtg_precon
              GROUP BY deck_type
              ORDER BY total DESC, deck_type ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // `LEFT JOIN` con `mtg_set` por lo mismo que en la lista: un precon de
        // una edición que el catálogo todavía no tiene sale con el nombre a
        // null, pero sale — con un JOIN a secas desaparecería del filtro.
        $sets = $this->db->query(
            'SELECT pr.set_code AS code, s.name AS name, COUNT(*) AS total
               FROM mtg_precon pr
          LEFT JOIN mtg_set s ON s.code = pr.set_code
              GROUP BY pr.set_code, s.name
              ORDER BY s.name IS NULL, s.name ASC, pr.set_code ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'types' => array_map(
                // `playable` viaja con el tipo para que la vista no tenga que
                // repetir la clasificación: separa el desplegable en «mazos» y
                // «productos» leyéndolo, no copiando las cinco cadenas.
                static fn (array $f): array => [
                    'type'     => (string) $f['type'],
                    'count'    => (int) $f['total'],
                    'playable' => PreconPlayability::esJugable((string) $f['type']),
                ],
                $tipos
            ),
            'sets' => array_map(
                static fn (array $f): array => [
                    'code'  => (string) $f['code'],
                    'name'  => $f['name'],
                    'count' => (int) $f['total'],
                ],
                $sets
            ),
        ];
    }

    public function find(string $fileName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_PRECON . ', pr.source_url AS sourceUrl'
            . self::ORIGEN_PRECON
            . ' WHERE pr.file_name = :file_name LIMIT 1'
        );
        $stmt->execute(['file_name' => $fileName]);

        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        $precon = $this->aContrato($fila);
        $precon['sourceUrl'] = $fila['sourceUrl'];

        return $precon;
    }

    public function cartas(string $fileName): array
    {
        // El orden de las zonas es el de la caja —el comandante primero—, y no el
        // alfabético del ENUM. Dentro de cada zona, por nombre; las huérfanas no
        // tienen nombre y van al final del suyo en vez de encabezar la lista con
        // un hueco, con el uuid de desempate para que el orden sea total.
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_CARTA . self::ORIGEN_CARTA
            . ' WHERE pc.precon_file = :file_name'
            . " ORDER BY FIELD(pc.board, 'commander', 'main', 'side', 'planes', 'schemes', 'tokens'),"
            . ' c.name IS NULL, c.name ASC, pc.printing_uuid ASC'
        );
        $stmt->execute(['file_name' => $fileName]);

        return array_map(
            static fn (array $fila): array => [
                'printingUuid'    => (string) $fila['printingUuid'],
                'board'           => (string) $fila['board'],
                'finish'          => (string) $fila['finish'],
                'count'           => (int) $fila['count'],
                'known'           => (bool) $fila['known'],
                'name'            => $fila['name'],
                'oracleId'        => $fila['oracleId'],
                'setCode'         => $fila['setCode'],
                'setName'         => $fila['setName'],
                'collectorNumber' => $fila['collectorNumber'],
                'rarity'          => $fila['rarity'],
                'manaCost'        => $fila['manaCost'],
                'colors'          => $fila['colors'],
                'colorIdentity'   => $fila['colorIdentity'],
                'typeLine'        => $fila['typeLine'],
                'scryfallId'      => $fila['scryfallId'],
                // NULL y no 0: no saber cuánto vale una carta es distinto de que
                // no valga nada. Quien suma ya lo trata como cero.
                'priceEur'        => $fila['priceEur'] !== null ? (float) $fila['priceEur'] : null,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    private function clausulasDeFiltro(PreconSearchCriteria $criterios, array &$where, array &$params): void
    {
        if ($criterios->deckType !== null) {
            $where[]            = 'pr.deck_type = :deck_type';
            $params['deck_type'] = $criterios->deckType;
        }

        if ($criterios->setCode !== null) {
            $where[]           = 'pr.set_code = :set_code';
            $params['set_code'] = $criterios->setCode;
        }

        // Los tipos que no son un mazo. Es un `NOT IN` de cinco cadenas sobre la
        // columna indexada `deck_type`, no una lista blanca de los otros 43: la
        // lista blanca habría que ampliarla cada vez que MTGJSON inventara un
        // tipo, y hasta entonces esas cajas serían invisibles.
        if ($criterios->soloJugables) {
            $marcadores = [];

            foreach (PreconPlayability::NO_JUGABLES as $i => $tipo) {
                $marcadores[]            = ':no_jugable_' . $i;
                $params['no_jugable_' . $i] = $tipo;
            }

            $where[] = 'pr.deck_type NOT IN (' . implode(', ', $marcadores) . ')';
        }

        if (!$criterios->tieneTexto()) {
            return;
        }

        $termino = (string) $criterios->q;

        // El japonés y el chino no van por `ft_name`: ese índice usa el parser
        // por defecto, que tokeniza por espacios, y esos idiomas no los usan —el
        // catálogo lo resuelve con un índice ngram aparte que aquí no existe—.
        // Un LIKE sobre 3.029 filas cuesta nada y es la diferencia entre
        // encontrar los 11 precons de nombre no ASCII y devolver cero en
        // silencio.
        if ($this->expresion->esCjk($termino)) {
            $where[]      = 'pr.name LIKE :q_like';
            $params['q_like'] = '%' . $termino . '%';

            return;
        }

        // La expresión sale vacía solo si lo tecleado era pura puntuación: ahí no
        // se filtra, en vez de devolver cero resultados por un `MATCH` sin
        // términos.
        $expresion = $this->expresion->construir($termino);

        if ($expresion === '') {
            return;
        }

        $where[]     = 'MATCH(pr.name) AGAINST(:q IN BOOLEAN MODE)';
        $params['q'] = $expresion;
    }

    /**
     * El cursor, traducido a una comparación de tuplas. **Nunca a un OFFSET.**
     *
     * `(name, file_name) > (visto, visto)` sobre el orden total del `ORDER BY`:
     * es lo que hace que la página siguiente empiece justo después de la última
     * fila entregada sin que MySQL tenga que recorrer y tirar las anteriores.
     *
     * Los dos marcadores son dos nombres distintos aunque el valor salga del
     * mismo cursor: con `ATTR_EMULATE_PREPARES = false` MySQL no admite
     * reutilizar un marcador nombrado en dos puntos de la misma sentencia.
     *
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    private function clausulaDeCursor(PreconSearchCriteria $criterios, array &$where, array &$params): void
    {
        $datos = Cursor::decodificar($criterios->cursor);

        // Un cursor manipulado o caducado empieza por el principio en vez de
        // romper la vista. Mismo criterio que el catálogo.
        if ($datos === null || !isset($datos['v'], $datos['u'])) {
            return;
        }

        $where[]            = '(pr.name, pr.file_name) > (:cursor_v, :cursor_u)';
        $params['cursor_v'] = (string) $datos['v'];
        $params['cursor_u'] = (string) $datos['u'];
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aContrato(array $fila): array
    {
        return [
            'fileName'    => (string) $fila['fileName'],
            'name'        => (string) $fila['name'],
            'deckType'    => (string) $fila['deckType'],
            'setCode'     => (string) $fila['setCode'],
            'setName'     => $fila['setName'],
            'releaseDate' => $fila['releaseDate'],
            'cardCount'   => (int) $fila['cardCount'],
        ];
    }

    public function huerfanos(): array
    {
        $sql = 'SELECT COUNT(*) AS filas, COUNT(DISTINCT pc.printing_uuid) AS uuids
                  FROM mtg_precon_card pc
                  LEFT JOIN mtg_printing p ON p.uuid = pc.printing_uuid
                 WHERE p.uuid IS NULL';

        $fila = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'filas' => (int) ($fila['filas'] ?? 0),
            'uuids' => (int) ($fila['uuids'] ?? 0),
        ];
    }

    /**
     * Upsert multi-fila, troceado en lotes.
     *
     * @param  list<array<string, mixed>> $filas
     * @param  list<string>               $clave Columnas de la clave; se excluyen del UPDATE
     * @return int Filas enviadas
     */
    private function upsert(string $tabla, array $filas, array $clave): int
    {
        if ($filas === []) {
            return 0;
        }

        $columnas = array_keys($filas[0]);
        $enviadas = 0;

        foreach (array_chunk($filas, self::TAMANO_LOTE) as $lote) {
            $this->ejecutarLote($tabla, $columnas, $clave, $lote);
            $enviadas += count($lote);
        }

        return $enviadas;
    }

    /**
     * @param list<string>               $columnas
     * @param list<string>               $clave
     * @param list<array<string, mixed>> $lote
     */
    private function ejecutarLote(string $tabla, array $columnas, array $clave, array $lote): void
    {
        $tupla  = '(' . implode(', ', array_fill(0, count($columnas), '?')) . ')';
        $tuplas = implode(', ', array_fill(0, count($lote), $tupla));

        $asignaciones = [];
        foreach ($columnas as $columna) {
            if (!in_array($columna, $clave, true)) {
                $asignaciones[] = "`{$columna}` = VALUES(`{$columna}`)";
            }
        }

        $sql = "INSERT INTO `{$tabla}` (`" . implode('`, `', $columnas) . "`) VALUES {$tuplas}";

        // `mtg_precon_card` tiene TODAS sus columnas en la clave menos `count`;
        // si algún día no quedara ninguna que actualizar, el upsert se degrada a
        // un no-op explícito en vez de a SQL inválido.
        $sql .= $asignaciones !== []
            ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $asignaciones)
            : " ON DUPLICATE KEY UPDATE `{$columnas[0]}` = `{$columnas[0]}`";

        $valores = [];
        foreach ($lote as $fila) {
            foreach ($columnas as $columna) {
                $valores[] = $fila[$columna] ?? null;
            }
        }

        $this->db->prepare($sql)->execute($valores);
    }
}
