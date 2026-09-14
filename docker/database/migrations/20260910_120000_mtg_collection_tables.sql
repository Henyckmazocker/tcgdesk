-- Migration: 20260910_120000_mtg_collection_tables.sql
-- Descripción: Zona 3 (usuario) del esquema — mtg_collection_item y
--              mtg_image_cache, las dos tablas que abre el Plan - Colección y Vistas.
--
-- Es la primera migración que toca DATO NO RECONSTRUIBLE. El catálogo se puede
-- borrar y volver a ingerir con `catalog:import`; una colección, no: la escribe
-- David a mano carta a carta. De ahí que ambas tablas se creen con
-- `IF NOT EXISTS` y que ninguna sentencia de este fichero borre nada.

-- ============================================================================
-- LA tabla central de la app
-- ============================================================================
-- Una fila NO es un ejemplar físico: es "de esta impresión, con este acabado,
-- idioma y condición, tengo N". La fila por ejemplar se descartó en
-- [[TCGDesk/Decisiones Técnicas]] (decisión 3) — el precio de compra por unidad
-- sería una tabla satélite, no un rediseño de esta.
--
-- EL UNIQUE KEY ES EL CONTRATO
-- Todo lo que escriba aquí lo hará con
--   INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
-- y es `uq_item` quien convierte esa línea en un upsert idempotente: añadir dos
-- veces la misma carta suma en vez de duplicar, y la importación del
-- Plan - Importación de Colecciones se podrá relanzar sin borrar antes.
--
-- `is_wishlist` entra en la clave A PROPÓSITO: querer una carta que ya tienes es
-- un caso legítimo (un segundo ejemplar, una versión mejor), y no debe colisionar
-- con la que ya está en la colección. Son dos filas distintas por diseño.
--
-- `finish` incluye 'etched'. No es un foil raro: es un acabado real con precio
-- propio en Cardmarket, ya presente en mtg_price_daily.finish y en
-- mtg_printing.has_etched. Un ENUM de solo normal/foil rompería la importación
-- a partir de Commander Legends.
--
-- `language` es VARCHAR(32), NO el VARCHAR(16) que dibuja [[TCGDesk/Base de
-- Datos]]. La migración 20260813_140000_mtg_localized_language_width.sql ya subió
-- a 32 la columna homóloga de mtg_printing_localized porque de los diez idiomas
-- que publica MTGJSON tres no caben en 16 —'Chinese Traditional' y
-- 'Portuguese (Brazil)' miden 19, 'Chinese Simplified' 18—. Aquí importa el doble:
-- la columna está DENTRO de uq_item, así que truncar el idioma haría colisionar
-- filas que no son la misma carta; y guarda el nombre largo tal cual ('Spanish',
-- no 'es') porque es la forma con la que tiene que casar en el JOIN con
-- mtg_printing_localized.
--
-- Las dos claves foráneas casan en tipo EXACTO con su destino —users.id es
-- INT UNSIGNED y mtg_printing.uuid es CHAR(36) utf8mb4_unicode_ci— porque InnoDB
-- rechaza cualquier desajuste, colación incluida, con el críptico errno 150.
-- ON DELETE CASCADE solo en user_id: borrar una cuenta se lleva su colección,
-- pero un printing NO se borra nunca mientras alguien lo tenga registrado (el
-- catálogo es reconstruible, la colección no).

CREATE TABLE IF NOT EXISTS mtg_collection_item (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED      NOT NULL,
    printing_uuid   CHAR(36)          NOT NULL COMMENT 'mtg_printing.uuid: la impresión concreta, no la carta conceptual',
    finish          ENUM('normal','foil','etched') NOT NULL DEFAULT 'normal',
    language        VARCHAR(32)       NOT NULL DEFAULT 'English' COMMENT 'Nombre largo de MTGJSON (Spanish, Chinese Traditional), no el código ISO',
    condition_grade ENUM('M','NM','EX','GD','LP','PL','PO') NOT NULL DEFAULT 'NM',
    quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bajar a 0 debe BORRAR la fila, no dejarla: si no, cuenta en "cartas únicas"',
    is_wishlist     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT 'Entra en uq_item: querer una carta que ya tienes es legítimo',
    notes           VARCHAR(512)      NULL,
    created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item (user_id, printing_uuid, finish, language, condition_grade, is_wishlist),
    KEY idx_user (user_id),
    CONSTRAINT fk_collection_user     FOREIGN KEY (user_id)       REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_collection_printing FOREIGN KEY (printing_uuid) REFERENCES mtg_printing(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='La colección: qué tiene el usuario, con la granularidad que exige valorarla';

-- ============================================================================
-- Caché de imágenes descargadas bajo demanda
-- ============================================================================
-- Decisión 6 de [[TCGDesk/Decisiones Técnicas]]: la imagen NO se descarga en la
-- petición que añade la carta —eso ataría el tiempo de respuesta al CDN de
-- Scryfall—. El comando `images:cache` las baja en segundo plano respetando los
-- 10 req/s, y esta tabla es la que dice qué copia local existe ya; mientras no
-- exista, la UI muestra la URL remota.
--
-- La clave es el scryfall_id y no el uuid de MTGJSON porque la URL de la imagen
-- se compone desde él (mtg_printing.scryfall_id, UNIQUE — así que la FK sería
-- técnicamente posible). No se declara a propósito: esta tabla es un índice de
-- ficheros en disco, reconstruible con solo volver a descargar, y no tiene por
-- qué atar la vida del catálogo.
--
-- La PK es SOLO el scryfall_id, tal como lo dibuja [[TCGDesk/Base de Datos]]:
-- una carta guarda un tamaño. Queda anotado —no resuelto aquí— que si M6 acaba
-- necesitando 'small' y 'large' a la vez, la clave tendrá que pasar a
-- (scryfall_id, size) en su propia migración.

CREATE TABLE IF NOT EXISTS mtg_image_cache (
    scryfall_id   CHAR(36)     PRIMARY KEY COMMENT 'identifiers.scryfallId: lo que compone la URL de Scryfall',
    size          ENUM('small','normal','large') NOT NULL,
    local_path    VARCHAR(512) NOT NULL COMMENT 'Ruta relativa bajo storage/; la imagen se guarda sin recortes ni overlays',
    downloaded_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Imágenes ya bajadas a disco. Reconstruible: borrarla solo obliga a volver a descargar';
