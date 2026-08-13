-- Migration: 20260813_140000_mtg_localized_language_width.sql
-- Descripción: mtg_printing_localized.language pasa de VARCHAR(16) a VARCHAR(32).
--
-- Medido sobre el set ISD de MTGJSON: de los diez idiomas que publica, tres no
-- caben en 16 caracteres —'Chinese Traditional' y 'Portuguese (Brazil)' miden 19,
-- 'Chinese Simplified' 18—. La columna viene de [[TCGDesk/Base de Datos]], donde
-- se dimensionó antes de tener el dato delante.
--
-- No es cosmético: `language` forma parte de la PRIMARY KEY, así que truncar
-- convierte el idioma en una clave que no se puede comparar con lo que devuelve
-- MTGJSON, y bastaría con que dos idiomas compartiesen los 16 primeros caracteres
-- para perder filas silenciosamente en el upsert.
--
-- Se aplica con la tabla vacía, así que la reconstrucción de la PK es inmediata.
-- Hacerlo después de ingerir ~600.000 filas sería otra historia.

ALTER TABLE mtg_printing_localized
    MODIFY COLUMN language VARCHAR(32) NOT NULL
    COMMENT 'Spanish, Chinese Traditional, Portuguese (Brazil)… tal como lo escribe MTGJSON';
