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

# Permission, role, dan akun awal. Tanpa ini deploy tetap hijau tapi tidak ada
# satu pun permission di basis data, sehingga tiap endpoint ber-gate menolak
# semua orang dan tidak ada user yang bisa dibuat untuk memperbaikinya --
# image ini tidak memasang sshd, jadi tidak ada konsol untuk menambalnya.
#
# Seeder-nya idempoten (firstOrCreate/syncPermissions), jadi RUN_SEED yang
# tertinggal menyala tidak merusak apa pun. Akun hanya lahir kalau
# VMS_ADMIN_EMAIL dan VMS_ADMIN_PASSWORD keduanya terisi.
if [ "${RUN_SEED:-0}" = "1" ]; then
    php artisan db:seed --force --class='Database\Seeders\RoleAndPermissionSeeder'
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
