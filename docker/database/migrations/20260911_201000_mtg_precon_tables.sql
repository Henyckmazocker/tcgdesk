-- Migration: 20260911_201000_mtg_precon_tables.sql
-- Descripción: Zona 1 (catálogo) del esquema — mtg_precon y mtg_precon_card, las dos
--              tablas que abre el Plan - Catálogo de Precons. Solo el esquema: el
--              comando `decks:import` que las llena llega después.
--
-- ============================================================================
-- POR QUÉ SON TABLAS PROPIAS Y NO mtg_deck CON user_id NULL
-- ============================================================================
-- Es la decisión estructural del plan, y la respuesta corta es que la separación
-- por zonas de este proyecto es POLÍTICA DE BACKUP, no esquema.
--
--   * `mtg_deck` (20260910_170000_mtg_deck_tables.sql) es zona 3: dato de usuario
--     IRRECUPERABLE. Un mazo lo monta David a mano, carta a carta, y no hay ningún
--     comando que lo devuelva.
--   * `mtg_precon` es zona 1: catálogo RECONSTRUIBLE ENTERO relanzando un comando,
--     igual que las 110.384 filas de `mtg_printing`. No se respalda nunca.
--
-- Meter las dos cosas en la misma tabla rompería las dos direcciones a la vez:
--
--   * Un TRUNCATE de reingesta de precons —la forma normal de refrescar catálogo—
--     se llevaría por delante LOS MAZOS DE DAVID.
--   * Un backup de la zona 3, que existe para salvar unas decenas de mazos,
--     arrastraría 250.000 filas de catálogo en cada copia.
--
-- Un `user_id NULL` haría que cada consulta de cualquiera de los dos lados tuviera
-- que acordarse de filtrar por él, y la primera que se olvide mezcla el catálogo
-- público con el inventario privado sin que salte nada.

-- ============================================================================
-- El precon
-- ============================================================================
-- `file_name` es la CLAVE NATURAL, no `name`: hay precons con el mismo nombre en
-- ediciones distintas, y `SneakAttack_ZNC` —el `fileName` de `DeckList.json`— es
-- único. Es también el nombre del fichero dentro de `AllDeckFiles.tar.gz`, así que
-- la ingesta no necesita traducir nada.
--
-- `deck_type` es VARCHAR(48) y no un ENUM por lo mismo que `mtg_deck.format`: los
-- 48 valores (medidos el 2026-09-11 contra `DeckList.json`) los publica MTGJSON, no
-- nosotros, y un ENUM obligaría a una migración cada vez que añadan un tipo. La
-- lista para la UI sale de `SELECT DISTINCT deck_type`, nunca copiada a mano.
--
-- `card_count` es SMALLINT UNSIGNED (hasta 65.535; el mazo más grande no llega a
-- 400) y su 0 NO es «mazo vacío»: significa indexado pero con las cartas todavía
-- sin ingerir. La fase 1 de `decks:import` escribe las 3.029 filas del índice en
-- segundos; la fase 2, que recorre 257 MB de tar, las rellena después.
--
-- `ft_name` es FULLTEXT sobre el nombre, con la misma trampa de siempre: las
-- stopwords de InnoDB dejan búsquedas en cero silencioso, y `the` está en la lista.
-- La lista se lee de `information_schema.INNODB_FT_DEFAULT_STOPWORD`.

CREATE TABLE IF NOT EXISTS mtg_precon (
    file_name    VARCHAR(128) PRIMARY KEY COMMENT 'DeckList.fileName: SneakAttack_ZNC, la clave natural',
    set_code     VARCHAR(8)   NOT NULL COMMENT 'DeckList.code',
    name         VARCHAR(255) NOT NULL,
    deck_type    VARCHAR(48)  NOT NULL COMMENT 'Commander Deck, Theme Deck… 48 valores distintos',
    release_date DATE         NULL,
    source_url   VARCHAR(512) NULL,
    card_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = solo indexado, cartas no ingeridas',
    KEY idx_type (deck_type),
    KEY idx_set (set_code),
    KEY idx_release (release_date),
    FULLTEXT KEY ft_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un mazo preconstruido oficial de MTGJSON. Zona 1: catálogo reconstruible, no se respalda';

-- ============================================================================
-- Las cartas del precon
-- ============================================================================
-- `board` AQUÍ NO LLEVA 'companion', al revés que `mtg_deck_card`. No es un olvido:
-- allí existe para las decklists que David pega a mano —`PlainTextParser.php:87`
-- reconoce esa cabecera—, y aquí solo se guarda lo que MTGJSON trae, que no publica
-- companion como zona. `displayCommander` queda fuera por lo mismo que en el otro
-- plan: es cosmético.
--
-- NINGUNA FK A mtg_printing, a diferencia de `mtg_deck_card.fk_deckcard_printing`.
-- Precons y catálogo se ingieren por separado, así que MTGJSON puede publicar un
-- `uuid` que nuestro `AllPrintings` todavía no tenga: con una FK, LA INGESTA ENTERA
-- DE MAZOS FALLARÍA por una carta de una edición que aún no hemos importado. Se
-- resuelve donde debe, en lectura: un JOIN contra `mtg_printing` al servir la lista
-- y un CONTADOR DE HUÉRFANOS que `decks:import` imprime al terminar — ruidoso, no
-- silencioso. Es el riesgo #11 del Roadmap.
--
-- `finish` distingue 'etched' de 'foil' porque son cosas distintas: un etched tiene
-- precio propio en `mtg_price_daily.finish` y columna propia en
-- `mtg_printing.has_etched`. Mapear los dos a foil falsearía el valor de todo lo
-- posterior a Commander Legends.
--
-- La PK compuesta (precon_file, printing_uuid, board, finish) es lo que hace la
-- ingesta idempotente: relanzar `decks:import` no duplica filas. `board` y `finish`
-- entran en ella porque la misma carta en el main y de comandante, o normal y foil,
-- son líneas legítimamente distintas.
--
-- ON DELETE CASCADE hacia mtg_precon: aquí sí, porque las dos tablas son zona 1 y
-- se reconstruyen juntas.

CREATE TABLE IF NOT EXISTS mtg_precon_card (
    precon_file   VARCHAR(128) NOT NULL,
    printing_uuid CHAR(36)     NOT NULL COMMENT 'mtg_printing.uuid SIN FK a propósito: un uuid huérfano se cuenta, no rompe la ingesta',
    board         ENUM('main','side','commander','planes','schemes','tokens') NOT NULL DEFAULT 'main',
    finish        ENUM('normal','foil','etched') NOT NULL DEFAULT 'normal',
    count         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (precon_file, printing_uuid, board, finish),
    CONSTRAINT fk_preconcard_precon FOREIGN KEY (precon_file) REFERENCES mtg_precon(file_name) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Las cartas de un precon: printing + board + finish + count. Sin FK a mtg_printing';
