#!/bin/sh
set -e

mkdir -p /var/www/html/runtime /var/www/html/public/upload
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

exec "$@"
