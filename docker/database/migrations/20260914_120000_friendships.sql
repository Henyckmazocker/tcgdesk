-- Migration: 20260914_120000_friendships.sql
-- Descripción: friendships (con el UNIQUE simétrico por columnas generadas) y
--              user_follow — el esquema del M1 del Plan - Amigos y Seguimiento.
--              Continúa 20260913_120000_public_profile.sql: aquella dejó el nivel
--              `friends` INERTE a propósito porque esta tabla no existía todavía.
--
-- ============================================================================
-- POR QUÉ EL UNIQUE ES SIMÉTRICO Y NO (requester_id, addressee_id)
-- ============================================================================
-- LibraryVue tiene UNIQUE(requester_id, addressee_id) y evita la fila cruzada
-- —(A,B) y (B,A) a la vez— con una comprobación previa en el use case
-- (el OR de su MySqlFriendshipRepository.php:32-34). Eso es una CARRERA: dos
-- personas que se piden amistad a la vez pasan las dos comprobaciones, y acaban
-- con dos solicitudes pendientes entre las mismas dos personas; aceptar una deja
-- la otra huérfana y la interfaz enseña un botón de aceptar que ya no significa
-- nada.
--
-- Aquí el UNIQUE va sobre (user_low, user_high) —el par ORDENADO, calculado por
-- la propia base de datos—, así que la doble fila cruzada no es improbable: es
-- IMPOSIBLE. El segundo INSERT da 1062 y el use case (M2) lo traduce a un 409
-- «ya existe una solicitud con esa persona» con traducirErrorDeBaseDeDatos()
-- de BaseController.
--
-- Se conservan requester_id y addressee_id aunque el UNIQUE sea simétrico porque
-- QUIÉN PIDIÓ SIGUE IMPORTANDO: es lo que decide quién ve el botón de aceptar.
-- Solo el addressee acepta o rechaza; sin esas dos columnas, cualquiera aceptaría
-- sus propias solicitudes y la amistad recíproca dejaría de ser recíproca.
--
-- ============================================================================
-- POR QUÉ VIRTUAL Y NO STORED — MEDIDO, NO SUPUESTO
-- ============================================================================
-- ⚠️ NO LAS CAMBIES A STORED. El plan las pedía STORED sobre una premisa que es
-- FALSA en MySQL 8.0.44: «MySQL 8.0 no indexa columnas virtuales en un UNIQUE».
-- Sí las indexa, y da exactamente el mismo 1062 simétrico — comprobado contra el
-- contenedor el 2026-09-14 insertando (A,B) y después (B,A).
--
-- Y lo que sí es cierto es lo CONTRARIO de lo que el plan suponía: una FK con
-- ON DELETE CASCADE no puede apuntar a la columna base de una generada STORED.
-- Con STORED, el CREATE TABLE de abajo muere entero con
--     ERROR 1215 (HY000): Cannot add foreign key constraint
-- Aislando un factor por prueba contra 8.0.44:
--     STORED  + FK ON DELETE CASCADE  → ERROR 1215
--     STORED  + FK sin acción referencial → OK
--     STORED  + FK ON DELETE RESTRICT → OK
--     VIRTUAL + FK ON DELETE CASCADE  → OK   ← esto es lo que hay escrito abajo
-- Como el CASCADE de las cuatro FK no es negociable (ver más abajo), el que se va
-- es STORED. El coste de VIRTUAL es recalcular dos LEAST/GREATEST al leer el
-- índice, sobre una tabla que tiene una fila por pareja de amigos.
--
-- Si alguien «arregla» esto de vuelta a STORED dentro de seis meses, la migración
-- dejará de aplicarse en una instalación nueva y nadie sabrá por qué.
--
-- ============================================================================
-- POR QUÉ NO HAY 'rejected' EN EL ENUM
-- ============================================================================
-- Rechazar hace DELETE. Nadie acumula un historial de desaires, y quien pidió
-- puede volver a pedir más adelante.
--
-- Con un 'rejected' y este UNIQUE simétrico, la fila rechazada IMPEDIRÍA volver a
-- pedir —el par (user_low, user_high) seguiría ocupado— aunque nadie hubiera
-- decidido nunca que un rechazo bloquea. El bloqueo permanente entraría en el
-- producto por la puerta de atrás, como efecto secundario de un valor del ENUM.
-- Bloquear a alguien está explícitamente FUERA del alcance del plan; si alguna vez
-- entra, será una tabla suya y una decisión escrita.
--
-- ============================================================================
-- POR QUÉ ON DELETE CASCADE EN LAS CUATRO FK
-- ============================================================================
-- Borrar un usuario tiene que llevarse sus amistades y sus seguimientos: una fila
-- huérfana en friendships haría fallar el JOIN con users del listado de /friends,
-- que es de donde salen username, display_name y avatar_url.
-- ESTE es el requisito que ganó frente a STORED, y por eso las columnas generadas
-- de arriba son VIRTUAL. La alternativa —bajar las FK a RESTRICT— se descartó el
-- 2026-09-14: sacrificaba el CASCADE y dejaba exactamente esas huérfanas.

