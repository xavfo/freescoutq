#!/usr/bin/env bash
# ============================================================
# respTickets.sh - Respaldo y restauración del sistema de tickets
#                  (FreeScout / QChat) de qsoftware.biz
# ------------------------------------------------------------
#   Origen  : /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz
#   Destino : /home/resp/tickets
#
# Instalación:
#   sudo cp docs/.scripts/respTickets.sh /home/resp/respTickets.sh
#   sudo chmod +x /home/resp/respTickets.sh
#
# Uso:
#   sudo bash /home/resp/respTickets.sh                      # respaldo
#   sudo bash /home/resp/respTickets.sh backup               # idem
#   sudo bash /home/resp/respTickets.sh list                 # lista respaldos
#   sudo bash /home/resp/respTickets.sh verify [STAMP]       # comprueba integridad
#   sudo bash /home/resp/respTickets.sh restore [STAMP]      # restaura (confirmación)
#   sudo bash /home/resp/respTickets.sh restore STAMP --yes --keep-env
#   sudo bash /home/resp/respTickets.sh prune                # aplica rotación
#
# Contenido del respaldo: UN ÚNICO fichero en /home/resp/tickets/
#   <YYYYMMDD_HHMMSS>.tar           (pre_restore_<STAMP>.tar si es de seguridad)
# que en su interior contiene:
#   db.sql.gz      volcado de la base de datos (estructura + datos)
#   files.tar.gz   árbol del proyecto (sin .git, node_modules, logs ni cachés)
#   manifest.txt   origen, fecha, commit, tamaños y conteos
#   SHA256SUMS     sumas de verificación de los ficheros anteriores
#
# Al terminar cada respaldo se ejecuta, desde /home/resp:
#   ./ftpupload.sh tickets/<nombre>.tar
# (desactivable con --no-upload; si la subida falla, el respaldo local sigue
# siendo válido y sólo se registra un aviso).
#
# Rotación: conserva las últimas ${KEEP} copias (configurable abajo) y, de los
# respaldos de seguridad pre_restore_*, únicamente el más reciente.
#
# Cron sugerido (diario 02:10):
#   10 2 * * * /usr/bin/env bash /home/resp/respTickets.sh backup >> /var/log/respTickets.log 2>&1
#
# Restauración (acción "restore"):
#   1. Verifica SHA256SUMS y la integridad de los archivos.
#   2. Crea un respaldo de seguridad del estado actual (prefijo pre_restore_).
#   3. Pone la aplicación en mantenimiento (php artisan down).
#   4. Recrea la base de datos (DROP + CREATE utf8mb4) e importa el volcado.
#   5. Extrae los ficheros sobre el proyecto y restaura propietario/permisos.
#   6. Limpia cachés, reinicia la cola y vuelve a poner la aplicación en línea.
#   - Con --keep-env conserva el .env actual en lugar del que trae el respaldo.
#   - Si el .env actual no tiene credenciales de base de datos (sitio caído), se
#     usan las del .env que viaja dentro del respaldo.
#
# Notas:
#   - Requiere cliente mysql/mariadb, tar y gzip; root para leer el .env y chown.
#   - La contraseña de la base se lee de $SRC/.env y se pasa mediante un fichero
#     temporal de opciones (chmod 600); nunca por la línea de comandos.
#   - La restauración sobrescribe ficheros, pero no elimina los añadidos después
#     del respaldo (adjuntos nuevos, módulos instalados, etc.).
# ============================================================
set -Eeuo pipefail

# ---- Configuración -------------------------------------------------------
SRC="/var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz"   # proyecto en producción
BASE="/home/resp/tickets"                                   # destino de los respaldos
BACKUP_ROOT="$BASE/"                                        # donde viven los respaldos
ENV_FILE="$SRC/.env"                                        # credenciales de la base
KEEP=7                                                      # número de respaldos a conservar
LOG="$BACKUP_ROOT/respTickets.log"
FTP_UPLOAD="/home/resp/ftpupload.sh"                        # subida del respaldo por FTP
FTP_REMOTE_DIR="tickets"                                    # destino remoto: tickets/<nombre>
BACKUP_EXT="tar"                                           # extensión del fichero de respaldo
UPLOAD=1                                                    # 0 = no subir (--no-upload)

