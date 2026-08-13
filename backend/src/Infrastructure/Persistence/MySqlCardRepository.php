<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Cursor;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Repository\CardRepositoryInterface;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;

/**
 * Búsqueda en el catálogo local, en cualquiera de los diez idiomas.
 *
 * La consulta la validó M0 sobre el catálogo real: 29 de 30 búsquedas de control
 * aciertan en el top-3 y la p95 es de 7,1 ms. Tres detalles la sostienen, y
 * ninguno es evidente:
 *
 *  - **El orden no puede ser el score de MySQL a secas.** Delante van la
 *    coincidencia exacta del nombre completo y después `edhrec_rank`. Sin eso,
 *    teclear 'relámpago' devolvía primero cartas que solo contienen la palabra.
 *  - **La coincidencia exacta sale gratis en `utf8mb4_unicode_ci`**: la colación
 *    ignora mayúsculas y acentos, así que 'aves del paraiso' casa con 'Aves del
 *    paraíso' sin columna normalizada ni `REPLACE` encadenados.
 *  - **Japonés, chino y coreano van por otro índice** (`name_cjk`, parser ngram),
 *    porque el parser por defecto tokeniza por espacios y esos idiomas no los
 *    usan.
 */
class MySqlCardRepository implements CardRepositoryInterface
{
    /** Columnas del contrato CatalogCard, comunes a la búsqueda y a la ficha. */
    private const COLUMNAS = "
        p.uuid,
        p.oracle_id           AS oracleId,
        c.name,
        p.set_code            AS setCode,
        s.name                AS setName,
        p.collector_number    AS collectorNumber,
        p.rarity,
        c.mana_cost           AS manaCost,
        c.mana_value          AS manaValue,
        c.colors,
        c.color_identity      AS colorIdentity,
        c.type_line           AS typeLine,
        p.scryfall_id         AS scryfallId,
        p.has_foil            AS hasFoil,
        p.has_nonfoil         AS hasNonfoil,
        p.has_etched          AS hasEtched,
        pn.price_eur          AS priceNormal,
        pf.price_eur          AS priceFoil,
        pe.price_eur          AS priceEtched
    ";

    /**
     * Los precios entran por LEFT JOIN, uno por acabado.
     *
     * Un printing sin cotizar en Cardmarket es lo normal, no la excepción, y su
     * precio tiene que ser NULL y no 0: un 0 lo colocaría el primero al ordenar
     * por precio ascendente y diría que la carta no vale nada, que es distinto de
     * no saber cuánto vale.
     */
    private const JOINS_PRECIO = "
        LEFT JOIN mtg_price_current pn ON pn.printing_uuid = p.uuid AND pn.finish = 'normal'
        LEFT JOIN mtg_price_current pf ON pf.printing_uuid = p.uuid AND pf.finish = 'foil'
        LEFT JOIN mtg_price_current pe ON pe.printing_uuid = p.uuid AND pe.finish = 'etched'
    ";

    public function __construct(
        private readonly PDO $db,
        private readonly BooleanExpressionBuilder $expresion
    ) {
    }

    public function search(SearchCriteria $criterios): array
    {
        $where  = [];
        $params = [];

        [$cte, $joinTexto, $ordenRelevancia] = $this->clausulaDeTexto($criterios, $params);
        $this->clausulasDeFiltro($criterios, $where, $params);

        $posicion = $this->clausulaDeCursor($criterios, $where, $params);

        // Se pide una fila de más: si llega, hay página siguiente. Evita el
        // COUNT(*) que costaría otra pasada sobre el mismo conjunto solo para
        // saber si el scroll infinito debe seguir.
        $sql = $cte . '
                SELECT ' . self::COLUMNAS . ', ' . $this->columnaDeCursor($criterios) . ' AS cursorValue
                  FROM mtg_printing p
                  JOIN mtg_card c ON c.oracle_id = p.oracle_id
                  JOIN mtg_set  s ON s.code      = p.set_code
                  ' . $joinTexto . '
                  ' . self::JOINS_PRECIO . '
                 WHERE ' . ($where === [] ? '1' : implode(' AND ', $where)) . '
                 ORDER BY ' . $this->clausulaDeOrden($criterios, $ordenRelevancia) . '
                 LIMIT ' . ($criterios->limit + 1)
                 . ($posicion > 0 ? ' OFFSET ' . $posicion : '');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $filas = $stmt->fetchAll();
        $hayMas = count($filas) > $criterios->limit;

        if ($hayMas) {
            array_pop($filas);
        }

        return [
            'items'      => array_map([$this, 'aContrato'], $filas),
            'nextCursor' => $hayMas ? $this->cursorSiguiente($criterios, $filas, $posicion) : null,
        ];
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', c.oracle_text AS oracleText, c.layout
               FROM mtg_printing p
               JOIN mtg_card c ON c.oracle_id = p.oracle_id
               JOIN mtg_set  s ON s.code      = p.set_code
               ' . self::JOINS_PRECIO . '
              WHERE p.uuid = :uuid
              LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);

        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        $carta = $this->aContrato($fila);

