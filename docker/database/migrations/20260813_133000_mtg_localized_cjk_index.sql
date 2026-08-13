-- Migration: 20260813_133000_mtg_localized_cjk_index.sql
-- Descripción: Índice FULLTEXT con parser ngram para los idiomas sin espacios
--              (japonés, chino, coreano) en mtg_printing_localized.
--
-- POR QUÉ EXISTE ESTA MIGRACIÓN
-- El parser FULLTEXT por defecto de MySQL tokeniza por espacios, y el japonés no
-- los usa. Medido sobre la BD real recién creada: buscar '+流刑*' contra
-- '流刑への道' devuelve 0 filas, mientras que 'destierro' y las palabras de tres
-- letras ('Fog', 'Ire') sí casan. Sin esto, la promesa de buscar en los diez
-- idiomas que publica MTGJSON no se cumple para japonés, chino ni coreano.
--
-- POR QUÉ UNA COLUMNA APARTE Y NO CAMBIAR ft_name_loc A ngram
-- ngram tokeniza en bigramas TODO lo que indexa. Aplicado al español y al inglés
-- mete ruido justo donde el plan sitúa el riesgo principal: nombres cortos que se
-- parecen entre sí (Lightning Bolt / Lightning Strike / Chain Lightning). Con dos
-- índices, cada búsqueda usa el suyo: la consulta con caracteres CJK va contra
-- name_cjk, el resto contra name, y ninguna paga el ruido de la otra.
--
-- name_cjk se puebla SOLO para los idiomas sin separación por espacios, así que
-- el índice ngram cubre ~60.000 filas y no las ~600.000 de la tabla entera.
--
-- Idempotente por consulta a information_schema: MySQL 8 no soporta
-- `ADD COLUMN IF NOT EXISTS` (eso es MariaDB), así que la comprobación es manual.

-- ---------------------------------------------------------------------------
-- Columna
-- ---------------------------------------------------------------------------
SET @col_existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_printing_localized'
       AND COLUMN_NAME  = 'name_cjk'
);

SET @sql = IF(@col_existe = 0,
    'ALTER TABLE mtg_printing_localized
       ADD COLUMN name_cjk VARCHAR(255) NULL
       COMMENT ''Copia de name SOLO para idiomas sin espacios (Japanese, Chinese*, Korean); NULL en el resto''',
    'SELECT ''name_cjk ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Índice ngram
-- ---------------------------------------------------------------------------
-- ngram_token_size = 2 (el default del servidor): las búsquedas CJK de un solo
-- carácter no casarán. Es aceptable — ningún nombre de carta japonés es de un
-- solo carácter — y bajarlo a 1 exige reiniciar MySQL y reconstruir el índice.

SET @idx_existe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_printing_localized'
       AND INDEX_NAME   = 'ft_name_cjk'
);

SET @sql = IF(@idx_existe = 0,
    'ALTER TABLE mtg_printing_localized
       ADD FULLTEXT KEY ft_name_cjk (name_cjk) WITH PARSER ngram',
    'SELECT ''ft_name_cjk ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
