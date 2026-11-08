# Evaluación aislada del avance E2 en SQL Server

Fecha: 26 de octubre de 2026. Rama `feat/modulo-2-equipo-2`, commit
`bad1bf3ec87ddba036aa4c2cebae15426b7740c0`. No se incorpora Finanzas a la app integrada.

## Entorno y resultados

Se creó un laboratorio temporal sin puertos publicados ni acceso configurado a
las bases de las aplicaciones existentes: red `campus-e2-eval-20261026`,
contenedores `campus-e2-eval-php-20261026`, `campus-e2-eval-sql-20261026` y
`campus-e2-eval-mongo-20261026`. SQL Server Developer 2022, imagen oficial:
`mcr.microsoft.com/mssql/server@sha256:cf8f0ab2ebe61feec2eed2629061a07d247f791c2a8ddda74a4ac9f06a291718`.
Referencia de preparación: [documentación de Microsoft](https://learn.microsoft.com/en-us/sql/linux/sql-server-linux-docker-container-deployment?view=sql-server-ver17).

Bases exclusivas del laboratorio: `campus_digital_financial_testing` en SQL y
`campus_virtual_testing` en su propio MongoDB. No son las bases del alterno ni
las del proyecto principal. Las credenciales son aleatorias, locales y privadas;
ningún cliente de servicio real ni wallet de la aplicación fue utilizado.

Se instalaron las dependencias bloqueadas de la fuente, sin scripts/plugins de
Composer durante instalación, en una copia de vendor independiente. No se
reemplazaron las dependencias del alterno. Framework efectivo: Laravel 13.26.1.
Las únicas adaptaciones de ejecución fueron conexiones/entorno y guardas de
TestCase para impedir otro destino; no se cambió la lógica financiera recibida.

Migraciones Mongo y SQL correctas. Regresión dirigida: **54 pruebas aprobadas,
523 aserciones, 7.23 s**, sin fallos/errores/omisiones en el XML:

- `MarketplaceCheckoutTest`
- `WalletPurchaseServiceTest`
- `PurchaseMarketplaceEventsTest`
- `Team4PaymentOutboxTest`
- `Team4OutboxAdministrationTest`

La primera ejecución tuvo 54 advertencias por un archivo `.env` ausente en la
copia temporal. Se creó el archivo vacío (las variables ya venían del contenedor)
y se repitió la batería sin advertencias. No se ocultaron fallos ni debilitaron
pruebas. No se ejecutó toda la suite financiera ni pruebas de concurrencia
multiproceso o una transacción distribuida SQL/Mongo.

Los tests usan fixtures propios de E2, incluida su configuración de clientes y
wallets de prueba. Demuestran comportamiento interno del corte, no compatibilidad
operativa con la sesión final E1 ni liquidación a una organización real.

## Qué aporta y qué falta antes de integrar

Existe preparación y consulta de compras con confirmación directa del comprador,
contraseña reciente y consentimiento vinculado a la solicitud/sesión. El worker
puede recuperar resultados por pedido; la outbox E4 tiene firma, reintentos y
administración/cuarentena. La preparación no es un débito ni una venta entregada.

1. `MarketplaceCheckoutService` consulta `User`, `UserSession` y `ServiceClient`
   directamente. La copia E1 incluida en la rama financiera no debe reemplazar
   nuestra identidad final; el consumo entre dominios debe adaptarse por API.
2. El flujo wallet probado registra el débito del comprador. No acredita por sí
   solo una contrapartida al receptor del negocio: falta cerrar el modelo de
   receptor/financiamiento y su evidencia con E2.
3. `payment_request_id`, `PurchasePayment.public_id` y el intento local E3 son
   referencias diferentes. La confirmación E4 debe usar el ID financiero real,
   con el mismo pedido y artículos físicos canónicos.
4. El checkout E2 propone 600 segundos y E3 reserva 15 minutos. La preparación
   debe quedar limitada por la reserva real y las fechas del pedido, sin extender
   plazos en reintentos; cancelación, pago tardío y resultado desconocido requieren
   una prueba conjunta E2/E3/E4.
5. Los proveedores pendientes de permisos financieros/bonos/caja y delegación
   siguen en E2. El checkout nativo no resuelve autoridad para becas/eventos E6.
6. No se probó entrega HMAC E2→E4 real, ni consentimiento en navegador con E1
   final, ni fondos/roles institucionales. Los tests de outbox usan su receptor
   controlado; no habilitan el webhook de la app integrada.

Indicadores financieros de la aplicación integrada permanecen como estaban;
no se provisionaron scopes/allowlists ni se habilitaron emisores. El laboratorio
se detiene al terminar y conserva sus logs fuera de Git. Código/entorno temporal
en `/tmp/campus-e2-evaluacion-20261026/`; evidencia copiada a
`storage/logs/actualizacion-20261026/e2/`.

## Corte de integración del 7 de noviembre

Se incorporaron los cambios E2 `9b6bd2b` y `3973bd0` sobre bonos. Emitir o
cancelar exige una sesión vigente ya registrada en Identidad, además de los
permisos de Finanzas. Las operaciones administrativas conservan su evidencia
de sesión y las emisiones a uno mismo se rechazan.

El checkout Wallet usa una cuenta de negocio activa y explícita. En la
configuración privada `FINANCIAL_MARKETPLACE_CLIENTS_JSON`, cada cliente conserva
`name` y `businesses`. La nueva propiedad `recipient_wallets` mapea cada ID de
negocio habilitado al UUID de su wallet MXN de tipo `WALLET_NEGOCIO` y titular
`BUSINESS`. El UUID se liga al resumen que confirma el comprador. La
preparación de pedidos con artículos físicos requiere
`reservation_expires_at` con fecha ISO 8601 y zona. E3 debe obtenerla de la
reserva correspondiente. La vigencia efectiva es el menor valor entre esa
fecha y el TTL de Finanzas.

La compra registra el cargo del comprador y el abono del negocio en una
transacción SQL Server junto con la outbox. Las devoluciones parciales y
totales referencian la compra original y usan el receptor que quedó registrado
al cobrar. Si el receptor no tiene saldo para devolver, la solicitud permanece
aprobada para revisión o reintento. El conciliador distingue las compras
liquidadas de las compras históricas con un solo movimiento. La fase cubre
pagos con Wallet y un negocio por pedido.

Validación en un laboratorio sin puertos publicados, con SQL Server y MongoDB
desechables. Se usó un perfil externo de pruebas financieras restringido a
esas bases. La prueba HTTP crea una reserva firmada, prepara el pago por OAuth,
confirma con una sesión de prueba, consulta la operación por pedido y entrega
al receptor E4 el evento firmado almacenado en SQL Server. Repite el webhook y
la confirmación de inventario. El laboratorio verifica además conciliación,
devoluciones parciales y recuperación tras saldo insuficiente del comercio.

La conciliación diaria revisa las devoluciones completadas en la fecha de
negocio. Verifica el abono al comprador y, para compras liquidadas, el débito
al receptor original y su vínculo con el cargo. Registra una diferencia si
falta evidencia o si el tipo, la cuenta o el importe del movimiento no
corresponden. Las devoluciones históricas de un solo abono conservan su
tratamiento.

### Checkout de Marketplace

E3 ofrece **Pagar con wallet** y **Consultar pago** en Mis pedidos. Al preparar, consulta la reserva de E4 y fija la fecha límite en la menor vigencia de E3 y E4. Guarda el cliente y la vigencia en el pedido antes de llamar a E2 con la clave `marketplace-wallet:{order_id}`. Finanzas controla la sesión, la confirmación de contraseña y el cargo. El reconciliador de E3 consulta la operación por negocio y pedido y registra la referencia de pago.

Inventarios exige el evento firmado que entrega `financial:team4-deliver`. Mientras falta esa evidencia, el pedido queda en `payment_reconciliation`. E3 usa la clave `marketplace-confirm:{order_id}` y concilia nuevamente si pierde la respuesta de Inventarios. Si E4 ya liberó la reserva o confirma que venció, el pedido conserva el pago y pasa a `refund_review` para su resolución autorizada.

Una cancelación de E3 conserva la reserva hasta obtener `CANCELLED` o `EXPIRED` de E2. Cuando una consulta detecta un vencimiento, E3 usa la cancelación de E2 para verificar el resultado con el bloqueo de la fila financiera y resolver un cargo en curso. Si el cargo ganó, se conserva la referencia `PAID` y se concilia Inventarios. Un 404 tras una preparación incierta conserva el pedido para revisión, ya que esa respuesta por sí sola no acredita que se canceló. Se respeta el vencimiento que administra Inventarios.

La integración se controla con `MARKETPLACE_WALLET_CHECKOUT_ENABLED` y usa `MARKETPLACE_FINANZAS_URL`, `MARKETPLACE_FINANZAS_CLIENT_ID` y `MARKETPLACE_FINANZAS_CLIENT_SECRET`. Para activarla se requiere el cliente E3 aprobado con `financial:purchase:prepare`, `financial:purchase:read` y `financial:purchase:cancel`, la cuenta receptora aprobada para cada negocio y la configuración firmada E2–E4. El coordinador usa el comando programado `marketplace:reconciliar`. La devolución monetaria requiere su autorización y circuito propios.

## Garantías, caja y operaciones programadas

- Para `RENTAL_DEPOSIT`, Finanzas puede liquidar un cargo positivo y liberar
  el resto en la misma transacción SQL. El cargo sale del saldo retenido
  del alumno y entra en una wallet institucional activa de la misma moneda.
  El cierre guarda el importe cobrado, el liberado y el receptor. Un reintento
  idéntico devuelve el mismo cierre. Un depósito sin cargo se libera con la
  operación existente. El adaptador de E5 permanece pendiente hasta acordar la
  cuenta receptora, quién autoriza y el contrato de liquidación.
- La API de cancelación de Marketplace es
  `POST /api/v1/financial/marketplace/payment-requests/by-order/cancel`.
  Requiere `financial:purchase:cancel`, el cliente original y el mismo
  `business_id` y `order_id`. Devuelve el estado definitivo
  `CANCELLED`, `EXPIRED` o `PAID`. Si devuelve `PAID`, conserva la referencia
  financiera original. Un 404 después de una preparación de resultado incierto
  no acredita cancelación. E3 debe conservar la clave original y recuperar el
  resultado antes de disponer de la reserva.
- El detalle y PDF de comprobantes propios aceptan verificación opcional por
  `folio` de caja y/o `reference` canónica. La consulta exige la sesión del
  titular y que los identificadores coincidan con su comprobante.
- Las transferencias consultan las interfaces internas de E1 para cuentas y
  sesiones, y el directorio para los destinatarios. La consulta por matrícula
  exige la acreditación de integridad de E1. La autorización de transferencias
  y la delegación de comprobantes siguen pendientes de sus contratos.
- Se validó en laboratorio un turno con recarga y retiro confirmados por el
  alumno, folios estables, arqueo y una diferencia observada por el conciliador.
  La corrida programada usa la cola `financial_queue_jobs` y la caché SQL
  `financial_scheduler`. El proceso se prepara con el comando de diagnóstico
  `financial:operations-check --require-schedule` y un worker dedicado
  `financial:queue-work`. El horario y el calendario `DIARIO` se configuran
  explícitamente. La expiración de retenciones y la entrega E2→E4 tienen
  banderas separadas. Estas tareas siguen desactivadas en el alterno.
