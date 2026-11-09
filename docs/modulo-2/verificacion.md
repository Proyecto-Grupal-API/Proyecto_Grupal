# Verificación de E2

## Diagnóstico estático

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\financial\Check-Environment.ps1 -ProjectPath "C:\Proyectos\Campus-Digital"
```

Para salida estructurada, añadir `-AsJson`. La herramienta no crea un informe en disco; muestra el resultado en terminal. No imprime valores de `.env`, contraseñas, tokens ni salida interna de errores de los procesos.

| Estado | Significado |
|---|---|
| `OK` | Se comprobó únicamente lo descrito en esa fila |
| `WARN` | Hay una condición para revisar; no provoca por sí sola código de salida 1 |
| `ERROR` | Falta un requisito local o no pudo comprobarse; código de salida 1 |
| `INFO` | Dato informativo, como el límite de memoria del PHP CLI |
| `SKIP` | No se hizo la comprobación; nunca interpretarlo como aprobado |

Código 0 significa que no se detectaron errores en las comprobaciones locales, no que el módulo esté listo para operar. `runtime_verified` siempre es false. Un lock diferente genera advertencia: los rangos de versión corresponden al lock de `9c1c91a`. La herramienta no resuelve todas las restricciones Composer, versiones de extensiones, librerías ODBC ni compatibilidad del servidor final.

No requiere `.env` completo para arrancar porque no carga Laravel ni vendor/autoload.php. Si PHP o Node faltan, continúa con las demás comprobaciones. Los procesos PHP/Node tienen un límite de 10 segundos y solo se ejecutan sondas estáticas o `--version`; Composer y npm solo se localizan en PATH. No se modifica el entorno del proceso.

## Qué puede cambiar datos

| Acción | Efectos a considerar |
|---|---|
| Este diagnóstico | Solo lee archivos y requisitos locales; no abre conexiones a bases ni ejecuta Artisan |
| `financial:operations-check` existente | Prueba caché y exclusión mutua con claves/bloqueos temporales; no es el diagnóstico estático nuevo |
| Pruebas de `tests/Feature/Financial` | Crean datos de testing. El TestCase actual borra la base MongoDB de testing antes de cada prueba |
| Ejecutor `tests/Concurrency/InstitutionalBeca/run.php` | Confirma fixtures SQL para compartirlos entre procesos y limpia registros propios al terminar; un cierre forzado puede impedir limpieza |
| `npm.cmd run build` | Escribe la compilación en public/build |
| `composer setup` | Instala, genera APP_KEY, ejecuta migraciones y compila; no es una comprobación inocua |
| Migraciones y seeders | Cambian esquemas o datos; revisar conexión, alcance y autorización antes de ejecutarlos |
| Scheduler, workers y emisor E4 | Pueden procesar operaciones o enviar eventos según configuración y permisos |

## Pruebas posteriores

Las bases reservadas son `campus_digital_financial_testing` y `campus_virtual_testing`, con APP_ENV=testing. Antes de ejecutar, revisar variables del proceso y URLs de conexión, configuración cacheada y la conexión efectiva. No ejecutar otras suites, workers o tareas sobre esas mismas bases al mismo tiempo. Un servidor de desarrollo puede seguir abierto si usa otras bases.

El diagnóstico no inicia pruebas. Utilizar el verificador aprobado de la entrega que se está revisando, que establece y restaura las variables del proceso y desactiva integraciones reales. No copiar un comando de pruebas aislado suponiendo que la configuración ya es segura. La suite con PDF necesitó `php -d memory_limit=512M` sobre el ejecutable Pest; esto no requiere cambiar php.ini ni el límite de producción. Las once carreras concurrentes se ejecutan por separado de Pest.

Si un diagnóstico falla, corregir únicamente el requisito identificado y volver a comprobar. No borrar bases, regenerar APP_KEY, activar permisos ni ejecutar migraciones para convertir todos los estados en OK.

## Alcance de esta entrega

Agrega cuatro archivos: un script y estas tres guías. No cambia README.md, `.env.example`, composer.json, configuraciones, servicios financieros ni módulos ajenos. No configura Docker ni certifica tareas programadas o integraciones. El siguiente paso es preparar despliegue reproducible a partir de los requisitos documentados, con una propuesta independiente de archivos y acciones.
