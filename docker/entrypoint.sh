#!/bin/sh
set -e
cd /var/www/html

# Package manifest + env-dependent caches happen at container start (composer
# ran with --no-scripts at build time, so discovery never ran there).
php artisan package:discover || true
php artisan config:cache
php artisan route:cache || true
php artisan storage:link || true

# Opt-in migration on boot (test server); on Azure prefer a release job.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
