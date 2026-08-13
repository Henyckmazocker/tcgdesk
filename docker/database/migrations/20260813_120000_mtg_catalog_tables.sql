-- Migration: 20260813_120000_mtg_catalog_tables.sql
-- Descripción: Zonas 1 (catálogo) y 2 (precios) del esquema — las siete tablas mtg_*
--              que llena el Plan - Mirror del Catálogo MTG.
--
-- El catálogo es un MIRROR RECONSTRUIBLE: se puede borrar entero y volver a
-- ingerir con `catalog:import`. Los precios NO: MTGJSON solo retiene 90 días, así
-- que lo que no se capture cada día se pierde para siempre. Viven en la misma base
-- de datos porque la consulta central de la app —"mis cartas, ordenadas por
-- precio"— es un JOIN de las tres zonas; la separación es por prefijo y por
-- política de backup, no por esquema.
--
-- El orden de creación NO es alfabético y no puede serlo: las claves foráneas
-- exigen que mtg_set y mtg_card existan antes que mtg_printing, y mtg_printing
-- antes que todo lo que cuelga de un uuid.

-- ============================================================================
-- ZONA 1 — CATÁLOGO (ingerido de MTGJSON, reconstruible, no se respalda)
-- ============================================================================

-- Ediciones. ~1.047 filas.
CREATE TABLE IF NOT EXISTS mtg_set (
    code           VARCHAR(8)        PRIMARY KEY COMMENT 'Código de MTGJSON: BLB, C21',
    name           VARCHAR(255)      NOT NULL,
    release_date   DATE              NULL,
    set_type       VARCHAR(32)       NULL COMMENT 'expansion, commander, masters',
    total_set_size SMALLINT UNSIGNED NULL,
    block          VARCHAR(64)       NULL,
    is_online_only TINYINT(1)        NOT NULL DEFAULT 0,
    KEY idx_release (release_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ediciones de MTG';

-- Carta conceptual (oracle). ~33.600 filas.
-- Una misma carta reimpresa en 40 sets es UNA fila aquí y 40 en mtg_printing.
CREATE TABLE IF NOT EXISTS mtg_card (
    oracle_id      CHAR(36)     PRIMARY KEY COMMENT 'identifiers.scryfallOracleId',
    name           VARCHAR(255) NOT NULL COMMENT 'Nombre en inglés; los demás idiomas en mtg_printing_localized',
    mana_cost      VARCHAR(64)  NULL,
    mana_value     DECIMAL(4,1) NULL,
    type_line      VARCHAR(255) NULL,
    oracle_text    TEXT         NULL,
    colors         VARCHAR(16)  NULL COMMENT 'WU, BRG',
    color_identity VARCHAR(16)  NULL,
    layout         VARCHAR(32)  NULL COMMENT 'normal, transform, modal_dfc',
    edhrec_rank    INT UNSIGNED NULL,
    FULLTEXT KEY ft_name (name),
    KEY idx_mana_value (mana_value),
    KEY idx_color_identity (color_identity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Carta conceptual: las reglas y la identidad, sin edición';

-- Impresión concreta: esta carta, en esta edición, con este número. ~105.800 filas.
CREATE TABLE IF NOT EXISTS mtg_printing (
    uuid             CHAR(36)     PRIMARY KEY COMMENT 'uuid de MTGJSON: la clave común con AllPricesToday',
    oracle_id        CHAR(36)     NOT NULL,
    set_code         VARCHAR(8)   NOT NULL,
    collector_number VARCHAR(16)  NOT NULL COMMENT 'No es numérico: existen 12a, ★, S1',
    rarity           ENUM('common','uncommon','rare','mythic','special','bonus') NOT NULL,
    artist           VARCHAR(255) NULL,
    border_color     VARCHAR(16)  NULL,
    frame_version    VARCHAR(16)  NULL,
    is_reprint       TINYINT(1)   NOT NULL DEFAULT 0,
    -- Derivadas del array finishes[] de MTGJSON. `etched` es un acabado real con
    -- precio propio (mtg_price_daily.finish ya lo admite); sin esta columna se
    -- ingerirían precios de un acabado que el catálogo no sabe que existe.
    has_foil         TINYINT(1)   NOT NULL DEFAULT 0,
    has_nonfoil      TINYINT(1)   NOT NULL DEFAULT 1,
    has_etched       TINYINT(1)   NOT NULL DEFAULT 0,
    scryfall_id      CHAR(36)     NULL COMMENT 'identifiers.scryfallId → imágenes e importación de colecciones',
    mcm_id           INT UNSIGNED NULL COMMENT 'identifiers.mcmId → enlace a la ficha de Cardmarket',
    -- NULLable y UNIQUE a la vez: MySQL admite múltiples NULL en un índice único,
    -- y hace falta porque no todo printing trae scryfallId. La unicidad es lo que
    -- convierte la importación desde ManaBox/Moxfield/Archidekt en un JOIN exacto.
    UNIQUE KEY uq_scryfall (scryfall_id),
    KEY idx_set_number (set_code, collector_number),
    KEY idx_rarity (rarity),
    FOREIGN KEY (oracle_id) REFERENCES mtg_card(oracle_id),
    FOREIGN KEY (set_code)  REFERENCES mtg_set(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='El cartón concreto: lo que tienes en una funda';

-- Nombres traducidos, TODOS los idiomas de foreignData. ~600.000 filas estimadas.
-- Es lo que permite buscar "luz de destierro" y encontrar Banishing Light. En
-- LibraryVue esto no existía y buscar en español devolvía cero resultados.
CREATE TABLE IF NOT EXISTS mtg_printing_localized (
    printing_uuid CHAR(36)     NOT NULL,
    language      VARCHAR(16)  NOT NULL COMMENT 'Spanish, Japanese… tal como lo escribe MTGJSON',
    name          VARCHAR(255) NOT NULL,
    text          TEXT         NULL,
    type_line     VARCHAR(255) NULL,
    PRIMARY KEY (printing_uuid, language),
    FULLTEXT KEY ft_name_loc (name),
    FOREIGN KEY (printing_uuid) REFERENCES mtg_printing(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Nombres y textos localizados por printing e idioma';

-- Legalidad por formato. Sale gratis de MTGJSON: no hay que mantener bans a mano.
CREATE TABLE IF NOT EXISTS mtg_legality (
    oracle_id CHAR(36)    NOT NULL,
    format    VARCHAR(24) NOT NULL COMMENT 'commander, standard, modern',
    status    ENUM('legal','not_legal','restricted','banned') NOT NULL,
    PRIMARY KEY (oracle_id, format),
    FOREIGN KEY (oracle_id) REFERENCES mtg_card(oracle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Legalidad por formato, ya calculada por MTGJSON';

-- ============================================================================
-- ZONA 2 — PRECIOS (ingerido a diario; el histórico es IRRECUPERABLE)
-- ============================================================================
-- Solo cardmarket.retail, en EUR. Los otros cuatro proveedores de MTGJSON están
-- en USD y multiplicarían por 4 la tabla a cambio de meter conversión de divisa
-- en toda la UI.

-- Un snapshot por printing, acabado y día. Crece indefinidamente: ese es el punto.
CREATE TABLE IF NOT EXISTS mtg_price_daily (
    printing_uuid CHAR(36)      NOT NULL,
    finish        ENUM('normal','foil','etched') NOT NULL,
    price_date    DATE          NOT NULL,
    price_eur     DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (printing_uuid, finish, price_date),
    KEY idx_date (price_date),
    FOREIGN KEY (printing_uuid) REFERENCES mtg_printing(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de precios propio: MTGJSON solo retiene 90 días, nosotros no borramos';

-- Desnormalización deliberada: el precio de HOY. Se reescribe entera en cada sync.
-- Sin ella, "ordenar el catálogo por precio" exige una subconsulta correlacionada
-- con MAX(price_date) por fila sobre ~200.000 combinaciones printing×acabado, que
-- no se puede indexar de forma útil.
CREATE TABLE IF NOT EXISTS mtg_price_current (
    printing_uuid CHAR(36)      NOT NULL,
    finish        ENUM('normal','foil','etched') NOT NULL,
    price_eur     DECIMAL(10,2) NOT NULL,
    updated_at    DATE          NOT NULL,
    PRIMARY KEY (printing_uuid, finish),
    KEY idx_price (price_eur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Precio vigente por printing y acabado; ordenar por precio es un índice';
