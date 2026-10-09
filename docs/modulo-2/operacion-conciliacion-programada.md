# Operación de conciliación automática (2.10) y caja (2.8)

Corte base: `892bd12`. Acuerdo: diario a las 02:00 en `America/Mexico_City`, para conciliar el día anterior. La hora es de ejecución: no modifica los límites del día de negocio, que sigue comenzando a medianoche.

## Aislamiento

La conexión de cola `financial` utiliza SQL Server y `financial_queue_jobs`. La cola se llama `financial-reconciliation`. El worker `financial:queue-work` registra los trabajos agotados en `financial_queue_failed_jobs`. No modifica `QUEUE_CONNECTION`, `CACHE_STORE`, ni el proveedor de fallos de los otros módulos.

`FINANCIAL_SCHEDULER_CACHE_STORE=financial_scheduler` selecciona tablas SQL dedicadas para los mutexes del scheduler. Afecta sus tareas, incluidas las retenciones, pero no la caché de la aplicación. Todos los servidores del mismo despliegue deben apuntar a la misma base y usar el mismo prefijo de caché. Otros despliegues deben usar bases o prefijos distintos.

La migración crea cuatro tablas independientes. No contiene credenciales y no cambia las tablas de movimientos, wallets, cajas ni usuarios. No se concede ningún permiso por este bloque.

## Aplicación y verificación

1. Aplicar el ZIP mediante `Apply-Changes.ps1`. Verifica los hashes del corte base y guarda respaldo.
2. Ejecutar `Verify-Changes.ps1 -MigrateDevelopment`. Migra desarrollo y la base financiera de pruebas; ejecuta pruebas operativas, de integración y la suite financiera completa. No activa el horario en desarrollo ni ejecuta un worker sobre su cola.
3. Cuando las pruebas pasen, ejecutar `Configure-Operations.ps1`. Actualiza exclusivamente las variables financieras indicadas abajo, con respaldo de `.env` fuera del repositorio. Si falla la comprobación, restaura `.env`. No incorpora `.env` al ZIP ni a Git.
4. En una terminal del proyecto, mantener `php artisan financial:queue-work`. En otra, mantener `php artisan schedule:work`. Si el scheduler ya está abierto, detenerlo con Ctrl+C y reiniciarlo para cargar la configuración nueva. Estos procesos deben mantenerse activos.
5. `php artisan schedule:list` debe mostrar conciliación a las 02:00 y expiración de retenciones cada minuto. `php artisan financial:operations-check --require-schedule` comprueba tablas, configuración y exclusividad de locks; no confirma procesos activos.

## Variables escritas por el configurador

```dotenv
FINANCIAL_BUSINESS_TIMEZONE=America/Mexico_City
FINANCIAL_RECONCILIATION_SCHEDULE_ENABLED=true
FINANCIAL_RECONCILIATION_DAILY_AT=02:00
FINANCIAL_RECONCILIATION_CALENDAR=DIARIO
FINANCIAL_RECONCILIATION_ACTOR=SYSTEM_SCHEDULER
FINANCIAL_RECONCILIATION_JOB_TIMEOUT=600
FINANCIAL_RECONCILIATION_LOCK_TTL=900
FINANCIAL_RECONCILIATION_RETRY_AFTER=960
FINANCIAL_RECONCILIATION_JOB_TRIES=3
FINANCIAL_RECONCILIATION_JOB_BACKOFF=60,300
FINANCIAL_SCHEDULER_CACHE_STORE=financial_scheduler
FINANCIAL_HOLD_SCHEDULE_EXPIRATION=true
FINANCIAL_CASH_RECONCILIATION_ENABLED=true
```

Se conserva el calendario diario incluso en fines de semana y festivos. Un calendario de lunes a viernes dejaría fechas sin revisar porque el comando elige ayer; se rechaza hasta implementar recuperación de fechas omitidas. Una zona previa distinta de la acordada bloquea el configurador: cambiarla reinterpretaría límites diarios y conciliaciones.

## Desarrollo y producción

En Windows sin PCNTL, usar un solo worker para desarrollo. `--timeout` no proporciona un límite efectivo de proceso sin esa extensión. En producción el comprobador y el worker exigen PCNTL; utilizar Linux y un supervisor de procesos que reinicie el worker si cae. El timeout técnico de 600 segundos debe ser menor que el TTL del lock (900), y éste menor que `retry_after` (960). Si la conciliación real tarda más, ajustar los tres de manera consistente y reiniciar el worker. El lock SQL y su validación de propietario permanecen activos.

En producción, registrar `php artisan schedule:run` cada minuto en cron y el worker como servicio. No ejecutar adicionalmente `schedule:work` en el mismo servidor si ya existe cron. Los mutexes compartidos reducen duplicados entre servidores; los jobs mantienen fecha e idempotencia por intento. La programación no recupera automáticamente horarios perdidos por apagado: si se pierde una ejecución, conciliar explícitamente esa fecha.

## Comprobación controlada y fallos

Para probar la cola sin esperar a las 02:00, `php artisan financial:reconcile AAAA-MM-DD --dispatch` encola una conciliación real de esa fecha; el worker debe procesarla. Una fecha inválida o futura se rechaza antes de encolarla. No ejecutar esta comprobación indiscriminadamente sobre producción.

`financial:operations-check` informa cantidades de pendientes y fallidos. Revisar las corridas y sus diferencias en el 2.10 con un usuario autorizado y revisar `storage/logs/laravel.log` si la ejecución falla. `financial_queue_failed_jobs` conserva excepción y payload para el operador técnico; no se expone públicamente.

Después de corregir una causa de fallo agotado, ejecutar una nueva corrida explícita con una llave nueva: `php artisan financial:reconcile AAAA-MM-DD --idempotency-key=recuperacion-AAAA-MM-DD-1 --executed-by=OPERADOR`. No recomendar `queue:retry` genérico: el proveedor de fallos de otros módulos es diferente y el job conserva las llaves por fecha e intento de la corrida fallida.

Los logs de consola, el conteo de cola y el listado del scheduler no sustituyen observar una corrida realmente procesada. Esta entrega no instala servicios del sistema, Docker ni contratos de roles pendientes. La interfaz de bonos (2.5) continúa en un bloque separado después de verificar esta base.
