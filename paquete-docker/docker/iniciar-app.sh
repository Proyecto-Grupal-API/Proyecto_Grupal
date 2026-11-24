#!/bin/sh
# Proceso principal del contenedor «app». Prepara el almacenamiento y, cuando la instalación ya está hecha
# (marca /storage/.campus-instalado), arranca los tres procesos de la aplicación como en el laboratorio:
#   servidor web de varios procesos (los módulos se llaman entre sí por HTTP), cola «comunidad» y planificador.
# Si un proceso de fondo se cae, se vuelve a lanzar.
mkdir -p /storage/framework/cache/data /storage/framework/sessions /storage/framework/views /storage/logs \
         /storage/app/public /storage/app/private
chmod -R 777 /storage 2>/dev/null

if [ ! -f /storage/.campus-instalado ]; then
  echo "[campus] Esperando la instalación (./campus.sh instalar)…"
  while [ ! -f /storage/.campus-instalado ]; do sleep 3; done
fi

echo "[campus] Arrancando la aplicación"
( while true; do /docker/con-entorno.sh php artisan queue:work comunidad --queue=comunidad --sleep=2 --tries=3; sleep 5; done ) &
( while true; do /docker/con-entorno.sh php artisan schedule:work; sleep 5; done ) &
cd /project/public
exec /docker/con-entorno.sh php -S 0.0.0.0:8080 /project/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
