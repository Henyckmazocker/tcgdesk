-- Migration: 20260914_180000_show_in_search.sql
-- Descripción: la SEXTA columna de user_privacy_settings — si apareces en el
--              buscador de usuarios de /friends. Es el M6 del
--              Plan - Amigos y Seguimiento, añadido el 2026-09-14.
--
-- ============================================================================
-- POR QUÉ ESTA COLUMNA EXISTE
-- ============================================================================
-- Hasta el M6, la única forma de llegar a una persona era saberse su `username`
-- de memoria y teclear la URL de su perfil: `friend_request` va por nombre
-- EXACTO. O sea que las cinco acciones de amistad del M2 no tenían puerta de
-- entrada. El M6 pone un buscador por prefijo en /friends.
--
-- Un buscador por prefijo hace ENUMERABLE quién está registrado, y eso va en la
-- dirección contraria del criterio con el que este mismo plan eligió `username`
-- sobre id («mandar ids numéricos por el cuerpo invitaría a recorrerlos»). Por
-- eso el buscador no se añade a secas: viene con este interruptor por usuario, y
-- el interruptor vive donde ya viven los otros cinco.
--
-- ============================================================================
-- POR QUÉ DOS VALORES Y NO LOS TRES DE `Nivel` (decidido con David el 2026-09-14)
-- ============================================================================
-- Las otras cinco columnas resuelven UN dueño por petición: se abre el perfil de
-- alguien y `App\Domain\Social\Visibilidad` decide, con su caché por dueño, qué
-- secciones se le enseñan al espectador. Un buscador resuelve N CANDIDATOS, y
-- cada resultado es un dueño distinto: con `friends` como valor válido, veinte
-- resultados costarían veinte `sonAmigos()` más veinte lecturas de privacidad,
-- porque esa caché no sirve cuando el dueño cambia en cada fila.
--
-- Con DOS valores, el filtro es una condición del WHERE y la búsqueda entera
-- cuesta UNA consulta. Y `friends` aquí sería además casi redundante: a quien ya
-- es tu amigo lo tienes listado en /friends sin buscarlo.
--
-- La consecuencia en PHP es que esta columna NO es una `Seccion` y su valor NO
-- es un `Nivel`: tiene su propio objeto de valor, `App\Domain\Social\Descubrimiento`,
-- con dos casos. Meterla en `Seccion` habría sido peor que verboso — habría
-- hecho que `nivelesDe()` devolviera SEIS entradas, y `Seguir::tieneCaraPublica()`
-- recorre precisamente eso buscando un `everyone`: un perfil con las cinco
-- secciones cerradas habría pasado a «tener cara pública» por el defecto de esta
-- columna, y el 422 del M3 se habría caído en silencio.
--
-- ============================================================================
-- POR QUÉ EL DEFECTO ES `everyone` (decidido con David el 2026-09-14)
-- ============================================================================
-- Es la única de las seis que nace abierta sin ser una sección de contenido, y
-- hay dos motivos:
--   1. El `username` YA ES la URL pública del perfil (`init.sql:34-36`), así que
--      aparecer en el buscador no publica nada que no estuviera publicado: quien
--      sale es alguien a quien ya se podía llegar escribiendo su nombre.
--   2. Un buscador que nace vacío se queda vacío para siempre, porque nadie
--      entra en el panel de privacidad a activar algo cuya existencia desconoce.
-- Quien no quiera aparecer lo baja a `nobody` y deja de salir, y eso se hace con
-- el sexto selector de `frontend/src/components/PrivacyPanel.vue`.
--
-- ============================================================================
-- EL DEFECTO ESTÁ EN TRES SITIOS, Y SI SE CAMBIA UNO SE CAMBIAN LOS TRES
-- ============================================================================
-- Igual que los otros cinco (ver la cabecera de 20260913_120000_public_profile.sql
-- y `Seccion::nivelPorDefecto()`), y por el mismo motivo de siempre: LA AUSENCIA
-- DE FILA SIGNIFICA «LOS DEFECTOS», y quien no tiene fila nunca llega a leer el
-- DEFAULT de la columna. La fila la escribe `privacy_set` la primera vez que
-- alguien toca el panel, y hoy no hay NI UNA en toda la tabla.
--
--   1. Aquí, el `DEFAULT 'everyone'` de abajo — para las filas que ya existan.
--   2. `App\Domain\Social\Descubrimiento::porDefecto()` — para PHP.
--   3. El `COALESCE(p.show_in_search, 'everyone')` de
--      `MySqlUserRepository::buscarPorPrefijo()` — para el WHERE del buscador.
--
-- Y el tercero va con **LEFT JOIN**, que es lo que rompe el hito en silencio si
-- se olvida: con un INNER JOIN el buscador devolvería CERO RESULTADOS SIEMPRE
-- —nadie tiene fila— que es exactamente lo contrario del defecto que se acaba de
-- elegir, y lo haría sin un solo error.
--
-- ============================================================================
-- Idempotente por information_schema: MySQL 8 NO soporta `ADD COLUMN IF NOT
-- EXISTS` (eso es MariaDB), igual que en 20260913_120000_public_profile.sql:83.

SET @col_existe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'user_privacy_settings'
       AND COLUMN_NAME  = 'show_in_search'
);

SET @sql = IF(@col_existe = 0,
    'ALTER TABLE user_privacy_settings
       ADD COLUMN show_in_search ENUM(''nobody'',''everyone'') NOT NULL DEFAULT ''everyone''
       COMMENT ''Si apareces en el buscador de /friends. DOS valores, no tres: ver el plan. La AUSENCIA de fila significa everyone''',
    'SELECT ''show_in_search ya existe'' AS aviso'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- NO se añade ningún índice sobre show_in_search, y es deliberado
-- ---------------------------------------------------------------------------
-- El buscador filtra por `users.username LIKE 'prefijo%'`, que es lo que usa el
-- índice del UNIQUE de esa columna y lo que reduce la búsqueda a un puñado de
-- filas. Esta columna solo se lee DESPUÉS, sobre esas pocas, a través de la
-- PRIMARY KEY de `user_privacy_settings` (`p.user_id = u.id`). Un índice sobre
-- un ENUM de dos valores con la inmensa mayoría en el mismo valor —o, hoy, sin
-- ninguna fila— no lo elegiría el optimizador nunca, y sería una estructura que
-- mantener en cada `privacy_set` a cambio de nada.
