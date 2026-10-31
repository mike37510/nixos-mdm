#!/usr/bin/env bash
# Archive de release Linux MDM (code + dump SQL, hors store Nix).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION" 2>/dev/null || echo 'unknown')"
OUT_DIR="$ROOT/storage/backups"
NAME="linux-mdm-v${VERSION}-${STAMP}"
STAGE="$OUT_DIR/$NAME"
OUT="$OUT_DIR/${NAME}.tar.gz"

mkdir -p "$OUT_DIR"
rm -rf "$STAGE"
mkdir -p "$STAGE/app"

read_php_string() {
  local key="$1" file="$2"
  sed -n "s/.*'${key}'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" "$file" | head -1
}

echo "==> Dump MySQL (si possible)"
DB_HOST="" DB_PORT="3306" DB_NAME="" DB_USER="" DB_PASS=""
if [[ -f "$ROOT/config.local.php" ]]; then
  DSN="$(read_php_string dsn "$ROOT/config.local.php")"
  DB_USER="$(read_php_string user "$ROOT/config.local.php")"
  DB_PASS="$(read_php_string password "$ROOT/config.local.php")"
  [[ "$DSN" =~ host=([^;]+) ]] && DB_HOST="${BASH_REMATCH[1]}"
  [[ "$DSN" =~ port=([^;]+) ]] && DB_PORT="${BASH_REMATCH[1]}"
  [[ "$DSN" =~ dbname=([^;]+) ]] && DB_NAME="${BASH_REMATCH[1]}"
fi

dump_ok=0
SQL_NAME="database-${DB_NAME:-mdm}.sql"
if [[ -n "${DB_HOST:-}" && -n "${DB_NAME:-}" && -n "${DB_USER:-}" ]]; then
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx nixvm-db; then
    # Sans --routines : évite l’erreur mysql.proc (versions MariaDB hétérogènes).
    if docker exec -e MYSQL_PWD="$DB_PASS" nixvm-db mariadb-dump \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
        --single-transaction --skip-ssl \
        "$DB_NAME" > "$STAGE/$SQL_NAME" 2>/tmp/linux-mdm-mysqldump.err; then
      echo "    SQL (via nixvm-db) : $(wc -c < "$STAGE/$SQL_NAME") octets"
      dump_ok=1
    else
      echo "    Avertissement dump SQL :" >&2
      cat /tmp/linux-mdm-mysqldump.err 2>/dev/null >&2 || true
      rm -f "$STAGE/$SQL_NAME"
    fi
  fi
else
  echo "    Pas de dump SQL (credentials indisponibles)"
fi

echo "==> Copie des fichiers applicatifs → $STAGE/app"
tar -C "$ROOT" -cf - \
  --exclude='./data/nix' \
  --exclude='./data/mysql' \
  --exclude='./storage/backups' \
  --exclude='./storage/nix-bin' \
  --exclude='./storage/nix-builds' \
  --exclude='./storage/builds' \
  --exclude='./storage/cache' \
  --exclude='./storage/home' \
  --exclude='./.git' \
  --exclude='*.ova' \
  --exclude='*.qcow2' \
  --exclude='*.vmdk' \
  . | tar -C "$STAGE/app" -xf -

printf '%s\n' "$VERSION" > "$STAGE/VERSION"
{
  echo "product=linux-mdm"
  echo "version=$VERSION"
  echo "created_at=$STAMP"
  echo "includes_sql=$dump_ok"
} > "$STAGE/MANIFEST.txt"

echo "==> Archive $OUT"
tar -czf "$OUT" -C "$OUT_DIR" "$(basename "$STAGE")"
rm -rf "$STAGE"

SIZE="$(du -h "$OUT" | awk '{print $1}')"
echo "OK — $(basename "$OUT") ($SIZE)"
echo "$OUT"
