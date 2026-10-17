# Cómo levantar el proyecto con Docker

Carpeta del proyecto: `Proyecto_Grupal-Modulo_4` (rama `Modulo_4`).

## Cada vez que quieras usarlo

1. Abre **Docker Desktop** y espera a que diga "Engine running".
2. En una terminal, dentro de la carpeta del proyecto:

   ```bash
   docker compose up -d
   ```

3. Abre en el navegador: <http://localhost:8004/equipo4>

Para apagarlo al terminar (no borra datos):

```bash
docker compose down
```

Si solo cierras Docker Desktop o apagas la PC, los contenedores se detienen
y vuelven a arrancar solos la próxima vez (`restart: unless-stopped`).

## Comandos útiles

| Qué quieres | Comando |
|---|---|
| Ver si está corriendo | `docker compose ps` |
| Ver logs de la app | `docker compose logs -f campus-eq4-app` |
| Entrar al contenedor | `docker compose exec campus-eq4-app bash` |
| Correr tests | `docker compose exec campus-eq4-app php artisan test` |
| Reiniciar la app | `docker compose restart campus-eq4-app` |

## Cuándo hay que reconstruir

La carpeta del proyecto está montada dentro del contenedor, así que no hace
falta reconstruir la imagen para cambios en `app/`, `routes/`, etc. Pero PHP
tiene `opcache.validate_timestamps = 0` (docker/php.ini), por lo que **no ve
los cambios de código hasta reiniciar**:

```bash
docker compose restart campus-eq4-app campus-eq4-scheduler
```

(Los `.vue` además necesitan recompilar el frontend, ver abajo.)

Reconstruye solo si cambió `Dockerfile`, `composer.json/lock` o `package.json`:

```bash
docker compose build
docker compose up -d
```

**Importante:** como el montaje oculta `vendor/` y `public/build/` del image,
después de reconstruir hay que copiarlos otra vez a tu carpeta:

```bash
cid=$(docker create campus-eq4-app:latest)
rm -rf vendor public/build
docker cp $cid:/var/www/html/vendor ./vendor
docker cp $cid:/var/www/html/public/build ./public/build
docker rm $cid
```

(En Git Bash antepón `MSYS_NO_PATHCONV=1` a los `docker cp`.)

## Si algo falla

- **"failed to connect to the docker API"**: Docker Desktop no está abierto o
  todavía está arrancando. Espera un minuto y repite.
- **"container name already in use"**: hay contenedores de otra carpeta (por
  ejemplo la versión vieja `ultima`). Bórralos y vuelve a subir; los datos
  están en un volumen y no se pierden:

  ```bash
  docker rm -f campus-eq4-app campus-eq4-scheduler campus-eq4-mongo
  docker compose up -d
  ```
- **Contenedor en bucle con `entrypoint.sh: no such file or directory`**: el
  archivo quedó con saltos de línea de Windows (CRLF). El `.gitattributes` ya
  lo evita; si pasa, convierte con `sed -i 's/\r$//' docker/*` y `docker compose build`.
- **Error `Class "App\Http\Controllers\Controller" not found`**: falta
  `app/Http/Controllers/Controller.php`. El `.gitignore` lo ignora, por eso no
  viene en el repo; hay que crearlo a mano (clase `abstract class Controller {}`
  en el namespace `App\Http\Controllers`).
- **Puerto ocupado (8004 o 27017)**: cierra lo que lo use o cambia el puerto
  de la izquierda en `docker-compose.yml`.

## Datos

Mongo guarda todo en el volumen `campus-eq4-mongo-data`. `docker compose down`
lo conserva. Para borrar la base y empezar de cero (⚠ pierde los datos):

```bash
docker compose down -v
```
