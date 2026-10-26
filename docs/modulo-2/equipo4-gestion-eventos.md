# Gestion operativa de eventos para Equipo 4

## Alcance

Consulta CLI y cuarentena auditada de eventos de desarrollo nunca intentados. No agrega pantallas, rutas HTTP, roles ni permisos institucionales. No cambia pagos, wallets, comprobantes, devoluciones o el payload contractual. La integracion de Equipo 3 queda fuera de esta entrega, conforme a la decision de continuar solo con Modulo 2.

El objetivo inmediato es apartar el evento del negocio DEV-CHECKOUT-LOCAL que ya genero un pago real en la base de desarrollo. Ese evento NO debe enviarse a un inventario real. Se mantiene FINANCIAL_TEAM4_WEBHOOK_ENABLED=false. No ejecutar financial:team4-deliver para probar manualmente esta entrega.

## Comandos de consulta

```powershell
php artisan financial:team4-events list --limit=20
```

```powershell
php artisan financial:team4-events list --payment="f13452a8-b15d-4b10-8506-d9bbeee10817"
```

```powershell
php artisan financial:team4-events show "fade14e5-1af9-4226-8f6c-26a745e870bc"
```

Los IDs anteriores son los reportados en la prueba de desarrollo del usuario, no valores que el instalador modifica automaticamente. Si el evento no existe en otra base, usar sus IDs propios. `show` recibe event_id, NO payment_intent_id ni payment_request_id.

La lista muestra estado de ENVIO, intentos, pedido, negocio, identificador financiero y hash del cuerpo. Limite 1..100, orden descendente por id y cursor next_before_id; para continuar usar --before-id=VALOR. Se pueden combinar --state=PENDING y --payment=ID. El estado financiero PAID esta en stored_body; no debe confundirse con PENDING/REVIEW/DELIVERED del envio.

`show` incluye el JSON serializado exacto, integrity_ok y acciones operativas. No hace HTTP, no actualiza timestamps ni agrega un intento. No muestra secreto HMAC, URL de destino, token OAuth, sesiones o contrasenas. El contenido y la etiqueta del operador son datos operativos; compartir solo con quien corresponda.

## Apartar un evento

Primero inspeccionar la vista previa:

```powershell
php artisan financial:team4-events quarantine "fade14e5-1af9-4226-8f6c-26a745e870bc"
```

Sin --confirm SOLO consulta. Muestra el body_hash que debe revisar y las condiciones de aplicacion. No promete que el estado permanezca igual: las condiciones se vuelven a comprobar bajo bloqueo al aplicar.

Para aplicar en desarrollo, generar una sola vez un UUID de accion y conservarlo durante cualquier reintento:

```powershell
$accionCuarentena = [guid]::NewGuid().ToString()
```

Copiar body_hash del resultado de `show`, sustituyendo SHA256_REVISADO. El siguiente es un ejemplo de uso, no ejecutarlo con el marcador literal:

```powershell
php artisan financial:team4-events quarantine "fade14e5-1af9-4226-8f6c-26a745e870bc" --body-hash="SHA256_REVISADO" --action-id="$accionCuarentena" --operator="operador-desarrollo" --reason="Compra de prueba local con negocio ficticio; no enviar a inventario real." --confirm
```

No se pide ni se registra una contrasena. `operator` es una etiqueta DECLARADA del operador con acceso a la consola, no una identidad autenticada por Equipo 1 ni un rol aprobado. No aporta no repudio. El motivo no debe contener secretos ni datos personales innecesarios.

La accion de escritura esta limitada a APP_ENV local/testing. En production se rechaza la cuarentena, pero se permite la consulta CLI. No cambiar APP_ENV para eludir ese limite en un sistema real. Una futura operacion institucional requiere su mecanismo aprobado de autorizacion.

## Garantias y bloqueos

- Solo evento PENDING con attempts=0, delivered_at=null, sin lease y sin filas de intentos.
- Se exige coincidencia entre el hash revisado, el hash guardado y SHA256 de los bytes reales. No se repara contenido corrupto.
- Se bloquea primero el stream y despues el evento, en el mismo orden que el emisor existente. Si el worker ya gano y reclamo el envio, la cuarentena se rechaza incluso con lease vencido. No cancela una peticion en vuelo ni presume que nunca llego.
- Auditoria y cambio de estado estan en la misma transaccion SQL. Si falla cualquiera, se revierten ambos.
- El nuevo estado es REVIEW, last_code=OPERATOR_QUARANTINE. El emisor existente ya bloquea REVIEW y todos los eventos posteriores del mismo pago. Otros pagos siguen independientes.
- No se declara DELIVERED, no se crea ACK, no se modifica amount, body, body_hash, version, available_at ni contador de intentos.
- El mismo action-id y contenido devuelve idempotent=true sin duplicar historial. Reutilizar action-id cambiando evento, hash, operador o motivo se rechaza. Un action-id distinto sobre un evento ya apartado tambien se rechaza.
- No hay borrado, reapertura, reenvio manual, reinicio de intentos ni desbloqueo del stream en esta entrega. REVIEW no vence por tiempo. Nunca se usa cuarentena como devolucion de dinero: la compra sigue PAID y la wallet conserva su saldo.

La auditoria guarda event_id, action_id, operador declarado, motivo, estado anterior/nuevo, intentos, codigo previo, hash del cuerpo, entorno y fecha. Este servicio solo agrega filas, pero no convierte la base en inalterable para un administrador SQL con acceso directo. El down de la nueva migracion se niega a borrar una tabla que ya contiene auditoria.

## Verificacion y limites

Pruebas nuevas: consulta/paginacion, contenido inmutable, cuarentena sin tocar dinero, idempotencia y conflictos, aislamiento por pago, eventos con intentos/leases, integridad, rollback por fallo despues del registro de auditoria, comandos de vista previa/confirmacion, restricciones de entorno y llegada reentrante de un operador mientras el worker espera el ACK.

Se comprueban ambos ordenes logicos: cuarentena antes del worker impide envio; worker que reclamo antes impide cuarentena. No afirmar que esto es una prueba SQL Server multiproceso: no se ejecutan dos procesos/conexiones concurrentes en esa prueba. El bloqueo se apoya en la transaccion/lock de SQL Server y el orden compartido con el emisor. No hay garantia distribuida entre SQL/Mongo o servidores externos.

No hay PHP, PowerShell, MongoDB ni SQL Server en el entorno de preparacion. Las pruebas NO fueron ejecutadas aqui. Verify debe pasar en Windows con ambas bases de pruebas antes de aplicar cuarentena al evento de desarrollo. Los HTTP de pruebas se interceptan con Http::fake/preventStrayRequests; no se llama al receptor real.

La suite existente TestCase elimina la base Mongo de pruebas al iniciar cada test. Verify valida los nombres campus_virtual_testing y campus_digital_financial_testing ANTES de arrancar la suite; no quitar esas guardas. Las migraciones SQL de pruebas permanecen en la base de pruebas, los registros de prueba quedan bajo rollback.
