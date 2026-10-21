# Conexión interna de compras y devoluciones con outbox Equipo 4

Aplicar sobre la versión del Módulo 2 enviada en Campus-Digital-Modulo2-revision-pagos.zip y el emisor ya probado del commit 6641dbf. NO copiar la carpeta Equipo2 del ZIP conjunto: es una instantánea anterior y contiene un endpoint que aún no valida autorización delegada del comprador.

## Alcance

Se modifican únicamente PurchasePaymentService y PurchaseRefundService; se agregan un servicio interno PurchasePaymentEvents, una tabla de vínculos y ocho pruebas. Los métodos pay/payWithMultipleBonuses aceptan un último argumento opcional marketplace con order_id, business_id e items. Los llamadores existentes sin contexto conservan su flujo y no generan eventos históricos. La compra conserva un vínculo inmutable al pedido y su negocio. La combinación negocio/pedido es única: otra compra del mismo pedido provoca rollback, incluso con otra clave.

Los pagos vinculados registran PAID en la misma transacción SQL. Su payment_intent_id es PurchasePayment.public_id en minúsculas; también existe en pagos totalmente con bonos sin débito de wallet. Es una decisión interna propuesta que debe acordarse con Equipo 3 antes de exponer una API; no se cambia ni publica el contrato de su cliente actual. Los arrays de respuesta de los servicios anteriores se conservan.

Al completar una devolución vinculada se registra PARTIALLY_REFUNDED o REFUNDED según el total acumulado de devoluciones completadas; amount es el delta de esa devolución, no el acumulado. refund_id identifica la solicitud ejecutada. Solicitar/aprobar/rechazar no dispara estos eventos. Reintentar complete no duplica el evento. Los items de devolución están vacíos: NO implica retorno físico. La política de retorno físico y el efecto de items vacío en Eq4 deben confirmarse antes de activar envíos.

No se crea endpoint HTTP, no se entrega una autorización ficticia, no se otorgan roles y no se llama al receptor. La API wallet-only del paquete Eq3 no se incorpora; PurchasePaymentService aún exige uno o varios bonos. Esta entrega conecta los flujos de compra ya existentes con bonos/mix, NO completa el checkout wallet-only de Eq3. No se fabrican PENDING/FAILED a partir de una transacción que se revirtió. No se retiene ni liquida dinero al negocio: es el comportamiento previo de consumo/cargo, no un nuevo modelo de liquidación.

## Instalación

Descomprimir en C:\Proyectos\Campus-Digital-compras-eventos-equipo4. Ejecutar por separado:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-compras-eventos-equipo4\Apply-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital"
```

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-compras-eventos-equipo4\Verify-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital" -MigrateDevelopment
```

Apply verifica hashes de los dos archivos base y rechaza colisiones en archivos nuevos. Crea un respaldo de los originales fuera del proyecto. No altera .env ni ejecuta migraciones. Si falla la copia restaura lo que alcanzó a escribir. No volver a ejecutar Apply sobre cambios ya instalados.

Verify migra únicamente la tabla nueva (y asegura la outbox en pruebas), ejecuta ocho pruebas nuevas, las diez de outbox y las pruebas existentes de pagos/devoluciones. -FullFinancialSuite amplía a toda la carpeta financiera. Las bases de pruebas obligatorias son campus_virtual_testing y campus_digital_financial_testing; el TestCase del proyecto limpia Mongo de pruebas. No quitar sus guardas. No cambiar .env a producción ni activar FINANCIAL_TEAM4_WEBHOOK_ENABLED. No hay cambios Vue: no se necesita build.

No hay PHP/PowerShell/SQL Server/MongoDB disponibles en el entorno de preparación. Se revisaron diferencias y se escribieron pruebas, pero NO se ejecutaron. Ninguna prueba multiproceso real de SQL Server está certificada. La exclusión del mismo pedido usa índice único; los deadlocks o carreras deben provocar rollback y reintento de la transacción completa. El servicio anterior de pagos no incorpora en esta entrega un nuevo mecanismo de reintentos de concurrencia.

## Uso interno futuro

El contexto debe ser construido por un servicio de aplicación autorizado a partir de un pedido verificado, no directamente desde un formulario del navegador ni desde un buyer_id presentado por un servicio. No basta un token client_credentials para autorizar el débito de un usuario.

```php
$service->pay(
    wallet: $wallet,
    totalAmountCents: $verifiedTotal,
    bonusPublicId: $bonusId,
    idempotencyKey: $key,
    businessId: $verifiedBusiness,
    marketplace: [
        'order_id' => $verifiedOrder,
        'business_id' => $verifiedBusiness,
        'items' => $verifiedLines, // product_id, variant_id nullable, location_id, quantity int
    ],
);
```

Una repetición debe conservar el contexto. No se permite agregarlo retroactivamente a compras históricas ni omitirlo si ya estaba vinculado. La validación de estructura no sustituye identidad, permiso, precio ni propiedad del pedido.

## Pendientes del paquete recibido

1. E1/E2/E3: prueba delegada del comprador ligada a cliente, acción, pedido, importe/moneda, vigencia y revocación; scope financiero específico. El endpoint propuesto acepta buyer_id y financial:write, sin esa garantía.
2. Eq3: genera $orderId para reservar pero persiste Order con un ObjectId distinto y cobra/confirma con ese segundo ID. Usar un ID canónico estable en todos los pasos.
3. Eq3: reenvío/doble clic crea otra orden y otra clave. Persistir y recuperar el intento de checkout; un timeout no prueba fallo definitivo ni habilita otro cargo. Consultar/reintentar la misma operación.
4. Eq3: enviar business_id y líneas canónicas (incluyendo location/variant); dinero en centavos desde el origen, sin round(float*100). No usar precios viejos del carrito como única fuente.
5. Eq3/E2: lookup de pago por referencia idempotente y recuperación del resultado ante timeout; validar que token/cliente correspondan al pedido. Caché de token basada en expires_in y reacción a 401.
6. Eq3/E4: webhook asíncrono puede llegar después de confirm. Implementar recuperación durable de confirmación, no solo un bucle HTTP de segundos. No liberar inventario automáticamente ante resultado incierto.
7. Eq4: resolver los pendientes del documento de requerimientos antes de activar el envío; confirmar monto delta y devoluciones sin retorno físico.
8. E2: API de cargo wallet-only autorizada y unificada con el registro de compras/devoluciones; no integrar el controller directo al ledger que deja la compra fuera del flujo PurchaseRefundService. No alterar bonus_id a un UUID ficticio para cubrir wallet-only.

## Reversión técnica

Para deshacer código, restaurar los dos originales del respaldo y retirar los archivos nuevos del manifiesto. No hacer rollback de tablas con datos sin evaluación: un vínculo existente explica el origen de sus eventos. La migración down solo elimina la tabla de vínculos; no altera ni borra compras, saldos ni outbox. No deshacer la migración con el código nuevo activo y vínculos que deban seguir auditándose.
