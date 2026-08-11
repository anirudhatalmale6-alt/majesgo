#!/usr/bin/env bash
#
# Despliegue de MajesGo en el servidor de producción.
#
#   cd /var/www/majesgo && ./deploy.sh
#
# El orden importa. En particular route:cache: Laravel guarda las rutas en
# bootstrap/cache/routes-v7.php y, si no se regenera, cualquier ruta NUEVA no existe
# para la aplicación aunque esté en routes/web.php. Eso tumba con error 500 toda
# vista que la use (pasó el 11/08/2026 con el panel: el menú apuntaba a una ruta nueva).
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Código"
git pull --ff-only

echo "==> Dependencias"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Base de datos"
php artisan migrate --force

echo "==> Enlace de storage (public/storage no va en git)"
php artisan storage:link || true

echo "==> Cachés"
php artisan config:cache
php artisan route:cache      # imprescindible al agregar rutas
php artisan view:cache

echo "==> Permisos"
chown -R www-data:www-data storage bootstrap/cache

echo "==> PHP-FPM"
systemctl reload php8.3-fpm

echo "==> Comprobación"
for path in / /conductor /app /admin/login; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "https://majesgo.167.99.14.42.nip.io${path}")
    printf '    %-16s %s\n' "$path" "$code"
done

echo "==> Listo"
