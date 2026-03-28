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

# Trim optional surrounding single/double quotes in env values.
# This prevents malformed values when users paste env vars like "value".
trim_quotes() {
    val="$1"
    case "$val" in
        \"*\") val="${val#\"}"; val="${val%\"}" ;;
    esac
    case "$val" in
        \''*\') val="${val#\'}"; val="${val%\'}" ;;
    esac
    printf '%s' "$val"
}

# ── Generate ThinkPHP .env from Railway environment variables ──
ENV_FILE="/var/www/html/.env"
echo "# Auto-generated from Railway env vars at $(date -u)" > "$ENV_FILE"
echo "APP_DEBUG = ${APP_DEBUG:-true}" >> "$ENV_FILE"

# Main MySQL database
if [ -n "$MYSQLHOST" ] || [ -n "$DATABASE_HOSTNAME" ]; then
    DB_HOST="$(trim_quotes "${MYSQLHOST:-${DATABASE_HOSTNAME:-127.0.0.1}}")"
    DB_NAME="$(trim_quotes "${MYSQLDATABASE:-${DATABASE_DATABASE:-curve_1}}")"
    DB_USER="$(trim_quotes "${MYSQLUSER:-${DATABASE_USERNAME:-root}}")"
    DB_PASS="$(trim_quotes "${MYSQLPASSWORD:-${DATABASE_PASSWORD:-}}")"
    DB_PORT="$(trim_quotes "${MYSQLPORT:-${DATABASE_HOSTPORT:-3306}}")"

    # Compatible with both KLINE_DATABASE and legacy KLINE_DB_NAME styles.
    KLINE_DB_HOST_VAL="$(trim_quotes "${KLINE_DB_HOST:-$DB_HOST}")"
    KLINE_DB_NAME_VAL="$(trim_quotes "${KLINE_DATABASE:-${KLINE_DB_NAME:-curve_2}}")"
    KLINE_DB_USER_VAL="$(trim_quotes "${KLINE_DB_USER:-$DB_USER}")"
    KLINE_DB_PASS_VAL="$(trim_quotes "${KLINE_DB_PASS:-$DB_PASS}")"
    KLINE_DB_PORT_VAL="$(trim_quotes "${KLINE_DB_PORT:-$DB_PORT}")"

    echo "" >> "$ENV_FILE"
    echo "[DATABASE]" >> "$ENV_FILE"
    echo "TYPE = mysql" >> "$ENV_FILE"
    echo "HOSTNAME = ${DB_HOST}" >> "$ENV_FILE"
    echo "DATABASE = ${DB_NAME}" >> "$ENV_FILE"
    echo "USERNAME = ${DB_USER}" >> "$ENV_FILE"
    echo "PASSWORD = ${DB_PASS}" >> "$ENV_FILE"
    echo "HOSTPORT = ${DB_PORT}" >> "$ENV_FILE"
    echo "CHARSET = utf8" >> "$ENV_FILE"
    echo "PREFIX = fox_" >> "$ENV_FILE"
    # Kline database (same host, different db name)
    echo "KLINE_TYPE = mysql" >> "$ENV_FILE"
    echo "KLINE_HOSTNAME = ${KLINE_DB_HOST_VAL}" >> "$ENV_FILE"
    echo "KLINE_DATABASE = ${KLINE_DB_NAME_VAL}" >> "$ENV_FILE"
    echo "KLINE_USERNAME = ${KLINE_DB_USER_VAL}" >> "$ENV_FILE"
    echo "KLINE_PASSWORD = ${KLINE_DB_PASS_VAL}" >> "$ENV_FILE"
    echo "KLINE_HOSTPORT = ${KLINE_DB_PORT_VAL}" >> "$ENV_FILE"
fi

# Redis
if [ -n "$REDISHOST" ] || [ -n "$REDIS_HOST" ]; then
    REDIS_HOST_VAL="$(trim_quotes "${REDISHOST:-${REDIS_HOST:-127.0.0.1}}")"
    REDIS_PORT_VAL="$(trim_quotes "${REDISPORT:-${REDIS_PORT:-6379}}")"
    REDIS_PASS_VAL="$(trim_quotes "${REDISPASSWORD:-${REDIS_PASSWORD:-}}")"

    echo "" >> "$ENV_FILE"
    echo "[REDIS]" >> "$ENV_FILE"
    echo "HOST = ${REDIS_HOST_VAL}" >> "$ENV_FILE"
    echo "PORT = ${REDIS_PORT_VAL}" >> "$ENV_FILE"
    echo "PASSWORD = ${REDIS_PASS_VAL}" >> "$ENV_FILE"
fi

# Admin alias
if [ -n "$ADMIN_ALIAS" ]; then
    echo "" >> "$ENV_FILE"
    echo "[FFADMIN]" >> "$ENV_FILE"
    echo "ADMIN = ${ADMIN_ALIAS:-fox}" >> "$ENV_FILE"
fi

echo "--- .env generated ---"
cat "$ENV_FILE"
echo "---"

mkdir -p /var/www/html/runtime /var/www/html/public/upload
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

exec "$@"
