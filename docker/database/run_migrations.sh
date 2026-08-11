#!/usr/bin/env bash
# =============================================================================
# run_migrations.sh — Aplica migraciones de base de datos pendientes
# =============================================================================
# Uso (desde la raíz del proyecto):
#   ./docker/database/run_migrations.sh
#   ./docker/database/run_migrations.sh --env-file .env.prod --compose-file docker-compose.prod.yml
#
# Variables de entorno opcionales (sobreescriben los valores del .env):
#   DB_USER  — usuario de MySQL (default: tcgdesk_user)
#   DB_PASS  — contraseña (se lee de MYSQL_PASSWORD en el .env si no se define)
#   DB_NAME  — base de datos (default: tcgdesk_db)
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR/migrations"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }

# ---------------------------------------------------------------------------
# Parsear argumentos
# ---------------------------------------------------------------------------
ENV_FILE_ARG=""
COMPOSE_FILE_ARG=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env-file=*)     ENV_FILE_ARG="${1#--env-file=}"         ;;
    --env-file)       shift; ENV_FILE_ARG="$1"                ;;
    --compose-file=*) COMPOSE_FILE_ARG="${1#--compose-file=}" ;;
    --compose-file)   shift; COMPOSE_FILE_ARG="$1"            ;;
  esac
  shift
done

# ---------------------------------------------------------------------------
# Construir el comando docker compose (con o sin env-file / compose-file)
# ---------------------------------------------------------------------------
compose_cmd() {
  local args=()
  [[ -n "$ENV_FILE_ARG" ]]     && args+=(--env-file "$ENV_FILE_ARG")
  [[ -n "$COMPOSE_FILE_ARG" ]] && args+=(-f "$COMPOSE_FILE_ARG")

  if docker compose version &>/dev/null 2>&1; then
    docker compose "${args[@]}" "$@"
  else
    docker-compose "${args[@]}" "$@"
  fi
}

# ---------------------------------------------------------------------------
# Leer una variable de un archivo .env
# ---------------------------------------------------------------------------
env_get() {
  local file="$1" key="$2"
  grep -E "^${key}=" "$file" 2>/dev/null | head -1 | cut -d'=' -f2- || true
}

# ---------------------------------------------------------------------------
# Resolver credenciales de MySQL
# ---------------------------------------------------------------------------
resolve_db_creds() {
  local env_source="${ENV_FILE_ARG:-$ROOT_DIR/.env}"

  DB_USER="${DB_USER:-tcgdesk_user}"

  if [[ -z "${DB_NAME:-}" ]]; then
    DB_NAME="$(env_get "$env_source" MYSQL_DATABASE)"
  fi
  if [[ -z "${DB_NAME:-}" ]]; then
    DB_NAME="$(env_get "$env_source" DB_DATABASE)"
  fi
  DB_NAME="${DB_NAME:-tcgdesk_db}"

  if [[ -z "${DB_PASS:-}" ]]; then
    DB_PASS="$(env_get "$env_source" MYSQL_PASSWORD)"
  fi
  if [[ -z "${DB_PASS:-}" ]]; then
    DB_PASS="$(env_get "$env_source" DB_PASSWORD)"
  fi
  if [[ -z "${DB_PASS:-}" ]]; then
    error "No se pudo resolver MYSQL_PASSWORD desde $env_source"
    error "Asegúrate de que el archivo .env existe y contiene MYSQL_PASSWORD."
    exit 1
  fi
}

