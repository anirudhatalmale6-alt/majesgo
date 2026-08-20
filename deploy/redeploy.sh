#!/usr/bin/env bash
#
# Redespliegue de MajesGo en el servidor. Ejecutar SIEMPRE esto, no los comandos
# sueltos de memoria.
#
#   ssh root@167.99.14.42 'bash /var/www/majesgo/deploy/redeploy.sh'
#
# ⚠ El chown del final NO es opcional. Todo esto corre como root, así que los
# archivos que deja `view:cache` quedan de root; luego php-fpm (www-data) intenta
# recompilar una vista, el touch() le falla por permisos y la app entera responde
# 500. Pasó el 2026-08-20: se saltó el chown en un despliegue y /conductor y /app
# se cayeron. No se nota al desplegar porque el error aparece en la SIGUIENTE
# petición, no en el comando.
set -euo pipefail

cd /var/www/majesgo

echo "== git pull"
git pull

echo "== dependencias"
composer install --no-dev --optimize-autoloader

echo "== migraciones"
php artisan migrate --force

echo "== caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "== permisos (imprescindible)"
chown -R www-data:www-data storage bootstrap/cache

echo "== recargar php-fpm"
systemctl reload php8.3-fpm

echo "== comprobación"
for ruta in / /app /conductor /admin/login /privacidad; do
    codigo=$(curl -s -o /dev/null -w '%{http_code}' "https://majesgo.com${ruta}")
    printf '   %-14s %s\n' "$ruta" "$codigo"
    case "$codigo" in
        200|302) ;;
        *) echo "   ⚠ ${ruta} respondió ${codigo} — revisar storage/logs/laravel.log"; exit 1 ;;
    esac
done

echo "== listo: $(git log --oneline -1)"
