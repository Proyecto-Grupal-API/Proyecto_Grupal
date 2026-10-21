# Compras pagadas solo con wallet

Aplicar sobre el commit e435fc6 (compras/eventos) y emisor 6641dbf, después de las 531 pruebas aprobadas reportadas. Este paquete no instala la carpeta Equipo2 del ZIP conjunto ni cambia el contrato de roles.

## Implementación

PurchasePaymentService incorpora payWithWallet, un método interno independiente de pay y payWithMultipleBonuses. Guarda PurchasePayment con bonus_id null, bonus_amount_cents 0 y wallet_amount_cents igual al total. La migración permite que bonus_id sea nulo; no crea un bono ficticio ni cambia compras anteriores. No se modifica PurchaseRefundService ni la consulta de comprobantes porque aceptan repartos sin bonos.

Bajo una transacción SQL, bloquea y relee la wallet, valida tipo/propietario, exige estado activo para cargos nuevos, evalúa límites BLOQUEAR para PAGO y realiza el débito en ledger con referencia PURCHASE al ID público de la compra. Crea un evento PAID si recibe contexto Marketplace. Una falla en el débito o en el evento revierte compra, débito y vínculo. No liquida ni abona dinero a un negocio: conserva la semántica de cargo de compra existente, no implementa un nuevo proceso de liquidación.

La idempotencia valida wallet, importe, negocio, categoría, moneda y modalidad sin bonos. Un replay comprueba también la transacción completada y su único asiento PAGO con el importe correcto, y conserva el contexto. No se admite reutilizar claves de otras operaciones. Un replay completado puede recuperar el resultado aunque la wallet haya sido bloqueada posteriormente; no realiza otro débito. La autorización de quien consulta ese resultado deberá comprobarse en el futuro endpoint.

Los límites económicos usan FinancialLimitService existente, no un monto arbitrario nuevo. Los intentos bloqueados conservan su alerta después de revertir la transacción de compra. El actor queda sin atribución de usuario autenticado, pues este método interno no recibe una prueba de identidad. El límite 9007199254740991 es de representación numérica, no una política económica. Si existe un límite por rol y su proveedor no está disponible, su evaluación debe fallar cerrada. No se conceden permisos ni se reemplaza el proveedor pendiente.

El servicio no valida por sí solo sesión, permiso del comprador, propiedad/precio del pedido ni una autorización delegada. Debe llamarlo un servicio de aplicación que aporte esas garantías. No se agrega ruta ni controlador HTTP. El endpoint propuesto por Eq3 con buyer_id y financial:write sigue sin incorporarse. No se abren cargos reales desde otro módulo hasta resolver esa autorización.

## Devoluciones y comprobantes

Reutiliza request/approve/complete de PurchaseRefundService con bonus_refunds=[] y wallet_amount_cents correspondiente. La devolución puede ser parcial o total y exige el ciclo de aprobación existente. Los eventos solo se registran al completar, llevan importe delta y no inventan artículos de retorno físico. PersonalReceiptQueryService muestra una compra una sola vez con su asiento real y desglose de bonos vacío; los controladores/PDF existentes continúan usando esa proyección.

Un pago sin contexto Marketplace conserva su comprobante y devolución interna, pero no fabrica evento para Eq4. No se permite agregar un contexto retroactivamente a una compra ya pagada. Para compras vinculadas, payment_intent_id sigue siendo PurchasePayment.public_id en minúsculas, pendiente de aceptación con Eq3. No es necesariamente FinancialTransaction.public_id.

## Instalación

Descomprimir en C:\Proyectos\Campus-Digital-compras-wallet. Ejecutar uno por uno:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-compras-wallet\Apply-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital"
```

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-compras-wallet\Verify-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital" -MigrateDevelopment
```

Apply valida hash del servicio base, sintaxis y archivos nuevos; respalda fuera del proyecto. Rechaza bases diferentes antes de copiar. No altera .env, rutas, usuarios ni roles. Verify migra la columna nueva en desarrollo solo con -MigrateDevelopment, asegura tablas necesarias de pruebas y ejecuta las pruebas nuevas más compras, devoluciones y outbox. Después puede ejecutarse -FullFinancialSuite. Las bases de pruebas son exclusivamente campus_virtual_testing y campus_digital_financial_testing; TestCase limpia Mongo de pruebas. No se requiere build de Vue.

Se escribieron diez pruebas de integración SQL, pero aquí NO se ejecutaron porque no hay PHP, PowerShell, SQL Server ni MongoDB. La verificación está pendiente en tu entorno. No se certifica concurrencia multiproceso con estas pruebas. Los locks y restricciones existentes pueden producir conflictos SQL/deadlocks: no reintentar con otra clave, sino recuperar/reintentar la misma operación completa cuando corresponda.

Mantener FINANCIAL_TEAM4_WEBHOOK_ENABLED=false. No se envían peticiones reales en las pruebas. Esta entrega no constituye una prueba conjunta E2/E3/E4.

## Uso interno

```php
$result = $payments->payWithWallet(
    wallet: $authorizedWallet,
    totalAmountCents: $verifiedAmountCents,
    idempotencyKey: $stableKey,
    businessId: $verifiedBusiness,
    marketplace: $verifiedContext // order_id, business_id, items canónicos; o null para compra interna
);
```

El resultado incluye purchase_payment_id y el desglose en centavos. No aceptar esos valores del navegador sin verificar ni utilizar tinker para cargar cuentas reales. Las fixtures de las pruebas son únicamente de pruebas y se revierten.

## Pendientes externos

- E1: contrato de identidad/autorización de un pago concreto y revocación; no reinstalar los siete administradores rechazados.
- E3: un ID estable para reserva/cobro/confirmación, datos canónicos de pedido/negocio/líneas, lookup ante timeout y reintento del mismo checkout. Se conserva el análisis del ZIP conjunto de la entrega anterior.
- E2: endpoint financiero dedicado, autenticado y autorizado; scope específico, validación, consulta del resultado y auditoría del actor verificado. No usar solo client_credentials como permiso para debitar al comprador.
- E4: receptor corregido y pruebas conjuntas, incluyendo devoluciones sin retorno físico y confirmación posterior al webhook.
- Operación: acordar ejecución frecuente del emisor y manejo de eventos REVIEW/EXHAUSTED antes de habilitarlo.

La migración down se niega a hacer bonus_id obligatorio mientras existan compras sin bono. No borrar esas compras ni asignarles bonos ficticios para forzar rollback. Conservar evidencia y migración si ya hay datos dependientes.
