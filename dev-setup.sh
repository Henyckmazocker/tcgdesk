#!/usr/bin/env bash
# =============================================================================
# dev-setup.sh — TCGDesk: setup y arranque del entorno de desarrollo
# =============================================================================
# Uso:
#   ./dev-setup.sh          → setup interactivo (pide claves si faltan)
#   ./dev-setup.sh --reset  → recrea contenedores y volúmenes desde cero
#   ./dev-setup.sh --stop   → detiene todos los contenedores
#   ./dev-setup.sh --logs   → muestra logs en tiempo real
#   ./dev-setup.sh --mobile → prepara y compila la app Android (Capacitor)
#   ./dev-setup.sh --migrate→ aplica migraciones pendientes (sin resetear la BD)
# =============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$ROOT_DIR/.env"
BACKEND_ENV_FILE="$ROOT_DIR/backend/.env.docker-development"

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
# Helpers de argumentos
# ---------------------------------------------------------------------------
MODE="start"
for arg in "$@"; do
  case "$arg" in
    --reset)   MODE="reset"   ;;
    --stop)    MODE="stop"    ;;
    --logs)    MODE="logs"    ;;
    --mobile)  MODE="mobile"  ;;
    --migrate) MODE="migrate" ;;
    --help|-h)
      echo "Uso: $0 [--reset|--stop|--logs|--mobile|--migrate|--help]"
      echo ""
      echo "  (sin args)  Setup interactivo + arranque web (Docker)"
      echo "  --reset     Recrea contenedores y volúmenes desde cero"
      echo "  --stop      Detiene todos los contenedores"
      echo "  --logs      Logs en tiempo real de todos los servicios"
      echo "  --mobile    Prepara .env.mobile, secrets.xml, compila APK de debug"
      echo "  --migrate   Aplica migraciones de BD pendientes (sin resetear la BD)"
      exit 0
      ;;
  esac
done

