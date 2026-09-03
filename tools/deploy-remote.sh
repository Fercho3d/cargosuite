#!/usr/bin/env bash
#
# Despliegue de FregoCargo en el servidor. Se ejecuta EN el servidor, como
# `ubuntu`, con el paquete ya copiado en /tmp/frego-laravel.tar.gz.
#
#   ssh frego 'bash -s' < tools/deploy-remote.sh
#
# Existe porque los dos primeros despliegues manuales tumbaron el sitio por
# permisos: quien escribe en `storage` tiene que ser SIEMPRE el proceso web.
set -euo pipefail

APP=/var/www/html/frego-laravel
PAQUETE=/tmp/frego-laravel.tar.gz
PHP=php8.4

# Los comandos que escriben en storage/ o bootstrap/cache van como www-data.
# Si se corren como ubuntu, dejan archivos que el proceso web no puede
# reescribir después y la aplicación truena al expirar la caché.
artisan_web() { sudo -u www-data "$PHP" "$APP/artisan" "$@"; }

cd "$APP"

[[ -f "$PAQUETE" ]] || { echo "Falta $PAQUETE"; exit 1; }

echo "==> Directorios de escritura"
# El paquete NO trae storage/ ni bootstrap/cache: son del proceso web y tar
# fallaría al intentar tocarles el modo. Se crean aquí si es una instalación nueva.
sudo mkdir -p storage/framework/{cache/data,sessions,views} storage/logs \
             storage/app/{public,private} bootstrap/cache

echo "==> Código"
# El código lo posee ubuntu; el proceso web solo necesita leerlo.
sudo find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -exec chown ubuntu:www-data {} +
tar xzf "$PAQUETE"
rm -f "$PAQUETE"

echo "==> Dependencias"
"$PHP" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Migraciones"
artisan_web migrate --force

echo "==> Cachés de framework"
artisan_web cache:clear
artisan_web config:cache
artisan_web route:cache
artisan_web view:cache

echo "==> Permisos"
# Código: solo lectura para el proceso web.
sudo find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -exec chown ubuntu:www-data {} +
sudo find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -type d -exec chmod 750 {} +
sudo find . -path ./storage -prune -o -path ./bootstrap/cache -prune -o -type f -exec chmod 640 {} +
sudo chmod 750 artisan
# Escritura: dueño el proceso web, y setgid para que lo nuevo herede el grupo.
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 2775 storage bootstrap/cache

echo "==> Apache"
sudo apache2ctl configtest
sudo systemctl reload apache2

echo "==> Verificación"
for ruta in / /login /transacciones; do
    printf '    %-18s %s\n' "$ruta" "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:9000$ruta")"
done
printf '    %-18s %s\n' "Frego :80" "$(curl -s -o /dev/null -w '%{http_code}' http://localhost/)"
printf '    %-18s %s\n' "portal :8080" "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/)"

errores=$(grep -c ERROR "$APP/storage/logs/laravel.log" 2>/dev/null || echo 0)
echo "    errores en el log: $errores"

echo "==> Listo"
