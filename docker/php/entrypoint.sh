#!/bin/sh
# Entrypoint del contenedor PHP. Es idempotente: se puede reiniciar sin efectos
# destructivos. No borra datos ni ejecuta seeders automáticamente.
set -e

cd /var/www

# Directorios de runtime que Laravel necesita (no se versionan en Git).
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/framework/testing storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

if [ ! -f .env ]; then
    echo "[campus] .env no existe; copiando desde .env.example"
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    echo "[campus] Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[campus] Generando APP_KEY"
    php artisan key:generate --force
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[campus] Ejecutando migraciones (no destructivas)"
    php artisan migrate --force || echo "[campus] AVISO: las migraciones fallaron; revisar conexión a MongoDB"
fi

exec "$@"
