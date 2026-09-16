-- Migration: 20260916_150000_mtg_printing_orb.sql
-- Descripción: los descriptores ORB por impresión y cara — el M1 del
--              Plan - Reconocimiento de la Impresión por su Arte.
--
-- ============================================================================
-- QUÉ GUARDA ESTA TABLA, Y DÓNDE ESTÁ DE VERDAD EL DATO
-- ============================================================================
-- Aquí NO está el binario: está su RUTA. El bloque de bytes vive en
-- `storage/vision/orb/<2 primeros del uuid>/<uuid>-<face>.orb` y lo escribe
-- `App\Infrastructure\Vision\OrbDescriptorStore` con el mismo patrón
-- `.parcial` + rename de `ScryfallImageDownloader`.
--
-- No hay BLOB y no lo va a haber: no existe ni uno en las 15 migraciones
-- anteriores, el `innodb_buffer_pool_size` del contenedor son **128 MB** y la
-- BD ya pesa 3,28 GB. Con `nfeatures=700` sobre el tamaño `normal` cada cara
-- son **28.000 B** —el contador SATURA: 700,0 de media y 686 el mínimo sobre
-- 777 referencias medidas el 2026-09-15, así que la cuenta se hace con el
-- máximo y no con una media optimista—, y las 110.384 impresiones serían ~3 GB
-- metidos en el buffer pool que sirve al catálogo entero.
--
-- ============================================================================
-- EL FICHERO MIDE `keypoints * 40`, NO `keypoints * 32`
-- ============================================================================
-- Y esto es lo único de esta migración que muerde en silencio si se lee mal.
-- El bloque son DOS cosas pegadas, en este orden:
--
--   [0 .. keypoints*32)             descriptores — 32 B por keypoint (CV_8U, 32 columnas)
--   [keypoints*32 .. keypoints*40)  coordenadas  — 2 float32 little-endian (x, y) por keypoint
--
-- El porqué: la tubería del móvil es BFMatcher → Lowe → findHomography(puntos
-- de la consulta, puntos de la REFERENCIA, RANSAC) → inliers, y `MARGEN_MINIMO`
-- está definido sobre los **inliers**. Sin las coordenadas de los keypoints de
-- la referencia no hay `dstPoints`, no hay homografía, no hay inliers y no hay
-- nada que comparar con 1,5. El contrato original mandaba «descriptors» a secas
-- y se enmendó el 2026-09-15 al descubrirlo en el M0(a).
--
-- Un `cv.Mat` con la forma equivocada **empareja sin quejarse y devuelve
-- basura**: partir el bloque por el sitio equivocado da exactamente ese
-- síntoma, y por eso el reparto está escrito aquí y no solo en el JS.
--
-- ============================================================================
-- POR QUÉ NO HAY ÍNDICE POR `oracle_id`
-- ============================================================================
-- Deliberado: la consulta de `scan_orb_refs` entra por `mtg_printing`, que ya
-- tiene el suyo, y hace `LEFT JOIN` con esta tabla por la PRIMARY KEY.
-- Duplicar `oracle_id` aquí es una desnormalización que habría que mantener al
-- día con `catalog:import`, y el día que se desincronice el escáner dejaría de
-- ver referencias sin que nada se ponga rojo.
--
-- ============================================================================
-- POR QUÉ `ON DELETE CASCADE` EN LA FK, Y POR QUÉ LA FK NO BASTA
-- ============================================================================
-- CASCADE porque esta tabla es **zona 1 derivada**: si una impresión desaparece
-- del catálogo, sus descriptores no significan nada. Es el mismo criterio que
-- tenía `mtg_printing_hash` y que tiene `mtg_precon_card → mtg_precon`.
--
-- Pero la FK NO es la validación: `vision_orb_store` siembra con
-- `INSERT IGNORE`, y **`INSERT IGNORE` también se traga el fallo de clave
-- ajena**. Un `printing_uuid` inexistente devolvería 204 sin escribir una fila
-- y esa carta no se sembraría nunca, en silencio. Por eso el controller
-- comprueba **antes y explícitamente** que la impresión existe
-- (`OrbDescriptorRepositoryInterface::estadoDe()`), y la FK se queda como lo
-- que es: la red de abajo, no la puerta.
--
-- ============================================================================
-- POR QUÉ SE INSERTA Y NUNCA SE SOBRESCRIBE
-- ============================================================================
-- Los binarios los sube un cliente y acaban en una tabla COMPARTIDA del
-- catálogo. `INSERT IGNORE` —y no `ON DUPLICATE KEY UPDATE`, que es lo que hace
-- `mtg_image_cache`— es lo que impide que un cliente posterior reemplace unos
-- descriptores buenos por otros malos: el primero que siembra una impresión la
-- siembra, y el segundo recibe un 409.
--
-- La red de seguridad de fondo es la misma que la de `mtg_image_cache`: la
-- tabla es **reconstruible y prescindible**. Si alguna vez se sospecha que está
-- envenenada, se vacía (y se borra `storage/vision/orb/`) y se vuelve a sembrar
-- escaneando; mientras tanto el escáner sigue identificando por nombre.
--
-- ============================================================================
-- SI ESTA MIGRACIÓN FALLA, MIRA EL LOG DEL CONTENEDOR
-- ============================================================================
-- `docker/database/run_migrations.sh:114-119` manda `stderr` a /dev/null, así
-- que una migración que falle NO enseña el error de MySQL. Sigue sin arreglar.
--   docker compose exec -T mysql mysql -u root -p... tcgdesk_db < este_fichero

CREATE TABLE IF NOT EXISTS mtg_printing_orb (
    printing_uuid CHAR(36)             NOT NULL COMMENT 'mtg_printing.uuid',
    face          ENUM('front','back') NOT NULL,
    nfeatures     SMALLINT UNSIGNED    NOT NULL COMMENT 'Con el que se extrajo. El M0(b) del 2026-09-15 lo fijó en 700 sobre el tamaño normal (488x680): con 300 el acierto cae a 7/8 y con small a 4/8. Queda escrito por fila para que dos calibraciones no se mezclen en silencio',
    keypoints     SMALLINT UNSIGNED    NOT NULL COMMENT 'Los realmente hallados. El fichero mide keypoints * 40 bytes: keypoints*32 de descriptores seguidos de keypoints*8 de coordenadas (2 float32 LE). NO son 32: findHomography necesita las coordenadas de la referencia y el margen se define sobre los inliers de RANSAC',
    local_path    VARCHAR(512)         NOT NULL COMMENT 'Ruta relativa bajo storage/, como mtg_image_cache.local_path',
    stored_at     TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (printing_uuid, face),
    CONSTRAINT fk_printing_orb_printing
        FOREIGN KEY (printing_uuid) REFERENCES mtg_printing(uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Descriptores ORB por impresión y cara. Reconstruible: borrarla solo obliga a volver a sembrar escaneando';
