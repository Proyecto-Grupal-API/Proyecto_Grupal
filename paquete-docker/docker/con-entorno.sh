#!/bin/sh
# Ejecuta una orden con la configuración de /config/campus.env cargada tal cual (una variable por línea, sin
# interpretar comillas ni símbolos: hay valores JSON). Es la misma forma en que el laboratorio pasaba la configuración.
#   /docker/con-entorno.sh php artisan campus:install --check
set -e
if [ -f /config/campus.env ]; then
  while IFS= read -r linea || [ -n "$linea" ]; do
    case "$linea" in ''|'#'*) continue ;; esac
    nombre=${linea%%=*}; valor=${linea#*=}
    case "$nombre" in *[!A-Za-z0-9_]*|'') continue ;; esac
    export "$nombre=$valor"
  done < /config/campus.env
fi
exec "$@"
