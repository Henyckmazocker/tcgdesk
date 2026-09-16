<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\OrbDescriptorRepositoryInterface;
use PDO;

/**
 * `mtg_printing_orb` en MySQL.
 *
 * Tres detalles que no se ven en las firmas:
 *
 *  - **La consulta de referencias entra por `mtg_printing`, no por aquí.** Es un
 *    `LEFT JOIN` del catálogo contra esta tabla, y por eso devuelve fila también
 *    para las impresiones que nadie ha sembrado — que son las que el móvil tiene
 *    que ir a buscar. Entrar por `mtg_printing_orb` habría obligado a un índice
 *    por `oracle_id` aquí, que es la desnormalización que la migración explica
 *    por qué no existe.
 *  - **`estadoDe()` repite el uuid con DOS marcadores distintos.** Con
 *    `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un mismo nombre
 *    en dos puntos de la sentencia y devuelve `SQLSTATE[HY093]`. Es la misma
 *    trampa que ya está resuelta en `MySqlCardRepository::porUuids()`, y aquí
 *    aparece por la vía menos evidente: no es un `IN`, son dos `EXISTS`.
 *  - **`sembrar()` es `INSERT IGNORE` y devuelve `rowCount() === 1`.** No es un
 *    upsert como `MySqlImageCacheRepository::registrar()`, y la diferencia es
 *    deliberada: aquellas imágenes las baja nuestro propio comando del CDN,
 *    estos binarios los sube un cliente.
 */