# ---------------------------------------------------------------------------
# Ejecutar SQL inline en el contenedor MySQL
# ---------------------------------------------------------------------------
mysql_exec() {
  compose_cmd exec -T mysql mysql \
    -u"$DB_USER" \
    -p"$DB_PASS" \
    "$DB_NAME" \
    -e "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Ejecutar un archivo SQL local en el contenedor MySQL (vía stdin)
# ---------------------------------------------------------------------------
mysql_file() {
  compose_cmd exec -T mysql mysql \
    -u"$DB_USER" \
    -p"$DB_PASS" \
    "$DB_NAME" \
    2>/dev/null < "$1"
}

# ---------------------------------------------------------------------------
# Crear la tabla de control si no existe (bootstrap idempotente)
# ---------------------------------------------------------------------------
# Debe coincidir con la declaración de docker/database/init.sql.
bootstrap_migrations_table() {
  mysql_exec "
    CREATE TABLE IF NOT EXISTS migration_history (
      id         INT AUTO_INCREMENT PRIMARY KEY,
      filename   VARCHAR(255) NOT NULL UNIQUE,
      applied_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
      checksum   VARCHAR(64)  NOT NULL,
      INDEX idx_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Registro de migraciones de base de datos aplicadas';
  "
}

# ---------------------------------------------------------------------------
# Comprobar si una migración ya fue aplicada
# ---------------------------------------------------------------------------
is_applied() {
  local filename="$1"
  local result
  result=$(mysql_exec "SELECT COUNT(*) FROM migration_history WHERE filename='${filename}';" 2>/dev/null | tail -1)
  [[ "$result" == "1" ]]
}

# ---------------------------------------------------------------------------
# Registrar una migración como aplicada
# ---------------------------------------------------------------------------
record_migration() {
  local filename="$1" checksum="$2"
  mysql_exec "INSERT INTO migration_history (filename, checksum) VALUES ('${filename}', '${checksum}');"
}

# ---------------------------------------------------------------------------
# Runner principal
# ---------------------------------------------------------------------------
run_migrations() {
  resolve_db_creds

  info "Verificando conexión a MySQL..."
  if ! compose_cmd exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null; then
    error "MySQL no está disponible. Asegúrate de que el contenedor está corriendo."
    exit 1
  fi
  success "MySQL disponible."

  info "Inicializando tabla de control de migraciones..."
  bootstrap_migrations_table

  # Recopilar archivos *.sql de la carpeta migrations, ordenados por nombre
  local migration_files=()
  if [[ -d "$MIGRATIONS_DIR" ]]; then
    while IFS= read -r -d '' f; do
      migration_files+=("$f")
    done < <(find "$MIGRATIONS_DIR" -maxdepth 1 -name "*.sql" -print0 | sort -z)
  fi

  if [[ ${#migration_files[@]} -eq 0 ]]; then
    success "No hay archivos de migración en docker/database/migrations/. La base de datos está al día."
    return
  fi

  local pending=0
  local already_applied=0

  echo ""
  echo -e "${YELLOW}=== Estado de migraciones ===${NC}"
  for filepath in "${migration_files[@]}"; do
    local filename
    filename=$(basename "$filepath")
    if is_applied "$filename"; then
      echo -e "  ${GREEN}✓${NC} $filename  ${BLUE}(ya aplicada)${NC}"
      already_applied=$((already_applied + 1))
    else
      echo -e "  ${YELLOW}→${NC} $filename  ${YELLOW}(pendiente)${NC}"
      pending=$((pending + 1))
    fi
  done
  echo ""

  if [[ $pending -eq 0 ]]; then
    success "Base de datos actualizada — no hay migraciones pendientes. ($already_applied ya aplicadas)"
    return
  fi

  info "Aplicando $pending migración(es) pendiente(s)..."
  echo ""

  for filepath in "${migration_files[@]}"; do
    local filename
    filename=$(basename "$filepath")

    is_applied "$filename" && continue

    info "  → Aplicando: $filename ..."
    local checksum
    checksum=$(sha256sum "$filepath" | cut -d' ' -f1)

    if mysql_file "$filepath"; then
      record_migration "$filename" "$checksum"
      success "  ✓ Aplicada: $filename"
    else
      echo ""
      error "  ✗ Falló la migración: $filename"
      error "    Corrige el error en el archivo SQL y vuelve a ejecutar."
      error "    Las migraciones previas ya están registradas y no se re-ejecutarán."
      exit 1
    fi
  done

  echo ""
  success "=============================================="
  success " $pending migración(es) aplicada(s) correctamente"
  success "=============================================="
}

run_migrations