        $carta['oracleText']     = $fila['oracleText'];
        $carta['layout']         = $fila['layout'];
        $carta['legalities']     = $this->legalidadesDe((string) $fila['oracleId']);
        $carta['localizedNames'] = $this->nombresLocalizadosDe($uuid);
        $carta['priceHistory']   = $this->historicoDe($uuid);

        return $carta;
    }

    public function allSets(): array
    {
        $filas = $this->db->query(
            'SELECT code, name, release_date AS releaseDate, set_type AS setType,
                    total_set_size AS totalSetSize
               FROM mtg_set
              ORDER BY release_date DESC, name'
        )->fetchAll();

        return array_map(static fn (array $f) => [
            'code'         => $f['code'],
            'name'         => $f['name'],
            'releaseDate'  => $f['releaseDate'],
            'setType'      => $f['setType'],
            'totalSetSize' => $f['totalSetSize'] !== null ? (int) $f['totalSetSize'] : null,
        ], $filas);
    }

    /**
     * Resuelve el texto en un CTE y lo une por `oracle_id`.
     *
     * **El CTE no es cosmético: es la diferencia entre 1 ms y 125 segundos.** La
     * versión obvia —un `EXISTS (SELECT … MATCH …)` correlacionado en el WHERE—
     * obliga a MySQL a evaluar el FULLTEXT una vez por cada uno de los 110.384
     * printings, y medido sobre el catálogo real 'bosque' tardaba 125 s. Aquí
     * cada MATCH se resuelve UNA vez sobre su propia tabla y lo que queda es un
     * JOIN por clave.
     *
     * La coincidencia se agrupa por `oracle_id`, no por printing: quien busca
     * 'bosque' quiere todas las ediciones de Forest, no solo aquellas cuyo
     * nombre localizado casó.
     *
     * @param  array<string, mixed> $params
     * @return array{0: string, 1: string, 2: string|null} CTE, JOIN y orden por relevancia
     */
    private function clausulaDeTexto(SearchCriteria $criterios, array &$params): array
    {
        if (!$criterios->tieneTexto()) {
            return ['', '', null];
        }

        $termino = (string) $criterios->q;

        if ($this->expresion->esCjk($termino)) {
            // El parser ngram no entiende los operadores +/*: texto tal cual.
            $params['cjka']    = $termino;
            $params['cjkb']    = $termino;
            $params['exactoc'] = $termino;

            $cte = 'WITH coincidencias AS (
                        SELECT p2.oracle_id, MAX(MATCH(l.name_cjk) AGAINST(:cjka IN BOOLEAN MODE)) AS score
                          FROM mtg_printing_localized l
                          JOIN mtg_printing p2 ON p2.uuid = l.printing_uuid
                         WHERE MATCH(l.name_cjk) AGAINST(:cjkb IN BOOLEAN MODE)
                         GROUP BY p2.oracle_id
                    )';

            return [
                $cte,
                'JOIN coincidencias m ON m.oracle_id = p.oracle_id',
                '(c.name = :exactoc) DESC, m.score DESC, c.edhrec_rank IS NULL, c.edhrec_rank',
            ];
        }

        $expresion = $this->expresion->construir($termino);

        if ($expresion === '') {
            return ['', '', null];
        }

        // Un marcador por aparición: con ATTR_EMULATE_PREPARES a false, MySQL NO
        // admite reutilizar el mismo nombre en dos sitios de la sentencia.
        $params['ft1a']    = $expresion;
        $params['ft1b']    = $expresion;
        $params['ft2a']    = $expresion;
        $params['ft2b']    = $expresion;
        $params['exacto1'] = $termino;
        $params['exacto2'] = $termino;

        // Dos índices FULLTEXT distintos —el nombre inglés y los localizados—,
        // cada uno resuelto por separado y fundidos por oracle_id. `exacto` se
        // calcula aquí dentro para que la coincidencia exacta en CUALQUIER idioma
        // cuente, no solo en inglés.
        $cte = 'WITH coincidencias AS (
                    SELECT oracle_id, MAX(score) AS score, MAX(exacto) AS exacto FROM (
                        SELECT c2.oracle_id,
                               MATCH(c2.name) AGAINST(:ft1a IN BOOLEAN MODE) AS score,
                               (c2.name = :exacto1) AS exacto
                          FROM mtg_card c2
                         WHERE MATCH(c2.name) AGAINST(:ft1b IN BOOLEAN MODE)
                        UNION ALL
                        SELECT p2.oracle_id,
                               MATCH(l.name) AGAINST(:ft2a IN BOOLEAN MODE) AS score,
                               (l.name = :exacto2) AS exacto
                          FROM mtg_printing_localized l
                          JOIN mtg_printing p2 ON p2.uuid = l.printing_uuid
                         WHERE MATCH(l.name) AGAINST(:ft2b IN BOOLEAN MODE)
                    ) u GROUP BY oracle_id
                )';

        return [
            $cte,
            'JOIN coincidencias m ON m.oracle_id = p.oracle_id',
            'm.exacto DESC, m.score DESC, c.edhrec_rank IS NULL, c.edhrec_rank',
        ];
    }

    /**
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    private function clausulasDeFiltro(SearchCriteria $criterios, array &$where, array &$params): void
    {
        if ($criterios->setCode !== null) {
            $where[]           = 'p.set_code = :set_code';
            $params['set_code'] = $criterios->setCode;
        }

        if ($criterios->rarity !== null) {
            // Validada contra la lista blanca en SearchCriteria.
            $where[]         = 'p.rarity = :rarity';
            $params['rarity'] = $criterios->rarity;
        }

        // Identidad de color: la carta debe incluir TODOS los colores pedidos.
        // Se compara letra a letra para no depender del orden en que MTGJSON las
        // escriba.
        foreach ($criterios->coloresComoLetras() as $i => $color) {
            $where[]                 = "c.color_identity LIKE :color{$i}";
            $params["color{$i}"]     = '%' . $color . '%';
        }

        if ($criterios->priceMin !== null) {
            $where[]            = 'COALESCE(pn.price_eur, pf.price_eur, pe.price_eur) >= :price_min';
            $params['price_min'] = $criterios->priceMin;
        }

        if ($criterios->priceMax !== null) {
            $where[]            = 'COALESCE(pn.price_eur, pf.price_eur, pe.price_eur) <= :price_max';
            $params['price_max'] = $criterios->priceMax;
        }
    }

    /**
     * El ORDER BY. Los valores vienen de listas blancas de SearchCriteria, nunca
     * del cliente: `ORDER BY` no admite marcador de posición en PDO.
     */
    private function clausulaDeOrden(SearchCriteria $criterios, ?string $relevancia): string
    {
        // Los NULL de precio van SIEMPRE al final, se ordene ascendente o
        // descendente: "no sé cuánto vale" no es ni lo más barato ni lo más caro.
        $precio = 'COALESCE(pn.price_eur, pf.price_eur, pe.price_eur)';

        // La expresión y el SENTIDO tienen que ser los mismos que usa el cursor
        // en clausulaDeCursor(), o la paginación se salta filas o las repite.
        $orden = match ($criterios->sort) {
            'name'       => 'c.name ASC',
            'release'    => "COALESCE(s.release_date, '0001-01-01') DESC",
            'rarity'     => "FIELD(p.rarity, 'mythic', 'rare', 'uncommon', 'common', 'special', 'bonus')",
            'price_asc'  => "{$precio} IS NULL, {$precio} ASC",
            'price_desc' => "{$precio} IS NULL, {$precio} DESC",
            default      => $relevancia ?? "COALESCE(s.release_date, '0001-01-01') DESC",
        };

        // `uuid` como desempate final, en el mismo sentido que la columna: sin un
        // orden TOTAL, dos filas empatadas pueden salir en distinto orden entre
        // llamadas y el scroll infinito repetiría o se saltaría cartas.
        $sentidoUuid = $criterios->sort === 'release' ? 'DESC' : 'ASC';

        return $orden . ', p.uuid ' . $sentidoUuid;
    }

    /**
     * La columna por la que avanza el cursor, según el orden pedido.
     *
     * `release_date` es NULLable y en una comparación de tuplas un NULL hace que
     * la condición entera sea desconocida —y la fila desaparece—, así que se
     * sustituye por una fecha imposible antes de comparar.
     */
    private function columnaDeCursor(SearchCriteria $criterios): string
    {
        return match ($criterios->sort) {
            'name'    => 'c.name',
            'release' => "COALESCE(s.release_date, '0001-01-01')",
            default   => 'p.uuid',
        };
    }

    /**
     * Traduce el cursor a un filtro (orden por columna) o a un offset.
     *
     * @param  list<string>         $where
     * @param  array<string, mixed> $params
     * @return int Offset a aplicar; 0 si el cursor es de columna
     */
    private function clausulaDeCursor(SearchCriteria $criterios, array &$where, array &$params): int
    {
        $datos = Cursor::decodificar($criterios->cursor);

        if ($datos === null) {
            return 0;
        }

        if (!$criterios->usaCursorDeColumna()) {
            return Cursor::offsetDe($datos);
        }

        if (!isset($datos['v'], $datos['u'])) {
            return 0;
        }

        // Comparación de tuplas: (columna, uuid) > (último visto). El uuid
        // desempata, y por eso el ORDER BY lo lleva SIEMPRE al final: sin un
        // orden total, dos filas con el mismo nombre podrían saltarse o repetirse
        // entre páginas.
        $columna    = $this->columnaDeCursor($criterios);
        $comparador = $criterios->sort === 'release' ? '<' : '>';

        $where[]              = "({$columna}, p.uuid) {$comparador} (:cursor_v, :cursor_u)";
        $params['cursor_v']   = $datos['v'];
        $params['cursor_u']   = $datos['u'];

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $filas Página ya recortada
     */
    private function cursorSiguiente(SearchCriteria $criterios, array $filas, int $posicion): ?string
    {
        if ($filas === []) {
            return null;
        }

        if (!$criterios->usaCursorDeColumna()) {
            return Cursor::porPosicion($posicion + count($filas));
        }

        $ultima = $filas[count($filas) - 1];

        return Cursor::porColumna($ultima['cursorValue'], (string) $ultima['uuid']);
    }

    /** @return array<string, string> formato → estado */
    private function legalidadesDe(string $oracleId): array
    {
        $stmt = $this->db->prepare('SELECT format, status FROM mtg_legality WHERE oracle_id = :oracle_id');
        $stmt->execute(['oracle_id' => $oracleId]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * El histórico de precios de la ficha, para la gráfica de evolución.
     *
     * Una fila por día y acabado. Se limita a un año para que una carta con
     * mucho recorrido no devuelva una respuesta enorme: la gráfica de la ficha
     * no dibuja más que eso.
     *
     * @return list<array{date: string, finish: string, priceEur: float}>
     */
    private function historicoDe(string $uuid): array
    {
        $stmt = $this->db->prepare(
            'SELECT price_date AS date, finish, price_eur AS priceEur
               FROM mtg_price_daily
              WHERE printing_uuid = :uuid
                AND price_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
              ORDER BY price_date ASC'
        );
        $stmt->execute(['uuid' => $uuid]);

        return array_map(static fn (array $f) => [
            'date'     => $f['date'],
            'finish'   => $f['finish'],
            'priceEur' => (float) $f['priceEur'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string, string>> */
    private function nombresLocalizadosDe(string $uuid): array
    {
        $stmt = $this->db->prepare(
            'SELECT language, name FROM mtg_printing_localized WHERE printing_uuid = :uuid ORDER BY language'
        );
        $stmt->execute(['uuid' => $uuid]);

        return $stmt->fetchAll();
    }

    /**
     * Fila de MySQL → forma exacta del contrato CatalogCard.
     *
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aContrato(array $fila): array
    {
        return [
            'uuid'            => $fila['uuid'],
            'oracleId'        => $fila['oracleId'],
            'name'            => $fila['name'],
            'setCode'         => $fila['setCode'],
            'setName'         => $fila['setName'],
            'collectorNumber' => $fila['collectorNumber'],
            'rarity'          => $fila['rarity'],
            'manaCost'        => $fila['manaCost'],
            'manaValue'       => $fila['manaValue'] !== null ? (float) $fila['manaValue'] : null,
            'colors'          => $fila['colors'] === '' ? [] : str_split((string) $fila['colors']),
            'colorIdentity'   => $fila['colorIdentity'] === '' ? [] : str_split((string) $fila['colorIdentity']),
            'typeLine'        => $fila['typeLine'],
            'scryfallId'      => $fila['scryfallId'],
            'finishes'        => [
                'foil'    => (bool) $fila['hasFoil'],
                'nonfoil' => (bool) $fila['hasNonfoil'],
                'etched'  => (bool) $fila['hasEtched'],
            ],
            'priceEur'        => [
                'normal' => $fila['priceNormal'] !== null ? (float) $fila['priceNormal'] : null,
                'foil'   => $fila['priceFoil']   !== null ? (float) $fila['priceFoil']   : null,
                'etched' => $fila['priceEtched'] !== null ? (float) $fila['priceEtched'] : null,
            ],
        ];
    }
}
