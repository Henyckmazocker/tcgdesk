-- Migration: 20260912_100000_mtg_format.sql
-- Descripción: `mtg_format` — el catálogo de formatos que existen en `mtg_legality`.
--
-- Existe para que preguntar «¿ese formato existe?» deje de ser un recorrido del
-- índice de `mtg_legality` (740.000 filas) y pase a ser una lectura de clave
-- primaria sobre 21. El `SELECT DISTINCT format` cuesta 66 ms y no deja de
-- costarlos: lo que cambia es que se paga **una vez por ingesta** en vez de en
-- cada carga de la ficha de un mazo.
--
-- Es zona catálogo (`mtg_*`): reconstruible, no se respalda, y la regenera
-- `catalog:import` al terminar.

CREATE TABLE IF NOT EXISTS mtg_format (
    format      VARCHAR(24) NOT NULL PRIMARY KEY COMMENT 'mtg_legality.format, en minúsculas',
    updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Los formatos que existen en mtg_legality. Se PUEBLA con SELECT DISTINCT al final de catalog:import; NUNCA se escribe a mano';

-- SIN clave foránea a `mtg_legality`, deliberadamente y por el mismo motivo que
-- `mtg_precon_card` no la tiene contra `mtg_printing`: las dos tablas se pueblan
-- por separado y una FK haría fallar la ingesta entera por un formato que aún no
-- tiene filas.

-- Y se puebla YA, con el mismo `SELECT DISTINCT` que ejecutará la ingesta.
--
-- No es «escribirla a mano» —no hay ninguna lista copiada aquí, es la consulta—:
-- es cerrar la ventana entre esta migración y el siguiente `catalog:import`.
-- `formatoConocido()` lee de esta tabla desde ya, y con la tabla vacía la ficha
-- de cualquier mazo perdería su aviso de legalidad (todo `known: false`) durante
-- los tres meses que pueden pasar hasta la próxima ingesta.
REPLACE INTO mtg_format (format) SELECT DISTINCT format FROM mtg_legality;
