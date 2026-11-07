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

Sigue pendiente conectar el coordinador de pedidos de E3 a estas rutas,
consultar el estado real de la reserva antes de confirmar y acordar la
cancelación entre E2 y E3 cuando se cruce con un cobro. Se debe ejecutar luego
el flujo desde la tienda con los workers y el transporte del alterno. Las
banderas del alterno siguen apagadas y este corte no provisiona wallets,
clientes OAuth ni permisos institucionales.
