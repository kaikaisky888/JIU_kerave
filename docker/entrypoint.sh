#!/bin/sh
set -e

# Railway uses $PORT env var; default to 8080
export LISTEN_PORT=${PORT:-8080}

# === Resolve database connection from Railway env vars ===
# Priority: user-set DATABASE_* > Railway auto-injected MYSQL* > defaults
# NOTE: Railway template ${{MySQL.MYSQLHOST}} resolves into DATABASE_HOSTNAME at deploy time.
#       Railway MySQL plugin also auto-injects MYSQLHOST, MYSQLPORT, etc.
DB_HOSTNAME="${DATABASE_HOSTNAME:-${MYSQLHOST:-127.0.0.1}}"
DB_HOSTPORT="${DATABASE_HOSTPORT:-${MYSQLPORT:-3306}}"
DB_USERNAME="${DATABASE_USERNAME:-${MYSQLUSER:-root}}"
DB_PASSWORD="${DATABASE_PASSWORD:-${MYSQLPASSWORD:-}}"
DB_DATABASE="${DATABASE_DATABASE:-curve_1}"
DB_TYPE="${DATABASE_TYPE:-mysql}"
DB_DRIVER="${DATABASE_DRIVER:-mysql}"
DB_CHARSET="${DATABASE_CHARSET:-utf8}"
DB_PREFIX="${DATABASE_PREFIX:-fox_}"

# Kline database
KLINE_HOST="${KLINE_DB_HOST:-${DB_HOSTNAME}}"
KLINE_PORT="${KLINE_DB_PORT:-${DB_HOSTPORT}}"
KLINE_USER="${KLINE_DB_USER:-${DB_USERNAME}}"
KLINE_PASS="${KLINE_DB_PASS:-${DB_PASSWORD}}"
KLINE_NAME="${KLINE_DB_NAME:-curve_2}"

# Redis — user-set REDIS_* > Railway auto-injected REDISHOST/REDISPORT
R_HOST="${REDIS_HOST:-${REDISHOST:-127.0.0.1}}"
R_PORT="${REDIS_PORT:-${REDISPORT:-6379}}"
R_PASS="${REDIS_PASSWORD:-${REDISPASSWORD:-}}"

APP_DEBUG="${APP_DEBUG:-false}"

# === Generate Nginx config (replace listen port) ===
if [ -f "/etc/nginx/http.d/default.conf.template" ]; then
    envsubst '${LISTEN_PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf
fi

# === Generate ThinkPHP .env file ===
# config/database.php reads: Env::get('database.hostname'), Env::get('database.kline_hostname'), etc.
# config/cache.php reads:    Env::get('redis.host'), Env::get('redis.port'), Env::get('redis.password')
# All kline vars must be under [DATABASE] section as KLINE_* keys.
cat > /var/www/html/.env <<EOF
APP_DEBUG = ${APP_DEBUG}

[DATABASE]
TYPE = ${DB_TYPE}
DRIVER = ${DB_DRIVER}
HOSTNAME = ${DB_HOSTNAME}
HOSTPORT = ${DB_HOSTPORT}
DATABASE = ${DB_DATABASE}
USERNAME = ${DB_USERNAME}
PASSWORD = ${DB_PASSWORD}
CHARSET = ${DB_CHARSET}
PREFIX = ${DB_PREFIX}
KLINE_TYPE = mysql
KLINE_HOSTNAME = ${KLINE_HOST}
KLINE_HOSTPORT = ${KLINE_PORT}
KLINE_DATABASE = ${KLINE_NAME}
KLINE_USERNAME = ${KLINE_USER}
KLINE_PASSWORD = ${KLINE_PASS}

[REDIS]
HOST = ${R_HOST}
PORT = ${R_PORT}
PASSWORD = ${R_PASS}
EOF

chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env

# === Inject env vars into PHP-FPM so PHP processes can read them ===
if [ -f "/usr/local/etc/php-fpm.d/www.conf" ]; then
    # Quote all values to handle empty strings and special chars
    cat >> /usr/local/etc/php-fpm.d/www.conf <<FPMEOF

; --- Railway env vars ---
env[APP_DEBUG] = "${APP_DEBUG}"
env[DATABASE_TYPE] = "${DB_TYPE}"
env[DATABASE_DRIVER] = "${DB_DRIVER}"
env[DATABASE_HOSTNAME] = "${DB_HOSTNAME}"
env[DATABASE_HOSTPORT] = "${DB_HOSTPORT}"
env[DATABASE_DATABASE] = "${DB_DATABASE}"
env[DATABASE_USERNAME] = "${DB_USERNAME}"
env[DATABASE_PASSWORD] = "${DB_PASSWORD}"
env[DATABASE_CHARSET] = "${DB_CHARSET}"
env[DATABASE_PREFIX] = "${DB_PREFIX}"
env[KLINE_DB_HOST] = "${KLINE_HOST}"
env[KLINE_DB_PORT] = "${KLINE_PORT}"
env[KLINE_DB_USER] = "${KLINE_USER}"
env[KLINE_DB_PASS] = "${KLINE_PASS}"
env[KLINE_DB_NAME] = "${KLINE_NAME}"
env[REDIS_HOST] = "${R_HOST}"
env[REDIS_PORT] = "${R_PORT}"
env[REDIS_PASSWORD] = "${R_PASS}"
FPMEOF
fi

