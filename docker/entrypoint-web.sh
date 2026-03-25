#!/bin/sh
set -e

# Remove conflicting MPM modules, keep only prefork
rm -f /etc/apache2/mods-enabled/mpm_event.load \
      /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load \
      /etc/apache2/mods-enabled/mpm_worker.conf

# Railway assigns a dynamic PORT; make Apache listen on it
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
    sed -i "s/*:80/*:$PORT/" /etc/apache2/sites-enabled/*.conf
fi

mkdir -p /var/www/html/runtime /var/www/html/public/upload
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

exec "$@"
