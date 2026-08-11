-- ============================================================================
-- TCGDesk — esquema base (dev)
-- ============================================================================
-- Este fichero se monta en /docker-entrypoint-initdb.d/ y MySQL SOLO lo ejecuta
-- la primera vez, sobre un volumen vacío. A partir de ahí es un documento
-- histórico: TODO cambio posterior al esquema va en docker/database/migrations/
-- y se aplica con ./dev-setup.sh --migrate. Sin excepción.
--
-- (En LibraryVue esta disciplina se rompió y su init.sql ya no refleja el
--  esquema real. Aquí nace con el repo para no repetirlo.)
--
-- A diferencia de LibraryVue, este fichero NO hace DROP DATABASE: la base la
-- crea MYSQL_DATABASE del docker-compose, y un DROP aquí convierte cualquier
-- reejecución accidental en pérdida total de datos.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS tcgdesk_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tcgdesk_db;

-- ============================================================================
-- ZONA 3 — USUARIO (el dato de valor; nunca se reconstruye)
-- ============================================================================
-- El catálogo (mtg_*) y los precios (mtg_price_*) NO viven aquí: los crea el
-- Plan - Mirror del Catálogo MTG como migraciones. Este fichero solo levanta lo
-- transversal, que es lo que necesita la autenticación.

CREATE TABLE IF NOT EXISTS users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_id    VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Claim "sub" del JWT de Google',
    email        VARCHAR(255) NOT NULL UNIQUE,
    -- Se pide en el primer login, NO se deriva del email: es la URL pública
    -- futura (/user/:username) y generarla automáticamente la deja fea para siempre.
    username     VARCHAR(32)  NOT NULL UNIQUE,
    display_name VARCHAR(128) NULL,
    avatar_url   VARCHAR(512) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    -- Sin INDEX explícitos sobre google_id / email: el UNIQUE ya los indexa.
    -- (LibraryVue declara ambos por separado y son redundantes.)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTROL DE MIGRACIONES
-- ============================================================================
-- La crea también run_migrations.sh (bootstrap idempotente), pero declararla
-- aquí deja el esquema base completo y legible sin ejecutar nada.

CREATE TABLE IF NOT EXISTS migration_history (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    checksum   VARCHAR(64)  NOT NULL,
    INDEX idx_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de migraciones de base de datos aplicadas';