# ---------------------------------------------------------------------------
# Verificar dependencias
# ---------------------------------------------------------------------------
check_deps() {
  local missing=()
  for cmd in docker curl; do
    command -v "$cmd" &>/dev/null || missing+=("$cmd")
  done

  # docker compose (plugin) o docker-compose (standalone)
  if ! docker compose version &>/dev/null 2>&1 && ! command -v docker-compose &>/dev/null; then
    missing+=("docker-compose")
  fi

  if [[ ${#missing[@]} -gt 0 ]]; then
    error "Faltan dependencias: ${missing[*]}"
    error "Instálalas antes de continuar."
    exit 1
  fi
}

compose_cmd() {
  if docker compose version &>/dev/null 2>&1; then
    docker compose "$@"
  else
    docker-compose "$@"
  fi
}

# ---------------------------------------------------------------------------
# Leer una variable de un .env existente (por si ya está configurado)
# ---------------------------------------------------------------------------
env_get() {
  local file="$1" key="$2"
  grep -E "^${key}=" "$file" 2>/dev/null | head -1 | cut -d'=' -f2- || true
}

# ---------------------------------------------------------------------------
# Pedir valor al usuario con fallback al valor actual
# ---------------------------------------------------------------------------
ask() {
  local prompt="$1"
  local current="$2"
  local secret="${3:-no}"
  local value

  if [[ "$secret" == "yes" ]]; then
    read -rsp "  ${prompt} [${current:-(vacío)}]: " value
    echo
  else
    read -rp "  ${prompt} [${current:-(vacío)}]: " value
  fi

  echo "${value:-$current}"
}

# Solo pregunta si el valor está vacío; si ya tiene valor, lo reutiliza en silencio.
ask_if_empty() {
  local prompt="$1"
  local current="$2"
  local secret="${3:-no}"

  if [[ -n "$current" ]]; then
    echo "$current"
    return
  fi

  ask "$prompt" "" "$secret"
}

# ---------------------------------------------------------------------------
# Crear / actualizar .env raíz (para docker-compose.yml)
# ---------------------------------------------------------------------------
# TCGDesk no consume APIs externas en runtime: el catálogo y los precios se
# ingieren en batch desde MTGJSON con `php bin/tcgdesk`, sin clave ninguna. Por
# eso aquí solo hay OAuth y contraseñas de MySQL, y no las seis claves de API
# que pide el dev-setup.sh de LibraryVue.
setup_root_env() {
  local google_client_id mysql_root_password mysql_password

  google_client_id=$(env_get "$ENV_FILE"     GOOGLE_CLIENT_ID)
  mysql_root_password=$(env_get "$ENV_FILE"  MYSQL_ROOT_PASSWORD)
  mysql_password=$(env_get "$ENV_FILE"       MYSQL_PASSWORD)

  # Detectar si falta alguna clave antes de mostrar el bloque interactivo
  local needs_input=false
  [[ -z "$google_client_id" || -z "$mysql_root_password" || -z "$mysql_password" ]] && needs_input=true

  if [[ "$needs_input" == "true" ]]; then
    info "Faltan claves en .env — solo se pedirán las vacías."
    echo ""
    echo -e "${YELLOW}=== Autenticación ===${NC}"
    google_client_id=$(ask_if_empty "Google OAuth Client ID" "$google_client_id")

    echo ""
    echo -e "${YELLOW}=== Contraseñas MySQL ===${NC}"
    mysql_root_password=$(ask_if_empty "MySQL Root Password" "${mysql_root_password:-rootpass_dev}" "yes")
    mysql_password=$(ask_if_empty      "MySQL User Password" "${mysql_password:-devpassword}"       "yes")
  else
    success "Todas las claves ya están configuradas en .env — sin cambios."
    return
  fi

  cat > "$ENV_FILE" <<EOF
# Docker Compose Environment Variables — DESARROLLO
# Generado por dev-setup.sh el $(date '+%Y-%m-%d %H:%M:%S')

# Google OAuth
GOOGLE_CLIENT_ID=${google_client_id}

# Database
MYSQL_ROOT_PASSWORD=${mysql_root_password}
MYSQL_PASSWORD=${mysql_password}
DB_PASSWORD=${mysql_password}
EOF

  success ".env raíz creado/actualizado."
}

# ---------------------------------------------------------------------------
# Helper: escribe o actualiza una clave en un .env file
# ---------------------------------------------------------------------------
env_set() {
  local file="$1" key="$2" value="$3"
  [[ -z "$value" ]] && return
  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    echo "${key}=${value}" >> "$file"
  fi
}

# ---------------------------------------------------------------------------
# Crear/sincronizar backend/.env.docker-development
# ---------------------------------------------------------------------------
setup_backend_env() {
  # El backend aún no existe hasta M2; sin plantilla no hay nada que sincronizar.
  if [[ ! -f "$ROOT_DIR/backend/.env.docker-development.example" ]]; then
    warn "backend/.env.docker-development.example no existe todavía — saltando."
    return
  fi

  if [[ ! -f "$BACKEND_ENV_FILE" ]]; then
    info "Creando backend/.env.docker-development desde el ejemplo..."
    cp "$ROOT_DIR/backend/.env.docker-development.example" "$BACKEND_ENV_FILE"
    warn "Revisa $BACKEND_ENV_FILE y ajusta JWT_SECRET si es necesario."
  fi

  env_set "$BACKEND_ENV_FILE" DB_PASSWORD      "$(env_get "$ENV_FILE" MYSQL_PASSWORD)"
  env_set "$BACKEND_ENV_FILE" MYSQL_PASSWORD   "$(env_get "$ENV_FILE" MYSQL_PASSWORD)"
  env_set "$BACKEND_ENV_FILE" GOOGLE_CLIENT_ID "$(env_get "$ENV_FILE" GOOGLE_CLIENT_ID)"

  success "backend/.env.docker-development sincronizado."
}

# ---------------------------------------------------------------------------
# Build y arranque de contenedores
# ---------------------------------------------------------------------------
start_services() {
  local rebuild="${1:-no}"
  cd "$ROOT_DIR"

  if [[ "$rebuild" == "yes" ]]; then
    info "Eliminando contenedores y volúmenes existentes..."
    compose_cmd down -v --remove-orphans 2>/dev/null || true
    info "Rebuilding imágenes Docker (sin caché)..."
    compose_cmd build --no-cache
  else
    # Si las imágenes ya existen localmente, saltamos el build.
    # El código fuente se sirve vía volúmenes montados, no necesita rebuild para cambios de código.
    # Usa --reset para forzar reconstrucción (p.ej. al cambiar dependencias en composer.json/package.json).
    if docker image inspect tcgdesk-backend:latest &>/dev/null \
       && docker image inspect tcgdesk-frontend:latest &>/dev/null; then
      info "Imágenes Docker ya existen — saltando build. Usa --reset para reconstruir."
    else
      info "Construyendo imágenes Docker..."
      compose_cmd build
    fi
  fi

  info "Arrancando servicios (MySQL, Backend, Frontend)..."
  compose_cmd up -d

  # Esperar a que MySQL esté listo
  info "Esperando a que MySQL esté disponible..."
  local retries=30
  until compose_cmd exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null; do
    retries=$((retries - 1))
    if [[ $retries -le 0 ]]; then
      error "MySQL no respondió a tiempo. Revisa los logs: ./dev-setup.sh --logs"
      exit 1
    fi
    sleep 2
  done
  success "MySQL listo."

  # Esperar a que el backend esté disponible.
  # Se comprueba con la acción `ping`, NO con un GET a index.php: un GET sin
  # acción devuelve 400 por diseño, y `curl -sf` lo trata como fallo — el
  # chequeo heredado de LibraryVue nunca pasaba y siempre acababa en el warn.
  info "Esperando a que el backend esté disponible..."
  retries=30
  until curl -sf -X POST http://127.0.0.1:8899/index.php \
          -H 'Content-Type: application/json' \
          -d '{"action":"ping"}' -o /dev/null 2>/dev/null; do
    retries=$((retries - 1))
    if [[ $retries -le 0 ]]; then
      warn "Backend no respondió a la acción 'ping'. Revisa: ./dev-setup.sh --logs"
      break
    fi
    sleep 2
  done
  success "Backend listo."

  echo ""
  success "=============================================="
  success " TCGDesk está corriendo en desarrollo"
  success "=============================================="
  echo ""
  echo -e "  ${GREEN}Frontend:${NC} http://localhost:8094"
  echo -e "  ${GREEN}Backend:${NC}  http://localhost:8899"
  echo -e "  ${GREEN}MySQL:${NC}    localhost:3312  (user: tcgdesk_user)"
  echo ""
  echo -e "  Logs:   ${BLUE}./dev-setup.sh --logs${NC}"
  echo -e "  Parar:  ${BLUE}./dev-setup.sh --stop${NC}"
  echo -e "  Reset:  ${BLUE}./dev-setup.sh --reset${NC}"
  echo ""
}

# ---------------------------------------------------------------------------
# Verificar dependencias móviles (nvm/node, Android SDK, Java)
# ---------------------------------------------------------------------------
check_deps_mobile() {
  local missing=()

  # nvm
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  # shellcheck source=/dev/null
  [[ -s "$NVM_DIR/nvm.sh" ]] && . "$NVM_DIR/nvm.sh"

  command -v node &>/dev/null || missing+=("node/nvm")
  command -v npm  &>/dev/null || missing+=("npm")

  # Android SDK (ANDROID_HOME o ruta por defecto)
  ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"
  if [[ ! -d "$ANDROID_HOME" ]]; then
    missing+=("Android SDK (esperado en $ANDROID_HOME)")
  fi

  # Java 17
  if ! command -v java &>/dev/null; then
    missing+=("java")
  else
    local java_ver
    java_ver=$(java -version 2>&1 | head -1 | grep -oP '"\K[0-9]+')
    if [[ "$java_ver" -lt 17 ]]; then
      warn "Java $java_ver detectado. Se recomienda Java 17 para Android."
    fi
  fi

  if [[ ${#missing[@]} -gt 0 ]]; then
    error "Faltan dependencias para móvil: ${missing[*]}"
    error "Instálalas y vuelve a intentarlo."
    exit 1
  fi
}

# ---------------------------------------------------------------------------
# Crear frontend/.env.mobile (si no existe o si el usuario quiere actualizar)
# ---------------------------------------------------------------------------
MOBILE_ENV_FILE="$ROOT_DIR/frontend/.env.mobile"
SECRETS_XML="$ROOT_DIR/frontend/android/app/src/main/res/values/secrets.xml"

setup_mobile_env() {
  local update="no"
  if [[ -f "$MOBILE_ENV_FILE" ]]; then
    read -rp "frontend/.env.mobile ya existe. ¿Actualizar? (s/N): " update
  fi

  if [[ ! -f "$MOBILE_ENV_FILE" ]] || [[ "$update" =~ ^[sS]$ ]]; then
    info "Configurando frontend/.env.mobile..."

    local api_url google_client_id
    api_url=$(env_get "$MOBILE_ENV_FILE"          VUE_APP_API_URL)
    google_client_id=$(env_get "$MOBILE_ENV_FILE" VUE_APP_GOOGLE_CLIENT_ID)

    # Fallbacks desde .env raíz
    [[ -z "$google_client_id" ]] && google_client_id=$(env_get "$ENV_FILE" GOOGLE_CLIENT_ID)

    echo ""
    echo -e "${YELLOW}=== Configuración móvil ===${NC}"
    warn "VUE_APP_API_URL usa 10.0.2.2 para emulador Android (= localhost del host)."
    api_url=$(ask_if_empty    "VUE_APP_API_URL"        "${api_url:-http://10.0.2.2:8899/index.php}")
    google_client_id=$(ask_if_empty "Google OAuth Client ID" "$google_client_id")

    cat > "$MOBILE_ENV_FILE" <<EOF
# Mobile environment — Capacitor / Android
# Generado por dev-setup.sh el $(date '+%Y-%m-%d %H:%M:%S')
# NUNCA commitear este archivo

VUE_APP_API_URL=${api_url}
VUE_APP_MODE=mobile
VUE_APP_GOOGLE_CLIENT_ID=${google_client_id}
EOF
    success "frontend/.env.mobile creado/actualizado."
  fi

  # secrets.xml — Google OAuth Client ID para el plugin nativo
  local google_client_id
  google_client_id=$(env_get "$MOBILE_ENV_FILE" VUE_APP_GOOGLE_CLIENT_ID)

  if [[ ! -f "$SECRETS_XML" ]]; then
    info "Creando android/app/.../secrets.xml..."
    mkdir -p "$(dirname "$SECRETS_XML")"
    cat > "$SECRETS_XML" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <!-- Google OAuth client ID (web application type) -->
    <string name="server_client_id">${google_client_id}</string>
</resources>
EOF
    success "secrets.xml creado."

    # Añadir a .gitignore si no está ya
    local gitignore="$ROOT_DIR/.gitignore"
    if [[ -f "$gitignore" ]] && ! grep -q 'secrets.xml' "$gitignore"; then
      printf '\n# Android secrets (OAuth client ID)\nfrontend/android/app/src/main/res/values/secrets.xml\n' >> "$gitignore"
      success "secrets.xml añadido a .gitignore."
    fi
  else
    success "secrets.xml ya existe — sin cambios."
  fi
}

# ---------------------------------------------------------------------------
# Build Capacitor + sync Android
# ---------------------------------------------------------------------------
cmd_mobile() {
  check_deps_mobile
  setup_mobile_env

  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  # shellcheck source=/dev/null
  [[ -s "$NVM_DIR/nvm.sh" ]] && . "$NVM_DIR/nvm.sh"

  cd "$ROOT_DIR/frontend"

  info "Instalando dependencias npm (si es necesario)..."
  npm install --silent

  info "Compilando app móvil (npm run build:mobile)..."
  npm run build:mobile
  success "Build móvil completado."

  info "Sincronizando con proyecto Android (cap sync)..."
  npx cap sync android
  success "Sync completado."

  echo ""
  success "============================================"
  success " App Android lista para ejecutar"
  success "============================================"
  echo -e "  Emulador (10.0.2.2) apunta a: ${GREEN}http://localhost:8899${NC}"
  echo ""
  echo -e "  Asegúrate de que el backend web está corriendo:"
  echo -e "    ${BLUE}./dev-setup.sh${NC}  (en otra terminal si no está levantado)"
  echo ""
  read -rp "  ¿Abrir Android Studio ahora? (s/N): " open_studio
  if [[ "$open_studio" =~ ^[sS]$ ]]; then
    info "Abriendo Android Studio..."
    npx cap open android
  else
    echo -e "  Para abrir más tarde: ${BLUE}cd frontend && npx cap open android${NC}"
  fi
  echo ""
}

# ---------------------------------------------------------------------------
# Flujos principales
# ---------------------------------------------------------------------------
cmd_stop() {
  cd "$ROOT_DIR"
  info "Deteniendo contenedores..."
  compose_cmd down
  success "Contenedores detenidos."
}

cmd_logs() {
  cd "$ROOT_DIR"
  compose_cmd logs -f
}

cmd_start() {
  check_deps

  if [[ ! -f "$ENV_FILE" ]]; then
    warn "No se encontró el archivo .env raíz. Se pedirán todas las claves."
  fi
  setup_root_env

  setup_backend_env
  start_services "no"
}

cmd_reset() {
  check_deps
  warn "RESET: se eliminarán contenedores y volúmenes Docker (incluida la BD)."
  read -rp "¿Continuar? (s/N): " confirm
  [[ "$confirm" =~ ^[sS]$ ]] || { info "Cancelado."; exit 0; }

  setup_root_env
  setup_backend_env
  start_services "yes"
}

cmd_migrate() {
  check_deps
  info "Aplicando migraciones de base de datos pendientes..."
  "$ROOT_DIR/docker/database/run_migrations.sh"
}

# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
case "$MODE" in
  start)   cmd_start   ;;
  reset)   cmd_reset   ;;
  stop)    cmd_stop    ;;
  logs)    cmd_logs    ;;
  mobile)  cmd_mobile  ;;
  migrate) cmd_migrate ;;
esac
