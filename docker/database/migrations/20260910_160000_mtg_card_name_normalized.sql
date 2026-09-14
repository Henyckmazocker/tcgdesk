-- Migration: 20260910_160000_mtg_card_name_normalized.sql
-- Descripción: mtg_card.name_normalized — la clave de resolución por nombre del
--              M2 del Plan - Importación de Colecciones, con su índice.
--
-- POR QUÉ EXISTE ESTA COLUMNA
-- MySQL no tiene búsqueda por similitud (decisión 4 de [[TCGDesk/Decisiones
-- Técnicas]], donde se descartó PostgreSQL con pg_trgm sabiéndolo). El paso 3 del
-- resolvedor la sustituye por una clave normalizada EXACTA: 'Lim-Dûl's Vault' y
-- 'Lim-Dul's Vault' colapsan a la misma cadena, así que un usuario que teclea la
-- grafía ASCII acierta sin adivinar nada.
--
-- POR QUÉ SE RELLENA DESDE PHP Y NO CON UN UPDATE AQUÍ
-- Dos motivos medidos en el M0 del mismo plan:
--   1. No hay ext/intl en el contenedor: Normalizer::normalize() no existe, y la
--      transliteración es una tabla propia (App\Domain\Import\NameNormalizer).
--   2. La regla NO es «quitar toda la puntuación»: los blancos '_____' de las
--      Un-sets se CONSERVAN. Tratándolos como puntuación, '_____ Goblin'
--      (Unfinity) colapsa a la clave 'goblin' y se apropia de lo que el usuario
--      teclea al escribir «Goblin» — el único fallo silencioso que midió M0.
-- Un REPLACE() encadenado en SQL puro no replica ni lo uno ni lo otro. El
-- backfill lo hace `bin/tcgdesk catalog:normalize` y la ingesta la mantiene al
-- día desde MtgJsonMapper::card().
--
-- POR QUÉ NULL Y NO NOT NULL
-- La columna se crea vacía sobre 34.992 filas ya ingeridas: el NOT NULL exigiría
-- un DEFAULT '' que sería indistinguible de un nombre que normaliza a vacío. NULL
-- significa exactamente «esta carta todavía no está en el índice de nombres», que
-- es lo que cuenta `catalog:normalize` y lo que se comprueba tras cada ingesta.
--
-- LA COLACIÓN ES LA DE LA TABLA (utf8mb4_unicode_ci), A PROPÓSITO
-- Comprobado en este servidor antes de escribir la migración:
--   '_____ goblin' = 'goblin'          → 0   (los blancos NO son ignorables)
--   'lim-dul s vault' = 'lim dul s vault' → 0 (el guion tampoco)
-- Es decir, la colación no deshace por su cuenta lo que el normalizador decidió
-- conservar. Y al ser la misma que la de mtg_card.name, cualquier JOIN futuro
-- entre ambas columnas no arrastra el «Illegal mix of collations».
--
-- EL SEPARADOR ' // ' SOBREVIVE A LA NORMALIZACIÓN
-- Las 501 de 501 cartas transform/modal_dfc guardan las dos caras en `name`
-- separadas por ' // ' (930 nombres con ' // ' contando split y adventure) y los
-- usuarios teclean solo la cara frontal. Conservando el separador, el paso 3b es
-- un rango por prefijo sobre este mismo índice
-- (`name_normalized LIKE 'delver of secrets // %'`) y no hace falta una segunda
-- columna ni un LIKE sin ancla. De paso, conservarlo solo puede separar claves
-- que de otro modo colisionarían, nunca juntarlas.
--
-- Idempotente por consulta a information_schema: MySQL 8 no soporta
-- `ADD COLUMN IF NOT EXISTS` (eso es MariaDB), igual que en la migración del
-- índice ngram.

-- ---------------------------------------------------------------------------
-- Columna
-- ---------------------------------------------------------------------------
SET @col_existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_card'
       AND COLUMN_NAME  = 'name_normalized'
);

SET @sql = IF(@col_existe = 0,
    'ALTER TABLE mtg_card
       ADD COLUMN name_normalized VARCHAR(255) NULL
       COMMENT ''Clave de resolución por nombre: minúsculas, sin acentos ni puntuación, con los blancos _____ y el separador // intactos. La escribe PHP (NameNormalizer), nunca SQL''',
    'SELECT ''name_normalized ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Índice
-- ---------------------------------------------------------------------------
-- BTREE normal, no FULLTEXT: el paso 3 es una igualdad exacta (y el 3b un rango
-- por prefijo), no una búsqueda por palabras. El FULLTEXT ft_name que ya existe
-- sobre `name` sigue siendo el del paso 4, que es otro paso y otra semántica.
--
-- 255 caracteres × 4 bytes = 1020, holgado bajo el máximo de 3072 de InnoDB con
-- row_format DYNAMIC. El nombre más largo del catálogo mide 141.

SET @idx_existe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_card'
       AND INDEX_NAME   = 'idx_name_normalized'
);

SET @sql = IF(@idx_existe = 0,
    'ALTER TABLE mtg_card ADD KEY idx_name_normalized (name_normalized)',
    'SELECT ''idx_name_normalized ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