BACKUP_ROOT="${BACKUP_ROOT%/}"                               # sin barra final al componer rutas

# Rutas del árbol de ficheros que NO se respaldan
TAR_EXCLUDES=(
  --exclude='./.git'
  --exclude='./node_modules'
  --exclude='./storage/logs/*'
  --exclude='./storage/framework/cache/*'
  --exclude='./storage/framework/sessions/*'
  --exclude='./storage/framework/views/*'
  --exclude='./bootstrap/cache/*.php'
  --exclude='./.env.bak*'
)

ACTION="backup"
STAMP=""
ASSUME_YES=0
KEEP_ENV=0

MYSQL_CNF=""
TMP_ENV=""
TMP_ENV_DIR=""
TMP_BAK_DIR=""
PHP_BIN="$(command -v php || true)"

# ---- Utilidades ----------------------------------------------------------
log() {
  local msg
  msg="$(printf '[%s] %s' "$(date '+%Y-%m-%d %H:%M:%S')" "$*")"
  printf '%s\n' "$msg"
  if [[ -w "$BACKUP_ROOT" ]]; then
    printf '%s\n' "$msg" >>"$LOG" 2>/dev/null || true
  fi
}

die()  { log "ERROR: $*"; exit 1; }
warn() { log "AVISO: $*"; }

cleanup() {
  [[ -n "${MYSQL_CNF:-}" && -f "$MYSQL_CNF" ]] && rm -f "$MYSQL_CNF"
  [[ -n "${TMP_ENV:-}" && -f "$TMP_ENV" ]] && rm -f "$TMP_ENV"
  [[ -n "${TMP_ENV_DIR:-}" && -d "$TMP_ENV_DIR" ]] && rm -rf "$TMP_ENV_DIR"
  [[ -n "${TMP_BAK_DIR:-}" && -d "$TMP_BAK_DIR" ]] && rm -rf "$TMP_BAK_DIR"
  return 0
}
trap cleanup EXIT

usage() {
  cat <<'EOF'
Uso: respTickets.sh [acción] [STAMP] [opciones]

Acciones:
  backup            Respalda base de datos + ficheros (acción por defecto).
  restore [STAMP]   Restaura un respaldo (por defecto el más reciente).
  list              Lista los respaldos disponibles.
  verify [STAMP]    Verifica SHA256SUMS e integridad de un respaldo.
  prune             Aplica la rotación (conserva KEEP).

STAMP es el nombre del respaldo con formato YYYYMMDD_HHMMSS
(la extensión .tar es opcional).

Opciones:
  -y, --yes         No pedir confirmación al restaurar.
      --keep-env    Al restaurar, conservar el .env actual.
      --no-upload   No ejecutar ftpupload.sh al terminar el respaldo.
  -h, --help        Muestra esta ayuda.
EOF
}

