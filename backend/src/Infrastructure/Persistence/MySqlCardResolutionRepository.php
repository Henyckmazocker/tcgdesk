<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardResolutionRepositoryInterface;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;

/**
 * Las consultas del resolvedor de importación contra el catálogo local.
 *
 * Las decisiones de esta clase, ninguna evidente:
 *
 *  - **El paso 4 reutiliza `BooleanExpressionBuilder`**, la misma tokenización
 *    que la búsqueda del catálogo. Escribir aquí un segundo tokenizador sería
 *    volver a tropezar con lo que aquel ya resolvió: las stopwords de InnoDB
 *    ('the' mide 3 caracteres y no está indexada) y los tokens más cortos que
 *    `innodb_ft_min_token_size`, que exigidos con '+' devuelven CERO filas sin
 *    ningún error.
 *  - **El `MATCH` se resuelve una sola vez, en un CTE**, y lo demás se une por
 *    `oracle_id`. Es la trampa medida del repositorio de búsqueda: con un
 *    `EXISTS (SELECT … MATCH …)` correlacionado MySQL lo evalúa una vez por fila
 *    y 'bosque' pasó de 14 ms a 125 segundos.
 *  - **El paso 3b escapa el `_` antes del LIKE.** La clave normalizada conserva
 *    los blancos de las Un-sets, y `_` es un comodín de LIKE: sin escapar,
 *    `'_____ goblin // %'` casaría con cualquier cosa de cinco caracteres y el
 *    fallo silencioso que M0 midió volvería por la puerta de atrás.
 *  - **Las impresiones traen `nameNormalized`, y no es decorativo.** Es lo que
 *    permite al paso 2 comprobar que el nombre de la línea y el `(set, número)`
 *    hablan de la misma carta sin una segunda consulta: `ICE 96` es *Shyft*, no
 *    *Lim-Dûl's Vault*, y sin ese contraste la línea entraba en la colección
 *    convertida en otra carta.
 *  - **Un candidato de carta solo trae `printingUuid` si la carta tiene UNA
 *    impresión.** `MIN(p.uuid)` no es «la impresión buena»: es la única que hay,
 *    y por eso solo se copia cuando el COUNT dice 1. Elegir edición entre varias
 *    no es cosa del resolvedor.
 *  - **El paso 2b no lista pares: lista nombres y números por separado.** El
 *    emparejamiento lo hace `GROUP BY (oracle_id, collector_number)` y lo filtra
 *    `HAVING COUNT(*) = 1`, y quien recoge cada par es el mapa de salida. Medido
 *    el 2026-09-15 con `SHOW PROFILES` sobre un lote de 500: un `OR` de 500
 *    grupos `(número = ? AND (nombre = ? OR nombre LIKE ?))` tarda **1,23 s**
 *    porque MySQL evalúa las 500 ramas fila a fila; separados en dos listas son
 *    **0,41 s**, exactamente lo que ya cuesta hoy el paso 3b con sus 500 `LIKE`
 *    (0,42 s medidos). No se añade una clase de coste nueva.
 */
class MySqlCardResolutionRepository implements CardResolutionRepositoryInterface
{
    /**
     * Claves por sentencia.
     *
     * Un CSV de ManaBox de 20.000 líneas no cabe en un solo `IN (...)`: el techo
     * real son los 65.535 marcadores de un prepared statement y el
     * `max_allowed_packet`. Con 500 por lote son 40 sentencias, tres órdenes de
     * magnitud menos que una consulta por fila.
     */
    private const TAMANO_LOTE = 500;

    /** Carácter de escape del LIKE del paso 3b. No aparece en ninguna clave. */
    private const ESCAPE_LIKE = '!';

    /**
     * Cuántas veces tiene que ganar la carta mayoritaria del paso 3c para que se
     * quede sola. **Son 10, y el número está medido** — ver
     * `desempatarPorDominancia()`, donde está el porqué entero.
     *
     * Bajarlo a 2 se llevaría por delante los 151 casos intermedios, que no son
     * dato sucio sino nombres que dos cartas comparten con reparto desigual.
     */
    private const DOMINANCIA_MINIMA = 10;

