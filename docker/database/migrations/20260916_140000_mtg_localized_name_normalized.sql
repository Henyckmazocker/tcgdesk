-- Migration: 20260916_140000_mtg_localized_name_normalized.sql
-- Descripción: la clave normalizada de los nombres LOCALIZADOS, para que el
--              resolvedor deje de ser monolingüe. Es el M2 del
--              Plan - Escáner de Cartas por Cámara.
--
-- ============================================================================
-- EL AGUJERO QUE TAPA
-- ============================================================================
-- `mtg_printing_localized` tiene 410.604 filas con el nombre de cada impresión
-- en diez idiomas, y HASTA HOY NO LAS CONSULTABA NADIE para resolver. El paso 3
-- del resolvedor mira `mtg_card.name_normalized` —inglés y solo inglés— y el
-- paso 4 hace `MATCH(c2.name)` contra la misma tabla. Resultado: fotografiar una
-- Llanura española devuelve `not_found`, y `/import` tiene exactamente el mismo
-- agujero en silencio con cualquier CSV que no esté en inglés.
--
-- ============================================================================
-- POR QUÉ UNA COLUMNA NUEVA Y NO BUSCAR POR `name`
-- ============================================================================
-- Porque esta tabla NO TIENE ÍNDICE B-TREE sobre `name`: solo los dos FULLTEXT
-- (`ft_name_loc` y el `ft_name_cjk` con parser ngram). Buscar ahí por igualdad
-- sería un escaneo de 410.604 filas POR LECTURA, con el bucle del escáner
-- disparando dos peticiones por segundo.
--
-- Y normalizada, no cruda, por lo mismo que `mtg_card.name_normalized`: lo que
-- el OCR lee y lo que el usuario teclea traen acentos, mayúsculas y puntuación
-- que el catálogo escribe de otra forma.
--
-- ============================================================================
-- LA COLUMNA NACE A NULL Y ESO ES CORRECTO
-- ============================================================================
-- La clave la calcula `App\Domain\Import\NameNormalizer` en PHP y no SQL, por
-- las dos razones de siempre: no hay `ext/intl` en el contenedor —la
-- transliteración es una tabla propia— y la regla no es «quitar la puntuación»,
-- porque los blancos `_____` de las Un-sets se conservan a propósito. Un
-- `REPLACE()` encadenado daría una clave PARECIDA Y DISTINTA, que es la peor de
-- las opciones: la columna existiría y resolvería mal.
--
-- La rellena `catalog:normalize` (que ya hacía esto mismo para `mtg_card`) y la
-- mantiene al día la ingesta. Una fila a NULL no resuelve por su idioma y no
-- protesta nadie, así que el comando devuelve 1 si al terminar queda alguna.
-- ============================================================================

ALTER TABLE mtg_printing_localized
  ADD COLUMN name_normalized VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL
      COMMENT 'NameNormalizer::normalizar(name). La clave con la que el resolvedor casa un nombre en otro idioma',
  ADD KEY idx_loc_name_normalized (name_normalized);
