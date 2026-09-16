-- Migration: 20260916_160000_orb_por_idioma.sql
-- Descripción: el idioma entra en el índice ORB — el M6 del
--              Plan - Reconocimiento de la Impresión por su Arte.
--
-- ============================================================================
-- LA CAUSA RAÍZ, MEDIDA Y NO SUPUESTA (2026-09-16)
-- ============================================================================
-- La siembra guardaba la imagen que sale de `mtg_printing.scryfall_id`, que es
-- **la inglesa**. Una carta española se emparejaba contra una referencia
-- inglesa y **el 70,9 % de sus keypoints —los de la caja de reglas— no casaban
-- con nada**: ORB acababa decidiendo solo por la ilustración, que es la misma en
-- todas las reimpresiones.
--
-- Medido sobre `RTR 226` en español (foto IMG20260916102602), cambiando SOLO el
-- idioma de la referencia:
--
--   Referencia inglesa  → 1º CMM 602 (67), 2º RTR 226 (50), margen 1,34 → NO certifica
--   Referencia española → 1º RTR 226 (147), 2º CMM 602 (67), margen 2,19 → SÍ certifica
--
-- Con la inglesa, 49 de los 50 inliers de RTR 226 salen del arte. Con la
-- española suma 83 de arte + 61 de la caja de reglas + 3 del título.
--
-- ============================================================================
-- POR QUÉ `language` ENTRA EN LA PK Y NO EN UN `UNIQUE` APARTE
-- ============================================================================
-- La PK vieja era `(printing_uuid, face)` y seguiría prohibiendo dos idiomas de
-- la misma impresión y cara — que es exactamente lo que este hito existe para
-- permitir. Un `UNIQUE (printing_uuid, face, language)` al lado dejaría la PK
-- vieja mandando y la tabla no admitiría la segunda fila.
--
-- La FK `fk_printing_orb_printing` sobrevive al cambio porque `printing_uuid`
-- sigue siendo la columna más a la izquierda de la PK nueva, que es el índice
-- que InnoDB le exige.
--
-- ============================================================================
-- LAS 2.638 FILAS YA SEMBRADAS SE ETIQUETAN, NO SE TIRAN
-- ============================================================================
-- El `DEFAULT 'English'` es literalmente lo que son: todas se sembraron desde
-- `mtg_printing.scryfall_id`, que es la imagen inglesa. Costaron tiempo de móvil
-- real —de 91 a 2.638 filas en dos días— y siguen sirviendo a las cartas
-- inglesas tal cual.
--
-- **Y sus ficheros NO se renombran.** `local_path` pasa a llevar el idioma
-- (`vision/orb/<2>/<uuid>-<face>-<idioma>.orb`, o dos idiomas de la misma cara
-- se pisarían el fichero) pero las rutas viejas se quedan como están: la ruta
-- sale SIEMPRE de esta columna, nunca se recompone al leer, así que el código
-- acepta las dos formas sin un solo `if`. La alternativa descartada era
-- renombrar 2.638 ficheros dentro de una transacción de esquema para ahorrarse
-- eso.
--
-- ============================================================================
-- `mtg_printing_localized.scryfall_id`: DE DÓNDE SALE
-- ============================================================================
-- De MTGJSON, no de la API de Scryfall. `foreignData` ya trae
-- `"identifiers":{"multiverseId":"…","scryfallId":"1e59f85a-…"}` junto al
-- `"language"`, comprobado sobre el `AllPrintings.json.gz` real; `MtgJsonMapper`
-- lo recorría y tiraba los `identifiers`. **La ingesta no estrena ninguna
-- credencial ni ninguna dependencia externa**, y `GOOGLE_CLIENT_ID` sigue siendo
-- la única credencial del proyecto.
--
-- NULL cuando MTGJSON no lo trae para ese idioma: entonces se cae al inglés de
-- `mtg_printing.scryfall_id`, que es el comportamiento de hoy.
--
-- Sin índice: la columna se lee por `JOIN` sobre la PK `(printing_uuid,
-- language)` y nunca se busca POR ella.
--
-- ============================================================================
-- SI ESTA MIGRACIÓN FALLA, MIRA EL LOG DEL CONTENEDOR
-- ============================================================================
-- `docker/database/run_migrations.sh:114-119` manda `stderr` a /dev/null, así
-- que una migración que falle NO enseña el error de MySQL. Sigue sin arreglar.
--   docker compose exec -T mysql mysql -u root -p... tcgdesk_db < este_fichero

ALTER TABLE mtg_printing_orb
    ADD COLUMN language VARCHAR(32) NOT NULL DEFAULT 'English'
        COMMENT 'Nombre largo de MTGJSON, el MISMO vocabulario que mtg_collection_item.language y que IDIOMAS de constants/collection.js. El DEFAULT etiqueta las filas sembradas antes del 2026-09-16, que son todas inglesas',
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (printing_uuid, face, language);

ALTER TABLE mtg_printing_localized
    ADD COLUMN scryfall_id CHAR(36) NULL
        COMMENT 'identifiers.scryfallId de foreignData. NULL cuando MTGJSON no lo trae para ese idioma: entonces se cae al inglés de mtg_printing.scryfall_id';