class MySqlOrbDescriptorRepository implements OrbDescriptorRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function refsDe(string $oracleId, string $language): array
    {
        // El CTE resuelve **una sola fila por (impresión, cara)** antes de que el
        // catálogo entre en juego: se traen las candidatas de los dos idiomas que
        // pueden servir —el pedido y el inglés— y `ROW_NUMBER()` se queda con la
        // del pedido cuando existe. Hacerlo con dos `LEFT JOIN` a la misma tabla
        // y un puñado de `COALESCE` duplicaría filas en cuanto una cara estuviera
        // sembrada en los dos idiomas, que es el caso normal en cuanto esto lleve
        // un mes en marcha.
        //
        // Cada valor repetido va con un marcador NOMBRADO DISTINTO: con
        // `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un nombre en
        // dos puntos de la misma sentencia y devuelve `SQLSTATE[HY093]`.
        $stmt = $this->db->prepare(
            'WITH candidatas AS (
                 SELECT o.printing_uuid,
                        o.face,
                        o.language,
                        o.nfeatures,
                        o.keypoints,
                        o.local_path,
                        ROW_NUMBER() OVER (
                            PARTITION BY o.printing_uuid, o.face
                                ORDER BY (o.language = :idioma_orden) DESC, o.language
                        ) AS prioridad
                   FROM mtg_printing_orb o
                   JOIN mtg_printing pi ON pi.uuid = o.printing_uuid
                  WHERE pi.oracle_id = :oracle_candidatas
                    AND o.language IN (:idioma_candidatas, \'English\')
             )
             SELECT p.uuid                                   AS printingUuid,
                    COALESCE(l.scryfall_id, p.scryfall_id)   AS scryfallId,
                    CASE WHEN l.scryfall_id IS NOT NULL
                         THEN :idioma_imagen
                         ELSE \'English\'
                    END                                      AS scryfallLanguage,
                    c.face                                   AS face,
                    COALESCE(c.language, :idioma_salida)     AS language,
                    c.nfeatures                              AS nfeatures,
                    c.keypoints                              AS keypoints,
                    c.local_path                             AS localPath
               FROM mtg_printing p
          LEFT JOIN candidatas c
                 ON c.printing_uuid = p.uuid AND c.prioridad = 1
          LEFT JOIN mtg_printing_localized l
                 ON l.printing_uuid = p.uuid AND l.language = :idioma_localizado
              WHERE p.oracle_id = :oracle_id
           ORDER BY p.uuid, c.face'
        );

        $stmt->execute([
            'idioma_orden'       => $language,
            'oracle_candidatas'  => $oracleId,
            'idioma_candidatas'  => $language,
            'idioma_salida'      => $language,
            'idioma_localizado'  => $language,
            'idioma_imagen'      => $language,
            'oracle_id'          => $oracleId,
        ]);

        $salida = [];

        foreach ($stmt->fetchAll() as $fila) {
            $salida[] = [
                'printingUuid'     => (string) $fila['printingUuid'],
                // El id LOCALIZADO si MTGJSON lo publica; si no, el inglés. Es
                // la imagen que el móvil va a bajar para sembrar, y sembrar la
                // inglesa para una carta española es el fallo que abrió el M6.
                'scryfallId'       => $fila['scryfallId'] !== null ? (string) $fila['scryfallId'] : null,
                // **El idioma REAL de esa imagen**, que no siempre es el pedido:
                // el `COALESCE` de arriba cae al id inglés en silencio y el
                // cliente sella la siembra con lo que aquí ponga. Sin este campo
                // se sembrarían descriptores ingleses etiquetados `Spanish` en
                // el 52 % del catálogo —57.341 de 110.384 impresiones no tienen
                // fila en español— y con `INSERT IGNORE` eso es permanente.
                'scryfallLanguage' => (string) $fila['scryfallLanguage'],
                // Sin fila en `mtg_printing_orb` no hay cara sembrada, y la que
                // el móvil va a extraer primero es la frontal: es la que el CDN
                // sirve siempre y la única que tienen las 108.732 impresiones de
                // una sola cara.
                'face'             => $fila['face'] !== null ? (string) $fila['face'] : 'front',
                // El idioma de la referencia que va en esta fila, que puede NO
                // ser el pedido: si esa impresión solo está sembrada en inglés,
                // esto dice `English` y el cliente sabe contra qué está casando.
                // Sin sembrar en ninguno, dice el pedido: es en el que hay que
                // sembrarla.
                'language'         => (string) $fila['language'],
                'nfeatures'        => $fila['nfeatures'] !== null ? (int) $fila['nfeatures'] : null,
                'keypoints'        => $fila['keypoints'] !== null ? (int) $fila['keypoints'] : null,
                'localPath'        => $fila['localPath'] !== null ? (string) $fila['localPath'] : null,
            ];
        }

        return $salida;
    }

    public function estadoDe(string $printingUuid, string $face, string $language): array
    {
        // Dos marcadores para el MISMO valor. Con ATTR_EMULATE_PREPARES = false
        // reutilizar `:uuid` en los dos EXISTS da SQLSTATE[HY093]: el driver
        // manda los parámetros por posición y solo hay uno declarado con ese
        // nombre. La misma trampa que `porUuids()` resuelve con `:uuid_0`,
        // `:uuid_1`…
        $stmt = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM mtg_printing     WHERE uuid          = :uuid_existe) AS existe,
                EXISTS(SELECT 1 FROM mtg_printing_orb WHERE printing_uuid = :uuid_sembrada
                                                        AND face          = :face
                                                        AND language      = :language)   AS sembrada'
        );

        $stmt->execute([
            'uuid_existe'   => $printingUuid,
            'uuid_sembrada' => $printingUuid,
            'face'          => $face,
            // Por idioma: una cara sembrada en inglés sigue sembrable en
            // español, que es el motivo entero del M6.
            'language'      => $language,
        ]);

        $fila = $stmt->fetch();

        return [
            'existe'   => (int) ($fila['existe'] ?? 0) === 1,
            'sembrada' => (int) ($fila['sembrada'] ?? 0) === 1,
        ];
    }

    public function sembrar(
        string $printingUuid,
        string $face,
        string $language,
        int $nfeatures,
        int $keypoints,
        string $rutaRelativa
    ): bool {
        // INSERT IGNORE y no ON DUPLICATE KEY UPDATE: quien siembra primero
        // gana, y ningún cliente posterior reemplaza unos descriptores buenos
        // por otros malos. Que se trague ADEMÁS la clave ajena rota es el motivo
        // de que `estadoDe()` corra antes.
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mtg_printing_orb
                    (printing_uuid, face, language, nfeatures, keypoints, local_path)
             VALUES (:printing_uuid, :face, :language, :nfeatures, :keypoints, :local_path)'
        );

        $stmt->execute([
            'printing_uuid' => $printingUuid,
            'face'          => $face,
            'language'      => $language,
            'nfeatures'     => $nfeatures,
            'keypoints'     => $keypoints,
            'local_path'    => $rutaRelativa,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function olvidar(string $printingUuid, string $face, string $language): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mtg_printing_orb
                   WHERE printing_uuid = :printing_uuid
                     AND face          = :face
                     AND language      = :language'
        );

        $stmt->execute([
            'printing_uuid' => $printingUuid,
            'face'          => $face,
            // Con el idioma: sin él, deshacer una siembra española borraría
            // también la inglesa, que está buena y costó tiempo de móvil real.
            'language'      => $language,
        ]);
    }
}
