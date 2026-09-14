-- Migration: 20260910_170000_mtg_deck_tables.sql
-- Descripción: Zona 3 (usuario) del esquema — mtg_deck y mtg_deck_card, las dos
--              tablas que abre el Plan - Mazos de la Colección.
--
-- Segunda migración que toca DATO NO RECONSTRUIBLE, hermana de
-- 20260910_120000_mtg_collection_tables.sql. Un mazo lo monta David a mano, carta
-- a carta: no hay `catalog:import` que lo devuelva. De ahí que ambas tablas se
-- creen con `IF NOT EXISTS` y que ninguna sentencia de este fichero borre nada.
--
-- ============================================================================
-- POR QUÉ NO HAY NINGUNA FK DESDE mtg_deck_card A mtg_collection_item
-- ============================================================================
-- Es LA decisión estructural del plan, y no es comodidad: `mtg_collection_item.id`
-- es INESTABLE, verificado en el código.
--
--   * `MySqlCollectionRepository::changeGrade()` no reescribe la fila: la MUEVE a
--     otra combinación de `uq_item` —`condition_grade` está dentro de la clave— y,
--     cuando el destino ya existe, SUMA en el destino y BORRA la fila de origen,
--     dentro de la transacción con `FOR UPDATE`.
--   * `changeQuantity()` empieza con `if ($quantity <= 0) { $this->remove(...); }`:
--     bajar a 0 borra la fila, no la deja a cero.
--
-- El porqué de las dos cosas está escrito en
-- `backend/src/Application/UseCase/ChangeItemGrade.php:14-27`.
--
-- Una FK a ese `id` significaría que cambiar una carta de NM a LP —o venderla—
-- VACÍA EL MAZO EN SILENCIO, con o sin ON DELETE CASCADE: con él se llevaría las
-- líneas por delante, y sin él la colección dejaría de poder fundir filas. Por eso
-- el acople mazo ↔ colección SE CALCULA, NO SE REFERENCIA: un LEFT JOIN por las
-- cinco dimensiones (printing_uuid, finish, language, condition_grade + user_id,
-- con is_wishlist = 0) es lo que dice qué reclama el mazo frente a lo que hay.
-- Ese cruce vive en M3, no aquí; esta migración solo se asegura de que las cinco
-- columnas casen EN TIPO EXACTO con las de mtg_collection_item para que el JOIN no
-- degenere en conversión implícita.

-- ============================================================================
-- El mazo
-- ============================================================================
-- Cuelga de users.id y de nada más. `status` no es simétrico y es fácil de
-- confundir: solo 'built' CONSUME colección, 'building' solo calcula lo que falta
-- y 'dismantled' es puro archivo. Escribir `WHERE status != 'dismantled'` por
-- inercia mete los mazos en construcción en el consumo.
--
-- `format` es VARCHAR(24) NULL y no un ENUM a propósito: tiene que casar con
-- `mtg_legality.format`, que son los nombres de MTGJSON en minúsculas
-- (`commander`, `standard`, `modern`) y los publica el origen, no nosotros. Un
-- ENUM propio obligaría a una migración cada vez que MTGJSON añada un formato.
--
-- `user_id` es INT UNSIGNED porque users.id lo es: InnoDB rechaza cualquier
-- desajuste de tipo o colación con el críptico errno 150.

CREATE TABLE IF NOT EXISTS mtg_deck (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NOT NULL,
    name        VARCHAR(255)  NOT NULL,
    status      ENUM('built','building','dismantled') NOT NULL DEFAULT 'building',
    format      VARCHAR(24)   NULL COMMENT 'casa con mtg_legality.format; NULL = sin formato',
    notes       VARCHAR(1024) NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_status (user_id, status),
    CONSTRAINT fk_deck_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un mazo del usuario: nombre, estado, formato y notas. Dato no reconstruible';

-- ============================================================================
-- Las cartas del mazo
-- ============================================================================
-- Una línea referencia la CARTA ESPECÍFICA, con las mismas cinco dimensiones que
-- mtg_collection_item menos `is_wishlist`. Es lo que hace que el cruce sea un JOIN
-- exacto y que «te faltan 2» deje de mentir cuando tienes la carta pero en francés.
-- Corrige de paso a [[TCGDesk/Base de Datos]], que decía que los mazos apuntan al
-- oracle_id: valorar en euros exige (printing, finish).
--
-- `language` es VARCHAR(32) y está DENTRO de uq_deck_card, por lo mismo que en
-- mtg_collection_item: de los diez idiomas que publica MTGJSON, 'Chinese
-- Traditional' y 'Portuguese (Brazil)' miden 19 caracteres y 'Chinese Simplified'
-- 18. Truncar el idioma haría colisionar filas que NO son la misma carta. Es la
-- trampa que ya obligó a migrar mtg_printing_localized en agosto
-- (20260813_140000_mtg_localized_language_width.sql).
--
-- `board` incluye 'planes', 'schemes' y 'tokens' porque MTGJSON los trae y el
-- criterio es fidelidad al origen; incluye además 'companion', que MTGJSON NO
-- trae, para las decklists pegadas —`PlainTextParser.php:87` ya reconoce esa
-- cabecera—. `displayCommander` queda fuera a propósito: es cosmético (qué carta
-- enseña la caja del precon), no una zona de juego. Ojo con 'tokens': no cuenta
-- para nada —ni consume colección, ni suma al valor, ni cuenta para el tamaño
-- mínimo—, pero eso lo aplican las consultas, no el esquema.
--
-- `board` entra en uq_deck_card: la misma carta en el main y en el side son dos
-- líneas legítimas, igual que `is_wishlist` separa colección de deseos.
--
-- `count` a 0 debe BORRAR la línea, no dejarla: una línea a cero cuenta en «cartas
-- del mazo» y falsea el tamaño. Mismo criterio que `quantity` en la colección.
--
-- ON DELETE CASCADE en deck_id, y NUNCA en printing_uuid: borrar el mazo se lleva
-- sus cartas, pero el catálogo —que es reconstruible— no manda sobre el dato de
-- usuario, que no lo es.

CREATE TABLE IF NOT EXISTS mtg_deck_card (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deck_id         BIGINT UNSIGNED   NOT NULL,
    printing_uuid   CHAR(36)          NOT NULL COMMENT 'mtg_printing.uuid: la impresión concreta, no la carta conceptual',
    finish          ENUM('normal','foil','etched') NOT NULL DEFAULT 'normal',
    language        VARCHAR(32)       NOT NULL DEFAULT 'English' COMMENT 'Nombre largo de MTGJSON (Spanish, Portuguese (Brazil)), no el código ISO',
    condition_grade ENUM('M','NM','EX','GD','LP','PL','PO') NOT NULL DEFAULT 'NM',
    board           ENUM('main','side','commander','companion','planes','schemes','tokens')
                      NOT NULL DEFAULT 'main' COMMENT 'El board tokens no consume colección ni suma al valor',
    count           SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bajar a 0 debe BORRAR la fila: una línea a cero falsea el tamaño del mazo',
    UNIQUE KEY uq_deck_card (deck_id, printing_uuid, finish, language, condition_grade, board),
    KEY idx_deck (deck_id),
    CONSTRAINT fk_deckcard_deck     FOREIGN KEY (deck_id)       REFERENCES mtg_deck(id) ON DELETE CASCADE,
    CONSTRAINT fk_deckcard_printing FOREIGN KEY (printing_uuid) REFERENCES mtg_printing(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Las cartas de un mazo, con las cinco dimensiones + board. Sin FK a la colección: se calcula';