# === Create runtime directories ===
mkdir -p /var/www/html/runtime/admin \
         /var/www/html/runtime/cache \
         /var/www/html/runtime/index \
         /var/www/html/runtime/log \
         /var/www/html/runtime/mobile \
         /var/www/html/runtime/session \
         /var/www/html/public/upload
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

# === Auto-create and import databases ===
if command -v mysql >/dev/null 2>&1 && [ "$DB_HOSTNAME" != "127.0.0.1" ]; then
    echo "[entrypoint] Checking databases..."

    # Temporarily disable exit-on-error for DB operations
    set +e

    # Create curve_1
    mysql -h"$DB_HOSTNAME" -P"$DB_HOSTPORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
        -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>&1
    if [ $? -eq 0 ]; then
        echo "[entrypoint] Database '${DB_DATABASE}' exists/created"
        # Import curve_1.sql if DB is empty (no tables)
        TABLE_COUNT=$(mysql -h"$DB_HOSTNAME" -P"$DB_HOSTPORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
            -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}';" 2>/dev/null)
        if [ "$TABLE_COUNT" = "0" ] && [ -f "/var/www/html/curve_1.sql" ]; then
            echo "[entrypoint] Importing curve_1.sql..."
            mysql -h"$DB_HOSTNAME" -P"$DB_HOSTPORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
                "$DB_DATABASE" < /var/www/html/curve_1.sql 2>&1 && \
                echo "[entrypoint] curve_1.sql imported OK" || \
                echo "[entrypoint] WARN: curve_1.sql import failed"
        else
            echo "[entrypoint] Database '${DB_DATABASE}' has ${TABLE_COUNT} tables, skip import"
        fi
    else
        echo "[entrypoint] WARN: Could not create '${DB_DATABASE}'"
    fi

    # Create curve_2
    mysql -h"$KLINE_HOST" -P"$KLINE_PORT" -u"$KLINE_USER" -p"$KLINE_PASS" \
        -e "CREATE DATABASE IF NOT EXISTS \`${KLINE_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>&1
    if [ $? -eq 0 ]; then
        echo "[entrypoint] Database '${KLINE_NAME}' exists/created"
        TABLE_COUNT=$(mysql -h"$KLINE_HOST" -P"$KLINE_PORT" -u"$KLINE_USER" -p"$KLINE_PASS" \
            -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${KLINE_NAME}';" 2>/dev/null)
        if [ "$TABLE_COUNT" = "0" ] && [ -f "/var/www/html/curve_2.sql" ]; then
            echo "[entrypoint] Importing curve_2.sql (this may take a while)..."
            mysql -h"$KLINE_HOST" -P"$KLINE_PORT" -u"$KLINE_USER" -p"$KLINE_PASS" \
                "$KLINE_NAME" < /var/www/html/curve_2.sql 2>&1 && \
                echo "[entrypoint] curve_2.sql imported OK" || \
                echo "[entrypoint] WARN: curve_2.sql import failed"
        else
            echo "[entrypoint] Database '${KLINE_NAME}' has ${TABLE_COUNT} tables, skip import"
        fi
    else
        echo "[entrypoint] WARN: Could not create '${KLINE_NAME}'"
    fi

    # Re-enable exit-on-error
    set -e
fi

# === Verify PHP works ===
echo "[entrypoint] Testing PHP..."
php -r "echo 'PHP ' . PHP_VERSION . ' OK' . PHP_EOL;" || { echo "[FATAL] PHP broken!"; exit 1; }

php -r "
\$req = ['pdo_mysql','mysqli','redis','bcmath','pcntl','posix','sockets','gd','zip'];
\$miss = array_filter(\$req, function(\$e){ return !extension_loaded(\$e); });
if (\$miss) { echo 'MISSING: ' . implode(', ', \$miss) . PHP_EOL; exit(1); }
echo 'Extensions OK' . PHP_EOL;
" || { echo "[FATAL] Missing PHP extensions!"; exit 1; }

# Test PHP-FPM config validity
php-fpm -t 2>&1 || {
    echo "[FATAL] PHP-FPM config test failed! Showing injected env lines:"
    grep "^env\[" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null | head -25
    exit 1
}

# === Debug output (minimal to avoid Railway rate limit) ===
echo "[entrypoint] PORT=${LISTEN_PORT} DEBUG=${APP_DEBUG}"
echo "[entrypoint] DB=${DB_USERNAME}@${DB_HOSTNAME}:${DB_HOSTPORT}/${DB_DATABASE}"
echo "[entrypoint] Kline=${KLINE_USER}@${KLINE_HOST}:${KLINE_PORT}/${KLINE_NAME}"
echo "[entrypoint] Redis=${R_HOST}:${R_PORT}"
echo "[entrypoint] Starting services..."

exec "$@"