    /**
     * Longitud mínima para que el paso 5 busque parecidos. **Son 6, y salen de
     * una tanda real** — ver `cartasPorNombreAproximado()`.
     *
     * Las dos erratas que este paso existe para rescatar («Tlanura», «Llasura»)
     * miden siete caracteres. Por debajo de seis, lo que llega no son erratas:
     * son trozos de borde que el OCR lee en cualquier fotograma.
     */
    private const LONGITUD_MINIMA_APROXIMADA = 6;

    /**
     * Columnas de los pasos por nombre: identifican una CARTA y cuentan sus
     * impresiones, que es lo que dice si la edición queda determinada o no.
     */
    private const COLUMNAS_CARTA = '
        c.oracle_id       AS oracleId,
        c.name,
        c.name_normalized AS clave,
        COUNT(p.uuid)     AS impresiones,
        MIN(p.uuid)       AS uuidUnico,
        MIN(p.set_code)   AS setUnico
    ';

    public function __construct(
        private readonly PDO $db,
        private readonly BooleanExpressionBuilder $expresion
    ) {
    }

    public function impresionesPorScryfallId(array $scryfallIds): array
    {
        $encontradas = [];

        foreach ($this->lotes($scryfallIds) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            $stmt = $this->db->prepare(
                'SELECT p.scryfall_id AS scryfallId, p.uuid, p.oracle_id AS oracleId,
                        c.name, c.name_normalized AS nameNormalized,
                        p.set_code AS setCode, p.collector_number AS collectorNumber
                   FROM mtg_printing p
                   JOIN mtg_card c ON c.oracle_id = p.oracle_id
                  WHERE p.scryfall_id IN (' . $marcadores . ')'
            );
            $stmt->execute($lote);

            // scryfall_id es UNIQUE, así que cada clave trae como mucho una fila.
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $encontradas[mb_strtolower((string) $fila['scryfallId'])] = $this->aImpresion($fila);
            }
        }

