#!/usr/bin/env bash
# Campus Digital E1–E7 — manejo del paquete de pruebas (candidata 6710e59). Se ejecuta DENTRO de WSL2 (Ubuntu),
# con Docker Desktop encendido. Guía completa: GUIA-WINDOWS-WSL2-DOCKER.md
#
#   ./campus.sh instalar        primera vez: prepara, crea las bases, instala con las cuentas de demostración y arranca
#   ./campus.sh iniciar         arrancar después de apagar el equipo
#   ./campus.sh detener         detener sin perder datos
#   ./campus.sh estado          qué está en marcha y si la aplicación responde
#   ./campus.sh acceso          dirección y contraseña de las cuentas demo (sólo en tu pantalla)
#   ./campus.sh registros       ver lo que está haciendo la aplicación (Ctrl+C para salir)
#   ./campus.sh borrar-todo     borra TODOS los datos de esta instalación (pide confirmación)
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"
DIR=$(pwd); CFG=config/campus.env; LOCAL=.env
IMAGEN=localhost/campus-php-runtime:6710e59; TAR=imagen/campus-php-runtime-6710e59.tar.gz; CODIGO=codigo/campus-6710e59.tar.gz

ok(){ printf '  \033[32m✔\033[0m %s\n' "$*"; }
paso(){ printf '\n\033[1m%s\033[0m\n' "$*"; }
falla(){ printf '\n  \033[31m✘ %s\033[0m\n\n' "$*" >&2; exit 1; }
aleatorio(){ tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${1:-32}"; }
dc(){ docker compose "$@"; }
puerto(){ grep -m1 '^CAMPUS_PUERTO=' "$LOCAL" 2>/dev/null | cut -d= -f2 || true; }
url(){ echo "http://localhost:$(p=$(puerto); echo "${p:-18080}")"; }

comprobar(){
  paso "Comprobaciones"
  [ "$(uname -s)" = Linux ] || falla "Ejecuta este guion dentro de WSL2 (Ubuntu), no en PowerShell ni en CMD."
  case "$DIR" in /mnt/*) falla "El paquete está en una carpeta de Windows ($DIR). Cópialo a tu carpeta de Linux (por ejemplo ~/A_PAQUETE_CAMPUS): ver la guía, paso 3." ;; esac
  if grep -q $'\r' "$0" docker/*.sh 2>/dev/null; then falla "Los guiones tienen finales de línea de Windows (CRLF). Ejecuta: sed -i 's/\r\$//' campus.sh docker/*.sh"; fi
  command -v docker >/dev/null || falla "No encuentro «docker». Abre Docker Desktop y activa la integración con tu Ubuntu (guía, paso 2)."
  docker info >/dev/null 2>&1 || falla "Docker no responde. Abre Docker Desktop, espera a que diga «Engine running» y vuelve a intentarlo."
  docker compose version >/dev/null 2>&1 || falla "Falta «docker compose». Actualiza Docker Desktop."
  ok "WSL2, carpeta de Linux y Docker en marcha"
}

preparar(){
  comprobar
  paso "Preparación (sólo la primera vez hace algo)"
  if ! docker image inspect "$IMAGEN" >/dev/null 2>&1; then
    [ -f "$TAR" ] || falla "Falta $TAR"
    echo "  Cargando la imagen de la aplicación (1–2 minutos)…"; docker load -i "$TAR" >/dev/null; ok "imagen cargada"
  else ok "imagen de la aplicación ya cargada"; fi
  if [ ! -d codigo/campus ]; then
    echo "  Descomprimiendo el código…"; tar -xzf "$CODIGO" -C codigo; ok "código descomprimido"
  else ok "código ya descomprimido"; fi
  mkdir -p config; chmod 700 config
  if [ ! -f "$LOCAL" ]; then
    { echo "CAMPUS_PROYECTO=campusdigital"; echo "CAMPUS_PUERTO=18080"; echo "CAMPUS_PROCESOS_WEB=8"
      echo "CAMPUS_SQL_SA_PASSWORD=Sa$(aleatorio 26)9x"; } > "$LOCAL"; chmod 600 "$LOCAL"; ok "contraseña del administrador de SQL Server generada (archivo .env)"
  else ok "archivo .env ya existe"; fi
  if [ ! -f "$CFG" ]; then
    local p; p=$(puerto)
    cat > "$CFG" <<EOF
APP_NAME=Campus Digital
APP_ENV=local
APP_DEBUG=false
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_URL=http://localhost:${p:-18080}
APP_LOCALE=es
DB_CONNECTION=mongodb
MONGODB_URI=mongodb://mongo:27017/campus?replicaSet=rs0
MONGODB_DSN=mongodb://mongo:27017/campus?replicaSet=rs0
MONGODB_DATABASE=campus
DB_DATABASE=campus
SQLSRV_HOST=sqlserver
SQLSRV_PORT=1433
SQLSRV_DATABASE=campus_financial
SQLSRV_USERNAME=campus_app
SQLSRV_PASSWORD=$(aleatorio 32)
SQLSRV_ENCRYPT=yes
SQLSRV_TRUST_SERVER_CERTIFICATE=true
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
MAIL_MAILER=log
LOG_CHANNEL=stderr
LARAVEL_STORAGE_PATH=/storage
EOF
    chmod 600 "$CFG"; ok "configuración privada creada (config/campus.env)"
  else ok "configuración privada ya existe"; fi
}

esperar_app(){
  for _ in $(seq 1 60); do curl -s -o /dev/null -m 3 "$(url)/up" && return 0; sleep 2; done; return 1
}

instalar(){
  preparar
  paso "Bases de datos"
  dc up -d mongo sqlserver >/dev/null
  echo "  Esperando a MongoDB (réplica) y a SQL Server…"
  for _ in $(seq 1 60); do [ "$(docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q mongo)" 2>/dev/null)" = healthy ] && break; sleep 3; done
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q mongo)" 2>/dev/null)" = healthy ] || falla "MongoDB no arrancó. Revisa: docker compose logs mongo"
  ok "MongoDB listo"
  dc up -d app >/dev/null
  dc exec -T -e CAMPUS_SQL_SA_PASSWORD="$(grep -m1 '^CAMPUS_SQL_SA_PASSWORD=' "$LOCAL" | cut -d= -f2-)" app php /docker/crear-bases.php | sed 's/^/  /'
  paso "Instalación de Campus Digital con las cuentas de demostración (3–6 minutos)"
  if dc exec -T app test -f /storage/.campus-instalado; then ok "ya estaba instalado"; else
    dc exec -T app /docker/con-entorno.sh php artisan campus:install --demo --env-file=/config/campus.env --env-format=container \
       --internal-url=http://app:8080 --loopback-url=http://127.0.0.1:8080 | sed 's/^/  /' || falla "La instalación falló. Copia el mensaje de arriba al equipo que te envió el paquete."
    dc exec -T app /docker/con-entorno.sh php artisan campus:install --check >/dev/null || falla "La comprobación posterior a la instalación falló."
    dc exec -T app touch /storage/.campus-instalado; ok "instalado"
  fi
  paso "Arranque"
  esperar_app || falla "La aplicación no responde. Revisa: ./campus.sh registros"
  ok "Campus Digital en marcha"
  acceso
}

iniciar(){
  comprobar; [ -f "$CFG" ] || falla "Todavía no está instalado: ejecuta ./campus.sh instalar"
  dc up -d >/dev/null; echo "  Arrancando…"; esperar_app && ok "Campus Digital en marcha en $(url)" || falla "No responde todavía. Revisa: ./campus.sh registros"
}
detener(){ dc stop; ok "Detenido. Los datos se conservan; para volver: ./campus.sh iniciar"; }
estado(){ dc ps; printf '\n  Aplicación (%s): ' "$(url)"; curl -s -o /dev/null -m 5 -w '%{http_code}\n' "$(url)/up" || echo "no responde"; }
acceso(){
  [ -f "$CFG" ] || falla "Todavía no está instalado."
  local pw; pw=$(grep -m1 '^CAMPUS_DEMO_PASSWORD=' "$CFG" | cut -d= -f2-)
  paso "Acceso (no compartas esta pantalla ni la pegues en chats)"
  echo "  Dirección:   $(url)"
  echo "  Usuario:     <cuenta>@campus.test   (lista de cuentas: docs/CUENTAS-POR-EQUIPO.md)"
  echo "  Contraseña:  ${pw:-(se genera al instalar)}   — la misma para las 31 cuentas demo de ESTA instalación"
  echo "  Las cuentas de administración piden configurar el segundo factor la primera vez (app de autenticación en el teléfono)."
}
registros(){ dc logs -f --tail=100 app; }
borrar_todo(){
  printf 'Esto borra TODOS los datos de esta instalación (bases, archivos y contraseñas generadas). Escribe BORRAR para continuar: '
  read -r r; [ "$r" = BORRAR ] || { echo "Cancelado."; exit 0; }
  dc down -v; rm -f "$CFG" "$LOCAL"; ok "Borrado. Para empezar de nuevo: ./campus.sh instalar"
}

case "${1:-}" in
  instalar) instalar ;; iniciar) iniciar ;; detener) detener ;; estado) estado ;; acceso) acceso ;;
  registros) registros ;; borrar-todo) borrar_todo ;; preparar) preparar ;;
  *) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