# ---- Lectura del .env ----------------------------------------------------
env_get() {
  local key="$1" file="$2" line val
  [[ -r "$file" ]] || return 1
  line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -n 1)" || return 1
  [[ -n "$line" ]] || return 1
  val="${line#*=}"
  val="$(printf '%s' "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  if [[ ${#val} -ge 2 ]]; then
    case "$val" in
      \"*\") val="${val:1:${#val}-2}" ;;
      \'*\') val="${val:1:${#val}-2}" ;;
    esac
  fi
  printf '%s' "$val"
}

write_mycnf() {
  local esc="${DB_PASS//\\/\\\\}"
  esc="${esc//\"/\\\"}"
  MYSQL_CNF="$(mktemp "${TMPDIR:-/tmp}/respTickets.XXXXXX.cnf")"
  chmod 600 "$MYSQL_CNF"
  {
    printf '[client]\n'
    printf 'user=%s\n' "$DB_USER"
    printf 'password="%s"\n' "$esc"
    printf 'host=%s\n' "$DB_HOST"
    printf 'port=%s\n' "$DB_PORT"
    printf 'protocol=TCP\n'
  } >"$MYSQL_CNF"
}

load_db() {
  local ver
  [[ -r "$ENV_FILE" ]] || die "No puedo leer $ENV_FILE (¿ejecutar como root?)"

  DB_NAME="$(env_get DB_DATABASE "$ENV_FILE" || true)"
  DB_USER="$(env_get DB_USERNAME "$ENV_FILE" || true)"
  DB_PASS="$(env_get DB_PASSWORD "$ENV_FILE" || true)"
  DB_HOST="$(env_get DB_HOST     "$ENV_FILE" || true)"
  DB_PORT="$(env_get DB_PORT     "$ENV_FILE" || true)"
  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="${DB_PORT:-3306}"
  if [[ "$DB_HOST" == "localhost" ]]; then
    DB_HOST="127.0.0.1"
  fi

  [[ -n "$DB_NAME" ]] || die "DB_DATABASE no definido en $ENV_FILE"
  [[ -n "$DB_USER" ]] || die "DB_USERNAME no definido en $ENV_FILE"

  MYSQL_BIN="$(command -v mysql || command -v mariadb || true)"
  DUMP_BIN="$(command -v mysqldump || command -v mariadb-dump || true)"
  [[ -n "$MYSQL_BIN" ]] || die "No encuentro el cliente mysql/mariadb"
  [[ -n "$DUMP_BIN"  ]] || die "No encuentro mysqldump/mariadb-dump"

  [[ -n "$MYSQL_CNF" && -f "$MYSQL_CNF" ]] || write_mycnf

  ver="$("$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" -N -B -e 'SELECT VERSION();')" \
    || die "No puedo conectar a MySQL con las credenciales de $ENV_FILE"
  if [[ "$ver" == *MariaDB* ]]; then FLAVOR="mariadb"; else FLAVOR="mysql"; fi
  log "Servidor $FLAVOR: $ver | base de datos: $DB_NAME"
}

dump_opts() {
  DUMP_OPTS=(
    --single-transaction --quick --routines --triggers --events
    --default-character-set=utf8mb4
  )
  if [[ "$FLAVOR" == "mysql" ]]; then
    DUMP_OPTS+=(--no-tablespaces --set-gtid-purged=OFF)
  fi
}

# ---- Acciones ------------------------------------------------------------
write_manifest() {
  local dir="$1" commit size_db size_files nfiles
  commit="$(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || echo 'n/d')"
  size_db="$(du -h "$dir/db.sql.gz" | cut -f1)"
  size_files="$(du -h "$dir/files.tar.gz" | cut -f1)"
  nfiles="$(tar -tzf "$dir/files.tar.gz" | wc -l)"
  {
    echo "proyecto=tickets.qsoftware.biz (FreeScout / QChat)"
    echo "origen=$SRC"
    echo "destino=$BASE"
    echo "fecha=$(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "host=$(hostname)"
    echo "commit=$commit"
    echo "db_motor=$FLAVOR"
    echo "db_host=$DB_HOST:$DB_PORT"
    echo "db_nombre=$DB_NAME"
    echo "db_tamano=$size_db"
    echo "ficheros_tamano=$size_files"
    echo "ficheros_entradas=$nfiles"
    echo "excluidos=.git node_modules storage/logs storage/framework/{cache,sessions,views} bootstrap/cache"
  } >"$dir/manifest.txt"
}

do_backup() {
  local prefix="${1:-}" file ts
  ts="$(date +%Y%m%d_%H%M%S)"
  file="$BACKUP_ROOT/${prefix}${ts}.$BACKUP_EXT"
  [[ -e "$file" ]] && die "Ya existe el fichero de respaldo: $file"

  load_db
  dump_opts

  # Área de trabajo en el mismo sistema de ficheros que el respaldo
  TMP_BAK_DIR="$BACKUP_ROOT/.tmp-${prefix}${ts}"
  mkdir -p "$TMP_BAK_DIR"
  chmod 700 "$TMP_BAK_DIR"
  log "== Respaldo iniciado: $file"

  log "Volcando base de datos $DB_NAME ..."
  if ! "$DUMP_BIN" --defaults-extra-file="$MYSQL_CNF" "${DUMP_OPTS[@]}" "$DB_NAME" \
        | gzip -9 >"$TMP_BAK_DIR/db.sql.gz"; then
    die "Falló el volcado de la base de datos"
  fi

  log "Empaquetando ficheros de $SRC ..."
  if ! tar -czf "$TMP_BAK_DIR/files.tar.gz" -C "$SRC" "${TAR_EXCLUDES[@]}" . ; then
    die "Falló el empaquetado de ficheros"
  fi

  write_manifest "$TMP_BAK_DIR" || warn "No se pudo escribir manifest.txt"
  ( cd "$TMP_BAK_DIR" && sha256sum db.sql.gz files.tar.gz manifest.txt >SHA256SUMS )
  chmod 600 "$TMP_BAK_DIR"/* 2>/dev/null || true

  log "Creando el fichero único de respaldo ..."
  if ! tar -cf "$file" -C "$TMP_BAK_DIR" ./db.sql.gz ./files.tar.gz ./manifest.txt ./SHA256SUMS; then
    rm -f "$file"
    die "Falló la creación del fichero de respaldo"
  fi
  chmod 600 "$file"
  rm -rf "$TMP_BAK_DIR"
  TMP_BAK_DIR=""

  log "== Respaldo completado: $(du -h "$file" | cut -f1) en $file"
  upload_backup "$(basename "$file")"
}

# Ejecuta /home/resp/ftpupload.sh tickets/<nombre-del-respaldo> desde /home/resp,
# igual que se hace a mano en el servidor. Un fallo de subida no invalida el
# respaldo local: sólo se registra un aviso.
upload_backup() {
  local name="$1" dir base remote
  remote="$FTP_REMOTE_DIR/$name"
  dir="$(dirname "$FTP_UPLOAD")"
  base="$(basename "$FTP_UPLOAD")"
  if [[ "$UPLOAD" -ne 1 ]]; then
    log "-- Subida FTP omitida (--no-upload): $remote"
    return 0
  fi
  if [[ ! -f "$FTP_UPLOAD" ]]; then
    warn "No existe $FTP_UPLOAD; se omite la subida de $remote"
    return 0
  fi
  log "-- Subiendo respaldo con ./$base $remote (desde $dir) ..."
  if ( cd "$dir" && bash "$base" "$remote" ); then
    log "-- Subida FTP completada: $remote"
  else
    warn "La subida FTP de $remote falló; el respaldo local sigue siendo válido"
  fi
  return 0
}

latest_backup() {
  local f
  f="$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type f -name "*.$BACKUP_EXT" -printf '%f\n' 2>/dev/null \
        | grep -E "^[0-9]{8}_[0-9]{6}\\.$BACKUP_EXT$" | sort | tail -n 1)" || true
  printf '%s' "$f"
}

resolve_backup() {
  local name="$1" file
  if [[ -z "$name" ]]; then
    file="$(latest_backup)"
    [[ -n "$file" ]] || die "No hay respaldos en $BACKUP_ROOT"
    file="$BACKUP_ROOT/$file"
  else
    case "$name" in
      *."$BACKUP_EXT") file="$BACKUP_ROOT/$name" ;;
      *)                file="$BACKUP_ROOT/$name.$BACKUP_EXT" ;;
    esac
  fi
  [[ -f "$file" ]] || die "No existe el respaldo: $file"
  printf '%s' "$file"
}

do_list() {
  local name f
  printf 'Respaldos en %s\n' "$BACKUP_ROOT"
  printf '%-34s %8s  %s\n' "RESPALDO" "TAMAÑO" "FECHA"
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type f -name "*.$BACKUP_EXT" -printf '%f\n' 2>/dev/null \
    | grep -E "^(pre_restore_)?[0-9]{8}_[0-9]{6}\\.$BACKUP_EXT$" | sort -r \
    | while read -r name; do
        f="$BACKUP_ROOT/$name"
        printf '%-34s %8s  %s\n' "$name" "$(du -h "$f" | cut -f1)" "$(date -r "$f" '+%Y-%m-%d %H:%M')"
      done
}

do_verify() {
  local file dir f
  file="$(resolve_backup "$STAMP")"
  log "Verificando $file"
  [[ -s "$file" ]] || die "Falta o está vacío: $file"
  tar -tf "$file" >/dev/null 2>&1 || die "El fichero de respaldo está corrupto"

  TMP_BAK_DIR="$(mktemp -d "$BACKUP_ROOT/.unpack.XXXXXX")"
  dir="$TMP_BAK_DIR"
  tar -xf "$file" -C "$dir" || die "No se pudo extraer $file"

  for f in db.sql.gz files.tar.gz manifest.txt; do
    [[ -s "$dir/$f" ]] || die "Falta o está vacío dentro del respaldo: $f"
  done
  gzip -t "$dir/db.sql.gz"            || die "db.sql.gz está corrupto"
  tar -tzf "$dir/files.tar.gz" >/dev/null || die "files.tar.gz está corrupto"
  if [[ -f "$dir/SHA256SUMS" ]]; then
    ( cd "$dir" && sha256sum -c SHA256SUMS ) || die "Las sumas SHA256 no coinciden"
  else
    warn "El respaldo no tiene SHA256SUMS; se omite la comprobación de sumas"
  fi
  log "OK: el respaldo es íntegro"
}

do_restore() {
  local file dir web_user web_group ans
  file="$(resolve_backup "$STAMP")"
  log "== Restauración desde: $file"
  do_verify                        # deja el contenido extraído en $TMP_BAK_DIR
  dir="$TMP_BAK_DIR"

  log "Se reemplazará la base de datos y los ficheros de $SRC"
  if [[ "$ASSUME_YES" -ne 1 ]]; then
    read -r -p "Escribe 'si' para continuar: " ans || ans=""
    [[ "$ans" == "si" ]] || die "Cancelado por el usuario"
  fi

  web_user="$(stat -c '%U' "$SRC")"
  web_group="$(stat -c '%G' "$SRC")"

  # Si el .env actual no tiene credenciales válidas (sitio caído o .env perdido)
  # se leen las del .env que viaja dentro del respaldo.
  if [[ -z "$(env_get DB_DATABASE "$ENV_FILE" || true)" || -z "$(env_get DB_USERNAME "$ENV_FILE" || true)" ]]; then
    warn "El .env actual no tiene credenciales de base de datos: se usan las del respaldo"
    TMP_ENV_DIR="$(mktemp -d "${TMPDIR:-/tmp}/respTickets.env.XXXXXX")"
    tar -xzf "$dir/files.tar.gz" -C "$TMP_ENV_DIR" ./.env || die "El respaldo no contiene .env"
    ENV_FILE="$TMP_ENV_DIR/.env"
  fi

  log "-- Respaldo de seguridad del estado actual ..."
  if ! ( do_backup "pre_restore_" ); then
    warn "No se pudo crear el respaldo de seguridad; se continúa con la restauración"
  fi

  # Se recargan credenciales: el respaldo no debe depender del .env restaurado
  load_db

  if [[ "$KEEP_ENV" -eq 1 && -f "$SRC/.env" ]]; then
    TMP_ENV="$(mktemp "${TMPDIR:-/tmp}/respTickets.env.XXXXXX")"
    cp -p "$SRC/.env" "$TMP_ENV"
    log "-- Se conservará el .env actual (--keep-env)"
  fi

  if [[ -n "$PHP_BIN" ]]; then
    ( cd "$SRC" && "$PHP_BIN" artisan down --message="Restauración en curso" ) \
      || warn "No se pudo activar el modo mantenimiento"
  fi

  log "-- Recreando base de datos $DB_NAME ..."
  "$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" -e \
    "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    || die "No se pudo recrear la base de datos"

  log "-- Importando db.sql.gz ..."
  if ! gzip -dc "$dir/db.sql.gz" \
        | "$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" \
            --default-character-set=utf8mb4 "$DB_NAME"; then
    die "Falló la importación del volcado (la aplicación sigue en mantenimiento)"
  fi

  log "-- Restaurando ficheros en $SRC ..."
  tar -xzf "$dir/files.tar.gz" -C "$SRC" --no-same-owner \
    || die "Falló la extracción de ficheros"

  if [[ -n "$TMP_ENV" && -f "$TMP_ENV" ]]; then
    cp -p "$TMP_ENV" "$SRC/.env"
    rm -f "$TMP_ENV"
    TMP_ENV=""
  fi

  log "-- Ajustando propietario ($web_user:$web_group) y permisos ..."
  chown -R "$web_user:$web_group" "$SRC"
  chmod -R ug+rwX "$SRC"
  find "$SRC/storage" "$SRC/bootstrap/cache" -type d -exec chmod 775 {} + 2>/dev/null || true
  chmod 600 "$SRC/.env" 2>/dev/null || true

  if [[ -n "$PHP_BIN" ]]; then
    ( cd "$SRC" && "$PHP_BIN" artisan optimize:clear )      || warn "optimize:clear falló"
    ( cd "$SRC" && "$PHP_BIN" artisan freescout:clear-cache ) || warn "freescout:clear-cache falló"
    ( cd "$SRC" && "$PHP_BIN" artisan queue:restart )       || warn "queue:restart falló"
    ( cd "$SRC" && "$PHP_BIN" artisan up )                  || warn "No se pudo salir del mantenimiento"
  fi

  log "== Restauración completada. Comprueba el sitio: https://tickets.qsoftware.biz"
}

do_prune() {
  local i=0 j=0 name
  local list=()
  local pres=()

  # Restos de ejecuciones interrumpidas (más de un día): no son respaldos
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d \
    \( -name '.tmp-*' -o -name '.unpack.*' \) -mtime +1 \
    -exec rm -rf {} + 2>/dev/null || true

  while read -r name; do
    if [[ -n "$name" ]]; then
      list+=("$name")
    fi
  done < <(
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type f -name "*.$BACKUP_EXT" -printf '%f\n' 2>/dev/null \
      | grep -E "^[0-9]{8}_[0-9]{6}\\.$BACKUP_EXT$" | sort -r
  )

  # Los respaldos de seguridad previos a una restauración no cuentan para KEEP:
  # se conserva únicamente el más reciente.
  while read -r name; do
    if [[ -n "$name" ]]; then
      pres+=("$name")
    fi
  done < <(
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type f -name "*.$BACKUP_EXT" -printf '%f\n' 2>/dev/null \
      | grep -E "^pre_restore_[0-9]{8}_[0-9]{6}\\.$BACKUP_EXT$" | sort -r
  )
  for name in "${pres[@]:-}"; do
    if [[ -z "$name" ]]; then
      continue
    fi
    j=$((j + 1))
    if [[ "$j" -gt 1 ]]; then
      log "Rotación: eliminando $name (pre-restauración antigua)"
      rm -f "${BACKUP_ROOT:?}/$name"
    fi
  done

  if [[ ${#list[@]} -eq 0 ]]; then
    log "No hay respaldos que rotar"
    return 0
  fi

  for name in "${list[@]}"; do
    i=$((i + 1))
    if [[ "$i" -gt "$KEEP" ]]; then
      log "Rotación: eliminando $name"
      rm -f "${BACKUP_ROOT:?}/$name"
    fi
  done

  local kept="$i"
  if [[ "$kept" -gt "$KEEP" ]]; then
    kept="$KEEP"
  fi
  log "Rotación completada: $kept de ${#list[@]} respaldos conservados (KEEP=$KEEP)"
}

# ---- Punto de entrada ----------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    backup|restore|list|verify|prune) ACTION="$1" ;;
    -y|--yes)     ASSUME_YES=1 ;;
    --keep-env)   KEEP_ENV=1 ;;
    --no-upload)  UPLOAD=0 ;;
    -h|--help)    usage; exit 0 ;;
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]) STAMP="$1" ;;
    *) die "Argumento no reconocido: $1 (usa --help)" ;;
  esac
  shift
done

[[ $EUID -eq 0 ]] || warn "Sin privilegios de root: leer $ENV_FILE y aplicar chown puede fallar"
[[ -d "$SRC" ]]   || die "No existe el directorio origen: $SRC"

mkdir -p "$BACKUP_ROOT"
chmod 755 "$BASE" "$BACKUP_ROOT" 2>/dev/null || true

case "$ACTION" in
  backup)  do_backup; do_prune ;;
  restore) do_restore ;;
  list)    do_list ;;
  verify)  do_verify ;;
  prune)   do_prune ;;
esac