        return $encontradas;
    }

    public function impresionesPorSetYNumero(array $pares): array
    {
        $porClave = [];

        foreach (array_chunk(array_values($pares), self::TAMANO_LOTE) as $lote) {
            // Constructor de filas: `(set_code, collector_number) IN ((?,?), …)`.
            // MySQL 8 lo resuelve por idx_set_number en vez de degradar a scan.
            $tuplas  = implode(', ', array_fill(0, count($lote), '(?, ?)'));
            $valores = [];

            foreach ($lote as $par) {
                $valores[] = strtoupper((string) $par['setCode']);
                $valores[] = (string) $par['collectorNumber'];
            }

            $stmt = $this->db->prepare(
                'SELECT p.uuid, p.oracle_id AS oracleId, c.name,
                        c.name_normalized AS nameNormalized,
                        p.set_code AS setCode, p.collector_number AS collectorNumber
                   FROM mtg_printing p
                   JOIN mtg_card c ON c.oracle_id = p.oracle_id
                  WHERE (p.set_code, p.collector_number) IN (' . $tuplas . ')'
            );
            $stmt->execute($valores);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $clave = strtoupper((string) $fila['setCode']) . '|' . $fila['collectorNumber'];

                // Medido: (set_code, collector_number) no tiene un duplicado en
                // las 110.384 impresiones. Si algún día lo tuviera, el par deja de
                // ser exacto y no puede resolver: se marca y se descarta abajo.
                $porClave[$clave] = array_key_exists($clave, $porClave)
                    ? null
                    : $this->aImpresion($fila);
            }
        }

        return array_filter($porClave, static fn (?array $i): bool => $i !== null);
    }

    public function impresionesPorNombreYNumero(array $pares): array
    {
        /** @var array<string, array<string, mixed>|null> $porClave */
        $porClave = [];

        foreach (array_chunk(array_values($pares), self::TAMANO_LOTE) as $lote) {
            $claves  = [];
            $numeros = [];

            foreach ($lote as $par) {
                $clave  = (string) $par['name'];
                $numero = (string) $par['collectorNumber'];

                if ($clave === '' || $numero === '') {
                    continue;
                }

                // Las dos listas se deduplican: 300 líneas de la misma carta son
                // una sola clave, y el `LIKE` de la cara frontal es lo caro.
                $claves[$clave]   = true;
                $numeros[$numero] = true;
            }

            if ($claves === []) {
                continue;
            }

            $claves  = array_keys($claves);
            $numeros = array_keys($numeros);

            // Dos condiciones sobre la MISMA columna indexada: igualdad exacta
            // (paso 3) y prefijo de cara frontal (paso 3b). Sin la segunda, las
            // 932 cartas de doble cara no casarían nunca, porque el usuario
            // teclea la mitad izquierda de lo que guarda el catálogo.
            $exactas = implode(', ', array_fill(0, count($claves), '?'));
            $caras   = implode(
                ' OR ',
                array_fill(0, count($claves), "c.name_normalized LIKE ? ESCAPE '" . self::ESCAPE_LIKE . "'")
            );
            $marcasNumero = implode(', ', array_fill(0, count($numeros), '?'));

            $valores = array_merge(
                $claves,
                array_map(
                    fn (string $c): string => $this->escaparLike($c) . NameNormalizer::SEPARADOR . '%',
                    $claves
                ),
                $numeros
            );

            // El `HAVING COUNT(*) = 1` ES la regla de «cede el turno»: un grupo con
            // varias impresiones —`Plains 250` vive en 14 ediciones— no sale de la
            // consulta, así que su par no llega al mapa y la fila sigue al paso 3.
            $stmt = $this->db->prepare(
                'SELECT c.oracle_id        AS oracleId,
                        c.name,
                        c.name_normalized  AS nameNormalized,
                        p.collector_number AS collectorNumber,
                        MIN(p.uuid)        AS uuid,
                        MIN(p.set_code)    AS setCode
                   FROM mtg_card c
                   JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE (c.name_normalized IN (' . $exactas . ') OR ' . $caras . ')
                    AND p.collector_number IN (' . $marcasNumero . ')
                  GROUP BY c.oracle_id, p.collector_number
                 HAVING COUNT(*) = 1'
            );
            $stmt->execute($valores);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                foreach ($this->clavesDeNombreYNumero($fila) as $clave) {
                    // Mismo truco que el paso 2: la segunda vez que una clave
                    // aparece se marca null en vez de sobrescribir. Pasa cuando dos
                    // cartas distintas comparten clave y número (`sly spy`) o cuando
                    // la cara frontal de una choca con el nombre entero de otra: dos
                    // grupos de uno siguen siendo una ambigüedad, y una ambigüedad
                    // cede el turno.
                    $porClave[$clave] = array_key_exists($clave, $porClave)
                        ? null
                        : $this->aImpresion($fila);
                }
            }
        }

        // Indexado por la clave TAL COMO se pidió, igual que los pasos por nombre:
        // la comparación de MySQL es `utf8mb4_unicode_ci` y el número `12A` de una
        // línea vuelve de la BD como `12a`. Sin este paso el par se perdería por la
        // caja de una letra.
        $salida = [];

        foreach ($pares as $par) {
            $pedida   = (string) $par['name'] . '|' . (string) $par['collectorNumber'];
            $canonica = $this->canonicaDeNombreYNumero((string) $par['name'], (string) $par['collectorNumber']);

            if (($porClave[$canonica] ?? null) !== null) {
                $salida[$pedida] = $porClave[$canonica];
            }
        }

        return $salida;
    }

    public function cartasPorNombreNormalizado(array $claves): array
    {
        $porClave = [];

        foreach ($this->lotes($claves) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNAS_CARTA . '
                   FROM mtg_card c
                   LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE c.name_normalized IN (' . $marcadores . ')
                  GROUP BY c.oracle_id, c.name, c.name_normalized'
            );
            $stmt->execute($lote);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $porClave[mb_strtolower((string) $fila['clave'])][] = $this->aCarta($fila);
            }
        }

        return $this->indexarPorClavePedida($claves, $porClave);
    }

    public function cartasPorNombreLocalizado(array $claves): array
    {
        // ## DOS CONSULTAS, Y LA SEGUNDA NO ES UN LUJO: SON 9 SEGUNDOS
        //
        // La forma evidente —un `LEFT JOIN` de vuelta a `mtg_printing` para
        // contar las impresiones de la carta en la misma consulta que la
        // encuentra— **tarda 9,18 s con tres claves** (medido con `SHOW PROFILES`
        // el 2026-09-15). El motivo: *Forest* tiene 420 filas localizadas y 949
        // impresiones, así que el join las multiplica —400.000 filas
        // intermedias— y el `COUNT(DISTINCT)` se paga sobre eso. Con el bucle del
        // escáner disparando cada 500 ms, esa consulta es la feature entera
        // muerta.
        //
        // Partido en dos, cada mitad hace lo suyo con su índice:
        //
        //  1. **Quién es la carta**, por `idx_loc_name_normalized`. Barato: se
        //     tocan solo las filas de esa clave.
        //  2. **Cuántas impresiones tiene**, con la MISMA consulta que usan los
        //     pasos 3 y 5 (`COLUMNAS_CARTA`), y solo para las cartas que
        //     sobrevivieron al desempate.
        //
        // El desempate va en medio a propósito: contar impresiones de una carta
        // que se va a descartar es trabajo tirado.
        $porClaveIds = [];

        /** @var array<string, list<array{cuantos: int, idioma: ?string}>> $idiomasPorClave */
        $idiomasPorClave = [];

        foreach ($this->lotes($claves) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            // La clave es `l.name_normalized` y no `c.name_normalized`: se buscó
            // «llanura» y la carta se llama *Plains*, así que devolver la clave
            // inglesa dejaría a `indexarPorClavePedida()` sin nada que casar y el
            // paso entero saldría vacío sin un solo error.
            // ## LOS DOS AGREGADOS DE IDIOMA NO CAMBIAN NI UNA RESOLUCIÓN
            //
            // `COUNT(DISTINCT l.language)` y `MIN(l.language)` se calculan sobre
            // los grupos que YA existían: el `GROUP BY` no se toca, así que qué
            // carta resuelve cada clave sigue siendo exactamente lo de antes. Lo
            // único que se añade es la señal de idioma del M8, y añadirla aquí
            // es lo que la hace gratis: ni una consulta más, ni una fila más.
            $stmt = $this->db->prepare(
                'SELECT l.name_normalized        AS clave,
                        c.oracle_id              AS oracleId,
                        COUNT(*)                 AS respaldo,
                        COUNT(DISTINCT l.language) AS idiomas,
                        MIN(l.language)          AS idioma
                   FROM mtg_printing_localized l
                   JOIN mtg_printing p ON p.uuid      = l.printing_uuid
                   JOIN mtg_card     c ON c.oracle_id = p.oracle_id
                  WHERE l.name_normalized IN (' . $marcadores . ')
                  GROUP BY l.name_normalized, c.oracle_id'
            );
            $stmt->execute($lote);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $clave = mb_strtolower((string) $fila['clave']);

                $porClaveIds[$clave][] = [
                    'oracleId' => (string) $fila['oracleId'],
                    'respaldo' => (int) $fila['respaldo'],
                ];

                $idiomasPorClave[$clave][] = [
                    'cuantos' => (int) $fila['idiomas'],
                    'idioma'  => $fila['idioma'] === null ? null : (string) $fila['idioma'],
                ];
            }
        }

        if ($porClaveIds === []) {
            return $this->indexarPorClavePedida($claves, []);
        }

        foreach ($porClaveIds as $clave => $candidatos) {
            $porClaveIds[$clave] = $this->desempatarPorDominancia($candidatos);
        }

        $fichas   = $this->fichasDeCartas(array_merge(...array_values($porClaveIds)));
        $porClave = [];

        foreach ($porClaveIds as $clave => $candidatos) {
            $idioma = $this->idiomaUnicoDe($idiomasPorClave[$clave] ?? []);

            foreach ($candidatos as $candidato) {
                if (isset($fichas[$candidato['oracleId']])) {
                    // La ficha se copia (PHP pasa arrays por valor) antes de
                    // marcarla: la misma carta puede llegar por dos claves de
                    // dos idiomas distintos, y compartir el array las mezclaría.
                    $ficha             = $fichas[$candidato['oracleId']];
                    $ficha['language'] = $idioma;

                    $porClave[$clave][] = $ficha;
                }
            }
        }

        return $this->indexarPorClavePedida($claves, $porClave);
    }

    /**
     * El idioma de una clave localizada, **o `null` si no hay uno solo**.
     *
     * Es el escalón 2 de la cascada del M8 y su regla entera: una clave que
     * apunta a un único idioma lo declara; una que apunta a varios **no se
     * resuelve por mayoría**, se calla. Medido sobre las 232.439 claves de
     * `mtg_printing_localized`: 227.237 (el 97,8 %) apuntan a uno solo.
     *
     * ## Por qué aquí no hay heurística, y en el desempate por dominancia sí
     *
     * El desempate elige **carta**, y equivocarse manda la lectura a
     * `PrintingSelect.vue`, que el usuario ve. Esto elige **idioma**, que entra
     * en el `UNIQUE KEY` de `mtg_collection_item`: equivocarse parte la
     * colección en dos sin un solo error. Sin señal clara, `null` — y `null`
     * significa «que decida el cliente con su ajuste», que es lo de siempre.
     *
     * La cuenta se hace sobre **todos** los grupos de la clave, incluidos los que
     * el desempate por dominancia va a descartar: la pregunta es en qué idiomas
     * está escrito ese nombre, no a qué carta acaba apuntando.
     *
     * @param list<array{cuantos: int, idioma: ?string}> $grupos
     */
    private function idiomaUnicoDe(array $grupos): ?string
    {
        $unico = null;

        foreach ($grupos as $grupo) {
            // Un grupo con dos idiomas ya basta para que la clave sea ambigua.
            if ($grupo['cuantos'] !== 1 || $grupo['idioma'] === null) {
                return null;
            }

            if ($unico !== null && $unico !== $grupo['idioma']) {
                return null;
            }

            $unico = $grupo['idioma'];
        }

        return $unico;
    }

    /**
     * Las fichas de unas cartas por su `oracle_id`, con **la misma consulta que
     * los pasos 3 y 5**: `COLUMNAS_CARTA` es lo que hace que `impresiones`,
     * `printingUuid` y `setCode` signifiquen lo mismo viniendo de donde vengan.
     *
     * `impresiones` cuenta **todas las de la carta**, no las que estén traducidas
     * al idioma por el que se la encontró. La diferencia no es cosmética: una
     * carta con 200 impresiones de las que MTGJSON solo tradujo una diría
     * `impresiones = 1`, el escáner lo convertiría en `certaintySource: 'single'`
     * y el modo manos libres escribiría sola una edición inventada.
     *
     * @param  list<array{oracleId: string, respaldo: int}> $candidatos
     * @return array<string, array<string, mixed>> oracle_id → ficha
     */
    private function fichasDeCartas(array $candidatos): array
    {
        $ids = array_values(array_unique(array_column($candidatos, 'oracleId')));

        if ($ids === []) {
            return [];
        }

        $salida = [];

        foreach ($this->lotes($ids) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNAS_CARTA . '
                   FROM mtg_card c
                   LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE c.oracle_id IN (' . $marcadores . ')
                  GROUP BY c.oracle_id, c.name, c.name_normalized'
            );
            $stmt->execute($lote);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $salida[(string) $fila['oracleId']] = $this->aCarta($fila);
            }
        }

        return $salida;
    }

    /**
     * El desempate del paso 3c: **la carta que domina por ≥ 10× se queda sola**.
     *
     * ## Por qué hace falta, medido el 2026-09-15 contra la BD viva
     *
     * MTGJSON trae nombres localizados cruzados. `llanura` apunta a *Plains* con
     * **407** filas y a *Swamp* con **4**, todas del set INV; `isla` a *Island*
     * con 405 y a *Plains* con 4. Con la regla del paso 3 —varios candidatos es
     * empate y no se elige— **tres de las cinco tierras básicas españolas no se
     * resolverían jamás**, que es el caso más repetido de un escaneo masivo.
     *
     * ## Por qué 10× y no «la más votada gana»
     *
     * Porque lo segundo sería exactamente el fallo silencioso que este resolvedor
     * existe para no cometer. De las **298 claves de 232.439** que apuntan a más
     * de una carta —el 0,13 %—, **11 tienen una dominando por ≥10×** (el dato
     * sucio) y **136 son empates de verdad, por debajo de 2×**: nombres que dos
     * cartas comparten legítimamente en ese idioma, igual que `sly spy` los
     * comparte en inglés. Esos 136 siguen saliendo `ambiguous`, sin cambio.
     *
     * `pantano` es el caso que prueba que la regla no es un parche: *Quagmire*
     * (1 fila) es un homónimo **legítimo** de *Swamp* (701) en español, no un
     * error de MTGJSON, y la dominancia lo trata igual de bien — si escaneas algo
     * llamado «Pantano», la respuesta honesta es la carta que se llama así 701
     * veces.
     *
     * Y lo que acota el riesgo no vive aquí: una carta con cientos de impresiones
     * llega al escáner con `printingCertain: false`, así que **el modo manos
     * libres no la escribe sola** pase lo que pase. El desempate decide qué
     * propone el menú, no qué entra en la colección sin mirar.
     *
     * @param  list<array{oracleId: string, respaldo: int}> $candidatos
     * @return list<array{oracleId: string, respaldo: int}>
     */
    private function desempatarPorDominancia(array $candidatos): array
    {
        if (count($candidatos) < 2) {
            return $candidatos;
        }

        usort($candidatos, static fn (array $a, array $b): int => $b['respaldo'] <=> $a['respaldo']);

        $primero = $candidatos[0]['respaldo'];
        $segundo = $candidatos[1]['respaldo'];

        if ($segundo > 0 && $primero < $segundo * self::DOMINANCIA_MINIMA) {
            // Empate de verdad: dos cartas que comparten el nombre en ese idioma.
            return $candidatos;
        }

        return [$candidatos[0]];
    }

    public function cartasPorCaraFrontal(array $caras): array
    {
        $porCara = [];

        foreach ($this->lotes($caras) as $lote) {
            $condiciones = implode(
                ' OR ',
                array_fill(0, count($lote), "c.name_normalized LIKE ? ESCAPE '" . self::ESCAPE_LIKE . "'")
            );

            $patrones = array_map(
                fn (string $cara): string => $this->escaparLike($cara) . NameNormalizer::SEPARADOR . '%',
                $lote
            );

            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNAS_CARTA . '
                   FROM mtg_card c
                   LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE ' . $condiciones . '
                  GROUP BY c.oracle_id, c.name, c.name_normalized'
            );
            $stmt->execute($patrones);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $clave = (string) $fila['clave'];
                $corte = mb_strpos($clave, NameNormalizer::SEPARADOR);

                if ($corte === false) {
                    continue;
                }

                $porCara[mb_strtolower(mb_substr($clave, 0, $corte))][] = $this->aCarta($fila);
            }
        }

        return $this->indexarPorClavePedida($caras, $porCara);
    }

    public function cartasPorTexto(string $texto, int $limite = 25): array
    {
        $expresion = $this->expresion->construir($texto);

        if ($expresion === '') {
            return [];
        }

        $limite = max(1, $limite);

        // El MATCH vive SOLO aquí dentro y se resuelve una vez; el JOIN con las
        // impresiones cuelga del resultado, no al revés.
        $stmt = $this->db->prepare(
            'WITH coincidencias AS (
                 SELECT c2.oracle_id
                   FROM mtg_card c2
                  WHERE MATCH(c2.name) AGAINST (:expr IN BOOLEAN MODE)
                  LIMIT ' . $limite . '
             )
             SELECT ' . self::COLUMNAS_CARTA . '
               FROM coincidencias m
               JOIN mtg_card c ON c.oracle_id = m.oracle_id
               LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
              GROUP BY c.oracle_id, c.name, c.name_normalized'
        );
        $stmt->execute([':expr' => $expresion]);

        return array_map([$this, 'aCarta'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function cartasPorNombreAproximado(string $nombre, int $maxima): array
    {
        $nombre = trim($nombre);

        if ($nombre === '' || $maxima < 1) {
            return [];
        }

        // ## UN FRAGMENTO CORTO NO SE PARECE A NADA: SE CONFUNDE CON TODO
        //
        // Medido en el Realme el 2026-09-15 sobre 21 cartas: el OCR no solo lee
        // mal un nombre, **inventa cartas que no están sobre la mesa**. Lee `Pla`
        // en el borde de una Llanura, `PF` en el marco, `tab` en el texto de
        // reglas — y este paso les encontraba carta, porque a distancia 2 un
        // fragmento de tres letras alcanza media docena de nombres reales.
        //
        // Por debajo de seis caracteres, una distancia de 2 es media palabra y el
        // parecido deja de significar nada. Las erratas que este paso existe para
        // rescatar —«Tlanura», «Llasura»— miden siete, así que el corte no les
        // afecta; lo que corta es justo el ruido.
        if (mb_strlen($nombre) < self::LONGITUD_MINIMA_APROXIMADA) {
            return [];
        }

        // ## El acotado por longitud es lo que hace barato el `levenshtein`
        //
        // Dos cadenas a distancia de edición <= N no pueden diferir en más de N
        // caracteres de longitud: es la propiedad que convierte un recorrido de
        // 34.992 nombres en unos cientos. Sin ella habría que medir la distancia
        // contra el catálogo entero en cada lectura, con el bucle del escáner
        // disparando dos por segundo.
        //
        // `CHAR_LENGTH` y no `LENGTH`: la segunda cuenta BYTES, y un nombre con
        // acentos o japonés mide el doble o el triple en utf8mb4. La ventana se
        // abriría de más y dejaría de acotar nada.
        $longitud = mb_strlen($nombre);
        $minLen   = max(1, $longitud - $maxima);
        $maxLen   = $longitud + $maxima;

        // ## Este paso mira los DIEZ idiomas, no solo el inglés
        //
        // `mtg_card.name_normalized` guarda el nombre **en inglés**: la carta que
        // se llama «Llanura» está ahí como *Plains*. Buscando solo en esa tabla,
        // «Tlanura» —una errata sobre un nombre español, que es exactamente lo
        // que el OCR produce— no se parece a nada y el paso entero no sirve para
        // el idioma en que más falta hace.
        //
        // Así que se busca la clave más parecida **en las dos**, y luego se
        // resuelve por la vía que le toque: las inglesas ya traen su `oracle_id`,
        // y las localizadas pasan por `cartasPorNombreLocalizado()`, que aplica
        // su desempate por dominancia como con cualquier otro nombre traducido.
        //
        // Medido el 2026-09-15: la ventana sobre `mtg_card` son **5,6 ms** y la
        // `DISTINCT` sobre las 410.604 filas localizadas **71 ms**. Ninguna usa
        // índice —`CHAR_LENGTH()` no lo permite— y no hace falta que lo usen:
        // este paso solo corre sobre lo que ya salió `not_found`.
        $cerca = [];

        $stmt = $this->db->prepare(
            'SELECT c.oracle_id AS oracleId, c.name_normalized AS clave
               FROM mtg_card c
              WHERE c.name_normalized IS NOT NULL
                AND CHAR_LENGTH(c.name_normalized) BETWEEN :min AND :max'
        );
        $stmt->execute([':min' => $minLen, ':max' => $maxLen]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            // ## `levenshtein()` de PHP trabaja en BYTES, no en caracteres
            //
            // Con nombres acentuados cuenta de más: «Llanura» contra «Llanurá»
            // son dos bytes de diferencia y un solo carácter. Se mide sobre el
            // nombre **ya normalizado**, que es donde `NameNormalizer` ya quitó
            // los acentos con su tabla propia, así que lo que llega aquí es
            // ASCII en la práctica totalidad de los casos y el sesgo desaparece.
            // Lo que queda —japonés, chino— lo protege el acotado por longitud,
            // que sí cuenta caracteres.
            $distancia = levenshtein($nombre, (string) $fila['clave']);

            if ($distancia <= $maxima) {
                $cerca[] = ['distancia' => $distancia, 'oracleId' => (string) $fila['oracleId']];
            }
        }

        $stmt = $this->db->prepare(
            'SELECT DISTINCT name_normalized AS clave
               FROM mtg_printing_localized
              WHERE name_normalized IS NOT NULL
                AND CHAR_LENGTH(name_normalized) BETWEEN :min AND :max'
        );
        $stmt->execute([':min' => $minLen, ':max' => $maxLen]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $distancia = levenshtein($nombre, (string) $fila['clave']);

            if ($distancia <= $maxima) {
                $cerca[] = ['distancia' => $distancia, 'localizada' => (string) $fila['clave']];
            }
        }

        if ($cerca === []) {
            return [];
        }

        // Solo interesa el escalón más cercano, y es **global a los dos idiomas**:
        // quien llama acepta ÚNICAMENTE si queda un candidato, así que devolver
        // los de distancia 2 cuando hay uno a distancia 1 solo serviría para
        // convertir un acierto en un empate.
        $minima     = min(array_column($cerca, 'distancia'));
        $ganadores  = array_filter($cerca, static fn (array $c): bool => $c['distancia'] === $minima);

        $ids         = array_column($ganadores, 'oracleId');
        $localizadas = array_values(array_unique(array_filter(array_column($ganadores, 'localizada'))));

        $porCartas = $ids === []
            ? []
            : $this->fichasDeCartas(array_map(
                static fn (string $id): array => ['oracleId' => $id, 'respaldo' => 1],
                array_values(array_unique(array_filter($ids)))
            ));

        // Las claves localizadas se resuelven con el paso 3c entero, dominancia
        // incluida: una errata sobre «Llanura» no puede acabar resolviendo a algo
        // que «Llanura» escrito bien no resolvería.
        foreach ($this->cartasPorNombreLocalizado($localizadas) as $candidatos) {
            foreach ($candidatos as $carta) {
                $porCartas[$carta['oracleId']] ??= $carta;
            }
        }

        return array_values($porCartas);
    }

    /**
     * Devuelve el resultado indexado por la clave TAL COMO se pidió.
     *
     * La comparación de MySQL es `utf8mb4_unicode_ci`, así que lo que vuelve puede
     * no ser byte a byte lo que se preguntó. Quien llama busca por su propia
     * clave, y aquí se le devuelve con ella.
     *
     * @param  list<string>                              $pedidas
     * @param  array<string, list<array<string, mixed>>> $porClaveMinuscula
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexarPorClavePedida(array $pedidas, array $porClaveMinuscula): array
    {
        $salida = [];

        foreach ($pedidas as $clave) {
            $salida[$clave] = $porClaveMinuscula[mb_strtolower($clave)] ?? [];
        }

        return $salida;
    }

    /**
     * @param  list<string> $claves
     * @return list<list<string>>
     */
    private function lotes(array $claves): array
    {
        $unicas = array_values(array_unique(array_filter($claves, static fn (string $c): bool => $c !== '')));

        return $unicas === [] ? [] : array_chunk($unicas, self::TAMANO_LOTE);
    }

    /** `_` y `%` son comodines de LIKE, y las claves de las Un-sets llevan `_`. */
    private function escaparLike(string $valor): string
    {
        $e = self::ESCAPE_LIKE;

        return str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $valor);
    }

    /**
     * Las claves del paso 2b bajo las que se archiva una impresión: la de su nombre
     * entero y, si es de doble cara, la de su cara frontal.
     *
     * Son dos porque el usuario puede haber tecleado cualquiera de las dos y la
     * consulta acepta las dos. Se derivan aquí y no en SQL por lo mismo que en
     * `cartasPorCaraFrontal()`: partir por el separador en PHP es exacto y gratis,
     * y en SQL costaría renunciar al índice.
     *
     * @param  array<string, mixed> $fila
     * @return list<string>
     */
    private function clavesDeNombreYNumero(array $fila): array
    {
        $clave  = $fila['nameNormalized'] !== null ? (string) $fila['nameNormalized'] : '';
        $numero = (string) $fila['collectorNumber'];

        if ($clave === '') {
            return [];
        }

        $claves = [$this->canonicaDeNombreYNumero($clave, $numero)];
        $corte  = mb_strpos($clave, NameNormalizer::SEPARADOR);

        if ($corte !== false) {
            $claves[] = $this->canonicaDeNombreYNumero(mb_substr($clave, 0, $corte), $numero);
        }

        return $claves;
    }

    /** La forma con la que se archiva un par, insensible a la caja como MySQL. */
    private function canonicaDeNombreYNumero(string $clave, string $numero): string
    {
        return mb_strtolower($clave) . '|' . mb_strtolower($numero);
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aImpresion(array $fila): array
    {
        return [
            'printingUuid'    => (string) $fila['uuid'],
            'oracleId'        => (string) $fila['oracleId'],
            'name'            => (string) $fila['name'],
            // La MISMA columna por la que busca el paso 3. El paso 2 contrasta
            // contra ella el nombre que trae la línea; comparar contra `name`
            // recalculado dejaría a los dos pasos midiendo con distinta vara.
            'nameNormalized'  => $fila['nameNormalized'] !== null ? (string) $fila['nameNormalized'] : '',
            'setCode'         => (string) $fila['setCode'],
            'collectorNumber' => (string) $fila['collectorNumber'],
            'impresiones'     => 1,
        ];
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aCarta(array $fila): array
    {
        $impresiones = (int) $fila['impresiones'];

        return [
            'oracleId'     => (string) $fila['oracleId'],
            'name'         => (string) $fila['name'],
            'impresiones'  => $impresiones,
            // Solo cuando no hay nada que elegir.
            'printingUuid' => $impresiones === 1 && $fila['uuidUnico'] !== null ? (string) $fila['uuidUnico'] : null,
            'setCode'      => $impresiones === 1 && $fila['setUnico'] !== null ? (string) $fila['setUnico'] : null,
        ];
    }
}
