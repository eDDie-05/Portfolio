#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
    *[!0-9]*|'')
        printf '%s\n' 'PORT must contain only digits.' >&2
        exit 1
        ;;
esac

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/10000/${port}/g" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown www-data:www-data storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

php artisan migrate --force

if [ -n "${HRMS_INITIAL_ADMIN_NAME:-}" ] && [ -n "${HRMS_INITIAL_ADMIN_EMAIL:-}" ] && [ -n "${HRMS_INITIAL_ADMIN_PASSWORD:-}" ]; then
    php artisan hrms:create-admin --no-interaction
fi

php artisan config:cache

exec "$@"
