-- Migration: 20260913_120000_public_profile.sql
-- Descripción: user_privacy_settings y mtg_deck.share_token — el esquema del M1
--              del Plan - Perfil Público y Mazos Compartibles.
--
-- ============================================================================
-- POR QUÉ TRES NIVELES Y NO UN BOOLEANO
-- ============================================================================
-- LibraryVue resolvió lo mismo con seis flags 0/1 «qué eventos publico». Aquí la
-- pregunta es otra —«qué se ve DE MÍ»— y tiene tres respuestas, no dos: nadie,
-- mis amigos, cualquiera. Con un booleano, el nivel intermedio no existe y el
-- Plan - Amigos y Seguimiento no tendría dónde engancharse: habría que migrar la
-- tabla entera para darle sentido a la amistad.
--
-- `friends` nace INERTE a propósito: la tabla `friendships` no existe todavía y
-- `App\Domain\Social\Visibilidad` devuelve false para ese nivel. Fail-closed: una
-- sección en `friends` hoy no la ve nadie más que su dueño, y cuando el plan de
-- Amigos llegue empezará a verla quien toque. El error contrario —dejarlo pasar
-- «hasta que exista la tabla»— publica la colección de todo el mundo.
--
-- ============================================================================
-- POR QUÉ show_value Y show_wishlist NACEN EN friends Y LOS OTROS TRES EN everyone
-- ============================================================================
-- El criterio del plan es «todo visible, con flags para ocultar lo que quieras»,
-- con dos excepciones razonadas y no uniformizables:
--   * show_value: cuánto vale tu colección es información PATRIMONIAL. Qué cartas
--     tienes y cuánto dinero tienes en cartas no son el mismo dato, y el defecto
--     de lo sensible en LibraryVue (`show_notes` a 0) ya apuntaba ahí.
--   * show_wishlist: es lo que el #8 del Roadmap cruzará para proponer
--     intercambios, o sea, lo que le dice a un desconocido por dónde regatearte.
-- Si algún día se cambian estos dos defectos, que sea una decisión escrita y no
-- un descuido de una migración posterior.
--
-- ============================================================================
-- POR QUÉ NO SE CREA UNA FILA POR USUARIO
-- ============================================================================
-- La ausencia de fila SIGNIFICA «los cinco valores por defecto», y así lo
-- devuelve `MySqlUserPrivacyRepository::nivelesDe()`. De ese modo el alta de
-- usuario no se toca —este plan no mete mano en `RegisterUser`— y no hay estado
-- a medias: un usuario sin fila y otro con la fila recién creada responden
-- exactamente lo mismo. La fila la escribe `privacy_set` la primera vez que el
-- usuario cambia algo, con un ON DUPLICATE KEY UPDATE (M2).
--
-- Los DEFAULT de las cinco columnas están duplicados en PHP
-- (`App\Domain\Social\Seccion::nivelPorDefecto()`) por esa misma razón: quien no
-- tiene fila no puede leer el DEFAULT de la columna. Si aquí se cambia uno, hay
-- que cambiarlo allí — lo dice el comentario de esa clase.

CREATE TABLE IF NOT EXISTS user_privacy_settings (
    user_id         INT UNSIGNED NOT NULL PRIMARY KEY,
    show_collection ENUM('nobody','friends','everyone') NOT NULL DEFAULT 'everyone',
    show_value      ENUM('nobody','friends','everyone') NOT NULL DEFAULT 'friends'
        COMMENT 'Cuánto vale tu colección es información patrimonial: nace en friends, no en everyone',
    show_decks      ENUM('nobody','friends','everyone') NOT NULL DEFAULT 'everyone',
    show_sets       ENUM('nobody','friends','everyone') NOT NULL DEFAULT 'everyone',
    show_wishlist   ENUM('nobody','friends','everyone') NOT NULL DEFAULT 'friends'
        COMMENT 'Lo que quieres es lo que #8 cruzará para proponer intercambios',
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_privacy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- El enlace del mazo
-- ============================================================================
-- Una COLUMNA en mtg_deck, no una tabla nueva: un mazo tiene como mucho un enlace
-- vivo, y regenerarlo es sobreescribir esta celda. Una tabla `deck_share` con su
-- id y su fecha invitaría a guardar el histórico de tokens revocados, que es
-- justo lo que no se quiere tener.
--
-- NULL = no compartido, y es el estado de los mazos que ya existen: la columna
-- nace vacía sobre DATO NO RECONSTRUIBLE de David, así que esta migración es
-- aditiva y no recrea nada.
--
-- CHAR(64) porque el token es `bin2hex(random_bytes(32))`: 32 bytes de entropía,
-- 64 caracteres hexadecimales siempre, longitud fija. No es el `id` del mazo por
-- el motivo entero de la sección 🔴 del plan: un id se enumera y un token de 256
-- bits no. La colación es la de la tabla (utf8mb4_unicode_ci) para que el WHERE
-- del router público no arrastre un «Illegal mix of collations»; el hexadecimal
-- es ASCII y cabe de sobra bajo el máximo de índice de InnoDB (64 × 4 = 256 B).
--
-- Idempotente por information_schema: MySQL 8 no soporta `ADD COLUMN IF NOT
-- EXISTS` (eso es MariaDB), igual que en 20260910_160000_mtg_card_name_normalized.

SET @col_existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_deck'
       AND COLUMN_NAME  = 'share_token'
);

SET @sql = IF(@col_existe = 0,
    'ALTER TABLE mtg_deck
       ADD COLUMN share_token CHAR(64) NULL
       COMMENT ''32 bytes de random_bytes() en hex. NULL = no compartido; regenerar invalida el anterior''',
    'SELECT ''share_token ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- El índice único
-- ---------------------------------------------------------------------------
-- UNIQUE y no un KEY normal por dos motivos, y el segundo es el que importa:
--   1. La ruta `GET /api/public/deck/{token}` busca por esta columna y sin índice
--      sería un full scan de la tabla de mazos en una ruta abierta a internet.
--   2. UNIQUE convierte una colisión de tokens en un error de escritura en vez de
--      en dos mazos compartidos bajo el mismo enlace. Con 256 bits no va a pasar
--      nunca, pero el coste de garantizarlo es cero.
-- Varios NULL conviven sin problema bajo un UNIQUE de InnoDB: es lo que permite
-- que los mazos no compartidos —todos, hoy— no choquen entre sí.
--
-- Se añade como índice con nombre propio y no con el UNIQUE en línea de la
-- columna para poder comprobar su existencia por separado: un fichero que se
-- aplicase a medias dejaría la columna sin índice y este bloque lo repara.

SET @idx_existe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'mtg_deck'
       AND INDEX_NAME   = 'uq_deck_share_token'
);

SET @sql = IF(@idx_existe = 0,
    'ALTER TABLE mtg_deck ADD UNIQUE KEY uq_deck_share_token (share_token)',
    'SELECT ''uq_deck_share_token ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