CREATE TABLE IF NOT EXISTS friendships (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id INT UNSIGNED NOT NULL COMMENT 'Quién pidió. Se conserva aunque el UNIQUE sea simétrico',
    addressee_id INT UNSIGNED NOT NULL,
    status       ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
    -- Columnas generadas: hacen IMPOSIBLE la doble fila cruzada, en vez de improbable.
    -- VIRTUAL y no STORED: MySQL rechaza una FK con ON DELETE CASCADE sobre la
    -- columna base de una generada STORED — `ERROR 1215`. Ver la cabecera.
    -- Y NO SE INSERTAN: un INSERT que mencione user_low o user_high falla.
    user_low     INT UNSIGNED AS (LEAST(requester_id, addressee_id))    VIRTUAL,
    user_high    INT UNSIGNED AS (GREATEST(requester_id, addressee_id)) VIRTUAL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pareja (user_low, user_high),
    KEY idx_addressee (addressee_id, status),
    KEY idx_requester (requester_id, status),
    CONSTRAINT fk_friend_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_friend_addressee FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- POR QUÉ user_follow ES OTRA TABLA Y NO UN `type` EN friendships
-- ============================================================================
-- Amistad y seguimiento se parecen en que las dos son un par de user_id, y se
-- diferencian en todo lo demás:
--
--              | Amistad                  | Seguir
--   Dirección  | Recíproca                | Unilateral
--   Permiso    | Hay que aceptar          | No se pide
--   ACCESO     | Nivel `friends`          | NINGUNO
--   Clave      | UNIQUE simétrico         | PK(follower, followed), asimétrica A PROPÓSITO
--   Estados    | pending / accepted       | No tiene
--
-- Con un `type` en una sola tabla, el UNIQUE simétrico de arriba solo aplicaría a
-- la mitad de las filas — y A→B y B→A siguiéndose mutuamente son DOS HECHOS
-- LEGÍTIMOS que ese UNIQUE prohibiría. Cada consulta tendría que acordarse de cuál
-- de las dos cosas está mirando, que es como nace un fallo de los que no se ven
-- hasta que alguien ve lo que no debía.
--
-- SEGUIR NO DA NINGÚN ACCESO. user_follow no aparece en
-- App\Domain\Social\Visibilidad, en ningún sitio, jamás. Si alguna consulta une
-- estas dos tablas con un OR, el nivel `friends` pasa a significar «cualquiera que
-- pulse seguir», o sea, `everyone`.
--
-- ============================================================================
-- Y NO ES LO QUE LibraryVue TENÍA
-- ============================================================================
-- Allí user_follows existía PARA LLENAR EL FEED, y se eliminó al pasar a amistad
-- recíproca (su 20260513_120000_friends_and_feed.sql:4, DROP TABLE IF EXISTS
-- user_follows). Aquí no hay feed ni lo va a haber, así que seguir significa otra
-- cosa: UNA LISTA DE PERFILES A LOS QUE VOLVER, que es lo que el #8 del Roadmap
-- necesita para cruzar colecciones. Se reintroduce el nombre, no el concepto.

CREATE TABLE IF NOT EXISTS user_follow (
    follower_id INT UNSIGNED NOT NULL,
    followed_id INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followed_id),
    KEY idx_followed (followed_id),
    CONSTRAINT fk_follow_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_followed FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Marcador unilateral sobre un perfil público. NO da ningún acceso: ver el plan';
