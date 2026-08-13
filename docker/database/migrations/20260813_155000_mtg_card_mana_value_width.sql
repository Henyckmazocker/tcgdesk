-- Migration: 20260813_155000_mtg_card_mana_value_width.sql
-- Descripción: mtg_card.mana_value pasa de DECIMAL(4,1) a DECIMAL(10,1).
--
-- La ingesta completa abortó en el set UNF (Unfinity) con
-- "SQLSTATE[22003] Out of range value for column 'mana_value'". La causa es real
-- y no un dato corrupto: existen cartas con coste de maná {1000000} —Gleemax es
-- la conocida—, y DECIMAL(4,1) tope en 999.9.
--
-- Medido sobre AllPrintings.json.gz entero: manaValue máximo = 1000000. El resto
-- de columnas del esquema se comprobó en la misma pasada y todas caben con
-- holgura (number 10/16, name 141/255, artist 50/255, type 50/255, borderColor
-- 10/16, frameVersion 6/16, language 19/32, format 15/24, setCode 6/8), y las
-- rarezas reales son exactamente las seis del ENUM.
--
-- Se mantiene el decimal: Unstable publica costes de media unidad ({1/2}), que
-- es la razón por la que la columna no es entera.

ALTER TABLE mtg_card
    MODIFY COLUMN mana_value DECIMAL(10,1) NULL
    COMMENT 'Coste convertido. Decimal por los {1/2} de Unstable; ancho por los {1000000} de Unfinity';
