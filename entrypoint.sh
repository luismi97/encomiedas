#!/bin/bash
# Crea los directorios de cache/compilados que Laravel necesita en runtime.
mkdir -p storage/app/public storage/framework/views storage/framework/cache/data \
         storage/framework/sessions storage/logs bootstrap/cache

chmod -R 777 storage bootstrap/cache

# Instala dependencias si el contenedor arranca sin vendor/ (primer build).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-dev --optimize-autoloader --no-progress
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
    php artisan key:generate --ansi --force
fi

# Espera a que la base de datos acepte conexiones antes de migrar.
if [ "$1" = "apache2-foreground" ]; then
    for i in $(seq 1 30); do
        php artisan db:show > /dev/null 2>&1 && break
        echo "Esperando la base de datos... ($i)"
        sleep 2
    done

    php artisan migrate --force || true
    php artisan storage:link || true

    php artisan config:cache || php artisan config:clear || true
    php artisan route:cache  || php artisan route:clear  || true
    php artisan view:cache   || true
fi

exec "$@"
