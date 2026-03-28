#!/bin/sh
set -e

# Remove conflicting MPM modules, keep only prefork
rm -f /etc/apache2/mods-enabled/mpm_event.load \
      /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load \
      /etc/apache2/mods-enabled/mpm_worker.conf

# Railway assigns a dynamic PORT; make Apache listen on it
if [ -n "$PORT" ]; then
    sed -ri "s/^Listen [0-9]+$/Listen $PORT/" /etc/apache2/ports.conf
    for conf in /etc/apache2/sites-enabled/*.conf /etc/apache2/sites-available/*.conf; do
        [ -f "$conf" ] || continue
        sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$PORT>/g" "$conf"
    done
fi

# ── Generate ThinkPHP .env from Railway environment variables ──
ENV_FILE="/var/www/html/.env"
echo "# Auto-generated from Railway env vars at $(date -u)" > "$ENV_FILE"
echo "APP_DEBUG = ${APP_DEBUG:-true}" >> "$ENV_FILE"

# Resolve DB host/port/user/pass — user-set DATABASE_* takes priority over
# Railway auto-injected MYSQL* to avoid "railway" default DB overriding "curve_1"
DB_HOST="${DATABASE_HOSTNAME:-${MYSQLHOST:-127.0.0.1}}"
DB_NAME="${DATABASE_DATABASE:-${MYSQLDATABASE:-curve_1}}"
DB_USER="${DATABASE_USERNAME:-${MYSQLUSER:-root}}"
DB_PASS="${DATABASE_PASSWORD:-${MYSQLPASSWORD:-}}"
DB_PORT="${DATABASE_HOSTPORT:-${MYSQLPORT:-3306}}"

KLINE_HOST="${KLINE_DB_HOST:-$DB_HOST}"
KLINE_NAME="${KLINE_DB_NAME:-curve_2}"
KLINE_USER="${KLINE_DB_USER:-$DB_USER}"
KLINE_PASS="${KLINE_DB_PASS:-$DB_PASS}"
KLINE_PORT="${KLINE_DB_PORT:-$DB_PORT}"

REDIS_H="${REDIS_HOST:-${REDISHOST:-127.0.0.1}}"
REDIS_P="${REDIS_PORT:-${REDISPORT:-6379}}"
REDIS_PW="${REDIS_PASSWORD:-${REDISPASSWORD:-}}"

echo "" >> "$ENV_FILE"
echo "[DATABASE]" >> "$ENV_FILE"
echo "TYPE = mysql" >> "$ENV_FILE"
echo "DRIVER = mysql" >> "$ENV_FILE"
echo "HOSTNAME = ${DB_HOST}" >> "$ENV_FILE"
echo "DATABASE = ${DB_NAME}" >> "$ENV_FILE"
echo "USERNAME = ${DB_USER}" >> "$ENV_FILE"
echo "PASSWORD = ${DB_PASS}" >> "$ENV_FILE"
echo "HOSTPORT = ${DB_PORT}" >> "$ENV_FILE"
echo "CHARSET = utf8" >> "$ENV_FILE"
echo "PREFIX = fox_" >> "$ENV_FILE"
echo "KLINE_TYPE = mysql" >> "$ENV_FILE"
echo "KLINE_HOSTNAME = ${KLINE_HOST}" >> "$ENV_FILE"
echo "KLINE_DATABASE = ${KLINE_NAME}" >> "$ENV_FILE"
echo "KLINE_USERNAME = ${KLINE_USER}" >> "$ENV_FILE"
echo "KLINE_PASSWORD = ${KLINE_PASS}" >> "$ENV_FILE"
echo "KLINE_HOSTPORT = ${KLINE_PORT}" >> "$ENV_FILE"

echo "" >> "$ENV_FILE"
echo "[REDIS]" >> "$ENV_FILE"
echo "HOST = ${REDIS_H}" >> "$ENV_FILE"
echo "PORT = ${REDIS_P}" >> "$ENV_FILE"
echo "PASSWORD = ${REDIS_PW}" >> "$ENV_FILE"

if [ -n "$ADMIN_ALIAS" ]; then
    echo "" >> "$ENV_FILE"
    echo "[FFADMIN]" >> "$ENV_FILE"
    echo "ADMIN = ${ADMIN_ALIAS:-fox}" >> "$ENV_FILE"
fi

echo "========== Generated .env =========="
cat "$ENV_FILE"
echo "===================================="

# ── Auto-create databases if they don't exist ──
if command -v mysql >/dev/null 2>&1; then
    echo "[entrypoint] Checking if databases exist..."
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e \
        "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null && \
        echo "[entrypoint] Database '${DB_NAME}' OK" || \
        echo "[entrypoint] WARN: Could not ensure database '${DB_NAME}' exists (may need manual creation)"
    mysql -h"$KLINE_HOST" -P"$KLINE_PORT" -u"$KLINE_USER" -p"$KLINE_PASS" -e \
        "CREATE DATABASE IF NOT EXISTS \`${KLINE_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null && \
        echo "[entrypoint] Database '${KLINE_NAME}' OK" || \
        echo "[entrypoint] WARN: Could not ensure database '${KLINE_NAME}' exists (may need manual creation)"
else
    echo "[entrypoint] mysql client not installed, skipping DB check"
fi

mkdir -p /var/www/html/runtime /var/www/html/public/upload
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

exec "$@"
