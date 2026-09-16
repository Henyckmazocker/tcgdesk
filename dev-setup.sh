#!/usr/bin/env bash
# =============================================================================
# dev-setup.sh — TCGDesk: setup y arranque del entorno de desarrollo
# =============================================================================
# Uso:
#   ./dev-setup.sh          → setup interactivo (pide claves si faltan)
#   ./dev-setup.sh --reset  → recrea contenedores y volúmenes desde cero
#   ./dev-setup.sh --stop   → detiene todos los contenedores
#   ./dev-setup.sh --logs   → muestra logs en tiempo real
#   ./dev-setup.sh --mobile → compila el APK de debug de Android (Capacitor + Gradle)
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
      echo "  --mobile    .env.mobile + secrets.xml + build web + cap sync + APK de debug"
      echo "              No pregunta nada: corre entero sin intervención."
      echo "              Necesita un JDK/JBR 21 (lo busca solo); deja el APK en"
      echo "              frontend/android/app/build/outputs/apk/debug/app-debug.apk"
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
# Con `set -euo pipefail` un `read` que se queda sin stdin devuelve != 0 y
# ABORTA EL SCRIPT ENTERO en vez de caer al valor por defecto. Por eso aquí
# (y en todos los demás `read` de este fichero) se comprueba antes que haya
# terminal y el fallo del `read` nunca propaga.
ask() {
  local prompt="$1"
  local current="$2"
  local secret="${3:-no}"
  local value=""

  if [[ ! -t 0 ]]; then
    [[ -n "$current" ]] || warn "Sin terminal interactiva: '${prompt}' se queda vacío." >&2
    echo "$current"
    return
  fi

  if [[ "$secret" == "yes" ]]; then
    read -rsp "  ${prompt} [${current:-(vacío)}]: " value || true
    # A STDERR, y no es un capricho: `ask()` se llama SIEMPRE dentro de `$( )`,
    # así que todo lo que salga por stdout forma parte del valor devuelto. Con
    # un `echo` a secas, el salto de línea que cierra el prompt oculto se cuela
    # DELANTE de la contraseña —la sustitución de comandos solo se come los
    # saltos finales, no los iniciales— y acaba escrito así en el `.env`.
    echo >&2
  else
    read -rp "  ${prompt} [${current:-(vacío)}]: " value || true
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
# Descubrir un JDK/JBR 21 para Gradle
# ---------------------------------------------------------------------------
# `@capacitor/android` fija `JavaVersion.VERSION_21` en su build.gradle, así que
# `./gradlew assembleDebug` con el JDK 17 del host muere con
# `invalid source release: 21`. La ruta NO se codifica a fuego: Android Studio
# renombra su JBR en cada actualización y mañana no estaría donde la dejamos.
JAVA21_HOME=""
JAVA21_BUSCADO=()

# Versión mayor de un JAVA_HOME candidato ("" si no es un JDK usable).
java_major_of() {
  local home="$1" version=""
  [[ -n "$home" && -x "$home/bin/javac" ]] || return 1
  version=$(grep -E '^JAVA_VERSION=' "$home/release" 2>/dev/null | cut -d'"' -f2 || true)
  [[ -n "$version" ]] || version=$("$home/bin/java" -version 2>&1 | head -1 | grep -oP '"\K[0-9][^"]*' || true)
  [[ -n "$version" ]] || return 1
  echo "${version%%.*}"
}

# Deja la ruta en $JAVA21_HOME y devuelve 0; si no la encuentra, devuelve 1 y
# $JAVA21_BUSCADO lleva la lista literal de sitios donde miró.
find_java21() {
  JAVA21_HOME=""
  JAVA21_BUSCADO=()
  local -a candidates=()
  local dir entry

  # 1) El JAVA_HOME del entorno, si ya apunta a un 21.
  if [[ -n "${JAVA_HOME:-}" ]]; then
    JAVA21_BUSCADO+=("\$JAVA_HOME=$JAVA_HOME")
    candidates+=("$JAVA_HOME")
  else
    JAVA21_BUSCADO+=("\$JAVA_HOME (sin definir)")
  fi

  # 2) Los JBR que instala Android Studio y los JDK del sistema. De cada
  #    directorio se prueba también su subcarpeta jbr/ (Android Studio, Toolbox).
  for dir in "$HOME/.jdks" /usr/lib/jvm /opt "$HOME/.local/share/JetBrains/Toolbox/apps"; do
    JAVA21_BUSCADO+=("$dir/*  (y */jbr)")
    [[ -d "$dir" ]] || continue
    while IFS= read -r entry; do
      candidates+=("$entry")
      [[ -d "$entry/jbr" ]] && candidates+=("$entry/jbr")
    done < <(find "$dir" -maxdepth 1 -mindepth 1 \( -type d -o -type l \) 2>/dev/null | sort -Vr)
  done

  for dir in "$HOME/android-studio/jbr" /usr/local/android-studio/jbr; do
    JAVA21_BUSCADO+=("$dir")
    candidates+=("$dir")
  done

  for entry in "${candidates[@]}"; do
    if [[ "$(java_major_of "$entry" 2>/dev/null || true)" == "21" ]]; then
      JAVA21_HOME="$entry"
      return 0
    fi
  done
  return 1
}

# ---------------------------------------------------------------------------
# Verificar dependencias móviles (nvm/node, Android SDK, JDK 21)
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

  if [[ ${#missing[@]} -gt 0 ]]; then
    error "Faltan dependencias para móvil: ${missing[*]}"
    error "Instálalas y vuelve a intentarlo."
    exit 1
  fi

  # JDK 21 — se comprueba ANTES de npm y del build web para no descubrir que
  # falta después de tres minutos de trabajo.
  if ! find_java21; then
    error "No hay ningún JDK/JBR 21 y Gradle lo EXIGE:"
    error "  @capacitor/android fija JavaVersion.VERSION_21, así que compilar con"
    error "  el JDK 17 muere con 'invalid source release: 21'."
    error "Se buscó, por este orden, en:"
    local sitio
    for sitio in "${JAVA21_BUSCADO[@]}"; do
      error "    - $sitio"
    done
    error "Instala un JDK 21 (o el JBR que trae Android Studio), o exporta"
    error "JAVA_HOME apuntando a uno y vuelve a lanzar ./dev-setup.sh --mobile."
    exit 1
  fi
  success "JDK 21 para Gradle: $JAVA21_HOME"
}

# ---------------------------------------------------------------------------
# Crear frontend/.env.mobile (solo si no existe) y secrets.xml
# ---------------------------------------------------------------------------
MOBILE_ENV_FILE="$ROOT_DIR/frontend/.env.mobile"
SECRETS_XML="$ROOT_DIR/frontend/android/app/src/main/res/values/secrets.xml"

setup_mobile_env() {
  # Aquí vivía un `read -rp "¿Actualizar? (s/N)"` que, sin stdin y con
  # `set -e`, mataba el modo entero antes de compilar nada. El modo --mobile se
  # recorre una y otra vez para reconstruir el APK, así que la respuesta útil es
  # siempre la misma —«no, déjalo como está»— y ahora es la que se toma sola,
  # con el mismo criterio que secrets.xml justo debajo.
  if [[ -f "$MOBILE_ENV_FILE" ]]; then
    success "frontend/.env.mobile ya existe — sin cambios."
    info "  Para regenerarlo: bórralo y vuelve a lanzar ./dev-setup.sh --mobile"
  else
    info "Configurando frontend/.env.mobile..."

    local api_url google_client_id
    api_url=$(env_get "$MOBILE_ENV_FILE"          VITE_API_URL)
    google_client_id=$(env_get "$MOBILE_ENV_FILE" VITE_GOOGLE_CLIENT_ID)

    # Fallbacks desde .env raíz
    [[ -z "$google_client_id" ]] && google_client_id=$(env_get "$ENV_FILE" GOOGLE_CLIENT_ID)

    echo ""
    echo -e "${YELLOW}=== Configuración móvil ===${NC}"
    warn "VITE_API_URL usa 10.0.2.2 para emulador Android (= localhost del host)."
    api_url=$(ask_if_empty    "VITE_API_URL"           "${api_url:-http://10.0.2.2:8899/index.php}")
    google_client_id=$(ask_if_empty "Google OAuth Client ID" "$google_client_id")

    cat > "$MOBILE_ENV_FILE" <<EOF
# Mobile environment — Capacitor / Android
# Generado por dev-setup.sh el $(date '+%Y-%m-%d %H:%M:%S')
# NUNCA commitear este archivo

# El modo ya no es una variable: lo pasa vite build --mode mobile a vite.config.js
VITE_API_URL=${api_url}
VITE_GOOGLE_CLIENT_ID=${google_client_id}
EOF
    success "frontend/.env.mobile creado/actualizado."
  fi

  # secrets.xml — Google OAuth Client ID para el plugin nativo
  local google_client_id
  google_client_id=$(env_get "$MOBILE_ENV_FILE" VITE_GOOGLE_CLIENT_ID)

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

  # --legacy-peer-deps NO es opcional: @codetrix-studio/capacitor-google-auth
  # declara un peer de @capacitor/core@^6 y este repo va por el 8, así que
  # `npm install` a secas revienta con ERESOLVE. Y NADA de --silent: se tragaba
  # el mensaje de error y el fallo aparecía sin causa visible.
  info "Instalando dependencias npm (--legacy-peer-deps)..."
  if ! npm install --legacy-peer-deps; then
    error "npm install falló. El motivo está justo arriba, sin filtrar."
    error "Si es un ERESOLVE, mira los peers de frontend/package.json."
    exit 1
  fi
  success "Dependencias npm listas."

  info "Compilando app móvil (npm run build:mobile)..."
  if ! npm run build:mobile; then
    error "El build de Vite falló. El error de Vite está arriba, entero."
    exit 1
  fi
  success "Build móvil completado."

  info "Sincronizando con proyecto Android (cap sync)..."
  if ! npx cap sync android; then
    error "cap sync falló. Revisa el error de Capacitor de arriba."
    exit 1
  fi
  success "Sync completado."

  # El APK. Antes el modo se paraba en el sync y había que rodear a mano por
  # Android Studio o por un gradlew con el JAVA_HOME puesto a pulso.
  local android_dir="$ROOT_DIR/frontend/android"
  local apk="$android_dir/app/build/outputs/apk/debug/app-debug.apk"
  local antes_epoch
  antes_epoch=$(date +%s)

  info "Compilando APK de debug (gradlew assembleDebug)..."
  info "  JAVA_HOME=$JAVA21_HOME"
  if ! ( cd "$android_dir" && JAVA_HOME="$JAVA21_HOME" ./gradlew assembleDebug ); then
    error "Gradle falló al compilar el APK (JAVA_HOME=$JAVA21_HOME)."
    error "El error de Gradle sale completo arriba; si dice 'invalid source"
    error "release: 21', ese JAVA_HOME no es un JDK 21."
    exit 1
  fi

  if [[ ! -f "$apk" ]]; then
    error "Gradle terminó sin error pero NO hay APK en:"
    error "  $apk"
    exit 1
  fi
  # Gradle es incremental: si nada cambió deja el APK anterior intacto, y
  # callarlo sería mentir sobre lo que acaba de pasar.
  if [[ "$(stat -c '%Y' "$apk")" -lt "$antes_epoch" ]]; then
    warn "Gradle no encontró cambios: el APK es EL DE ANTES, no uno nuevo."
    warn "  Si esperabas uno nuevo, algo no llegó al proyecto Android."
  else
    success "APK recién compilado en esta ejecución."
  fi
  success "APK: $apk"
  success "  $(stat -c '%s bytes · %y' "$apk")"

  desplegar_en_movil "$apk"

  echo ""
  success "============================================"
  success " APK de debug listo"
  success "============================================"
  echo -e "  ${GREEN}$apk${NC}"
  echo ""
  echo -e "  Abrir en Android Studio: ${BLUE}cd frontend && npx cap open android${NC}"
  echo ""
  echo -e "  El APK filtra a ${YELLOW}arm64-v8a${NC}: dispositivo físico, no emulador x86."
  echo -e "  Y el backend tiene que estar levantado (${BLUE}./dev-setup.sh${NC})."
  echo ""
}

# ---------------------------------------------------------------------------
# Despliegue en el móvil: `adb reverse` + instalación
# ---------------------------------------------------------------------------
# Las dos cosas son OPCIONALES y NUNCA tumban el build: compilar un APK y
# tenerlo delante ya es el resultado del modo. Si no hay móvil, o no hay `adb`,
# se avisa y se sigue con exit 0 — un `--mobile` que falla porque el cable está
# desenchufado sería un mal comando.
#
# ELEGIR DISPOSITIVO ES LA PARTE DELICADA. Un mismo teléfono aparece DOS VECES
# en `adb devices` cuando está emparejado por Wi-Fi y por USB a la vez (una
# entrada `ip:puerto` y otra `adb-<serie>-XXXX._adb-tls-connect._tcp`), y ahí
# un `adb install` a secas muere con "more than one device". Así que:
#   · 1 dispositivo            -> ese
#   · varios, mismo `model:`   -> el primero, diciendo cuál (es el mismo aparato)
#   · varios, modelos DISTINTOS-> no se adivina: se listan y se dan los comandos
#   · ADB_SERIAL puesto        -> manda sobre todo lo anterior
desplegar_en_movil() {
  local apk="$1"

  if ! command -v adb >/dev/null 2>&1; then
    warn "No hay 'adb' en el PATH: APK sin instalar."
    warn "  Instálalo a mano: adb install -r $apk"
    return 0
  fi

  # Solo los que están en estado 'device': 'offline' y 'unauthorized' no sirven.
  local lineas
  lineas="$(adb devices -l 2>/dev/null | awk 'NR>1 && $2=="device"')"

  if [[ -z "$lineas" ]]; then
    warn "No hay ningún dispositivo conectado: APK sin instalar."
    warn "  Con el móvil enchufado y la depuración USB activa:"
    warn "    adb install -r $apk"
    return 0
  fi

  local serial=""
  local n
  n="$(printf '%s\n' "$lineas" | wc -l)"

  if [[ -n "${ADB_SERIAL:-}" ]]; then
    serial="$ADB_SERIAL"
    info "Usando ADB_SERIAL=$serial"
  elif [[ "$n" -eq 1 ]]; then
    serial="$(printf '%s\n' "$lineas" | awk '{print $1}')"
  else
    local modelos
    modelos="$(printf '%s\n' "$lineas" | grep -o 'model:[^ ]*' | sort -u | wc -l)"
    if [[ "$modelos" -eq 1 ]]; then
      serial="$(printf '%s\n' "$lineas" | head -1 | awk '{print $1}')"
      info "$n conexiones al mismo aparato (Wi-Fi y USB); uso $serial"
    else
      warn "Hay $n dispositivos DISTINTOS conectados y no voy a adivinar cuál:"
      printf '%s\n' "$lineas" | while read -r l; do warn "    $l"; done
      warn "  Elige uno y repite, o fija ADB_SERIAL:"
      warn "    ADB_SERIAL=<serie> ./dev-setup.sh --mobile"
      return 0
    fi
  fi

  # `adb reverse` NO persiste entre reinicios del móvil ni reconexiones del
  # cable, así que se repone en cada despliegue. Es idempotente.
  if adb -s "$serial" reverse tcp:8899 tcp:8899 >/dev/null 2>&1; then
    success "adb reverse tcp:8899 puesto (el móvil ve el backend en localhost)."
  else
    warn "No se pudo poner 'adb reverse tcp:8899': la app no verá el backend."
    warn "  A mano: adb -s $serial reverse tcp:8899 tcp:8899"
  fi

  info "Instalando el APK en $serial..."
  if adb -s "$serial" install -r "$apk" >/dev/null 2>&1; then
    success "APK instalado en $serial."
  else
    warn "Falló la instalación. El APK está compilado y se puede meter a mano:"
    warn "    adb -s $serial install -r $apk"
    warn "  Si se queja de firmas, desinstala antes: adb -s $serial uninstall com.tcgdesk.app"
  fi
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

  # Sin terminal no se puede confirmar algo que borra la BD, y tampoco se
  # cancela en silencio con código 0: el que llame se enteraría de nada.
  if [[ ! -t 0 ]]; then
    error "--reset exige confirmación interactiva y no hay terminal. No se ha tocado nada."
    exit 1
  fi

  local confirm=""
  read -rp "¿Continuar? (s/N): " confirm || true
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
