# Checkout con confirmación directa del comprador en Finanzas

Base: Módulo 2 commit 3a8b2aa, compras wallet ya verificadas y 541 pruebas aprobadas reportadas. No instala ni modifica la propuesta de roles rechazada. No copia clases de roles/usuarios del Equipo 1. El ZIP E1 revisado corresponde a 86111548a7659ffa3dab4fd6ee441bb70160b3d9: publica autorización comercial, no prueba delegada de pago. Por eso este flujo recibe el consentimiento directamente del comprador autenticado en Finanzas.

## Qué agrega

- Solicitud SQL inmutable vinculada al cliente de servicio, comprador canónico, negocio, pedido, importe, moneda y artículos.
- API de preparación y consulta. Crear o consultar no debita ni autoriza un pago.
- Pantallas propias: Finanzas → Compras por confirmar → Detalle → Confirmar contraseña → marcar consentimiento → Confirmar y pagar.
- Confirmación con sesión web autenticada, correo verificado, cuenta habilitada, UserSession vigente, contraseña confirmada recientemente y token de revisión ligado a la sesión, usuario y solicitud.
- Pago wallet mediante payWithWallet ya probado. Se guarda la compra, outbox y estado PAID bajo la misma transacción SQL. Si falla el guardado final se revierte todo.
- Reintento de confirmación devuelve la compra ya pagada. Nunca genera otra clave de cargo. Cancelación y vencimiento no debitan.
- Consulta por pedido para que Marketplace recupere el resultado de un timeout sin crear un checkout nuevo.
- Cliente y negocio permitidos explícitamente por configuración y cliente OAuth activo con scope vigente. Sin lista configurada, ningún servicio prepara ni consulta solicitudes.

No hay cobro automático de servidor a servidor. No se habilita el endpoint /marketplace/charges del paquete conjunto. No se crean cuentas, wallets, roles ni clientes. No se llama a Eq4 en las pruebas. El flag del emisor debe seguir false.

## Instalar

Descomprimir en C:\Proyectos\Campus-Digital-confirmacion-compras. Ejecutar uno por uno:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-confirmacion-compras\Apply-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital"
```

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Proyectos\Campus-Digital-confirmacion-compras\Verify-Changes.ps1" -ProjectPath "C:\Proyectos\Campus-Digital" -MigrateDevelopment
```

Apply comprueba los dos archivos que reemplaza (providers y Dashboard) y el servicio de pago requerido, respalda los originales fuera del proyecto y rechaza bases distintas. No modifica .env ni ejecuta migraciones. Verify valida sintaxis, migra la tabla nueva y requisitos de pruebas, ejecuta 13 pruebas nuevas más la batería enfocada de compras/devoluciones/outbox y hace npm.cmd run build. -FullFinancialSuite amplía la prueba a toda la suite financiera. Las bases de pruebas son únicamente campus_virtual_testing y campus_digital_financial_testing. El TestCase existente limpia Mongo de pruebas; no retirar las guardas.

No hay PHP, PowerShell, MongoDB ni SQL Server disponibles en el entorno donde se preparó este ZIP. NO se ejecutaron estas pruebas ni el build. La inspección del paquete es estática y debe completarse con Verify en tu equipo antes del commit. Las pruebas no certifican concurrencia SQL multiproceso ni una transacción distribuida SQL/Mongo.

## Habilitación posterior a las pruebas

Se entrega FINANCIAL_MARKETPLACE_ENABLED=false por defecto y la lista de clientes vacía. La pantalla muestra que no está habilitado; no se preparan solicitudes. No cambiar estos valores hasta verificar la entrega y acordar los IDs con Eq3.

Ejemplo de configuración futura en .env (los nombres svc_REAL y BUS_REAL son marcadores, no cuentas reales):

```dotenv
FINANCIAL_MARKETPLACE_ENABLED=true
FINANCIAL_MARKETPLACE_CLIENTS_JSON='{"svc_REAL":{"name":"Marketplace Campus Digital","businesses":{"BUS_REAL":"Nombre real del negocio"}}}'
FINANCIAL_MARKETPLACE_TTL_SECONDS=600
FINANCIAL_MARKETPLACE_PASSWORD_MAX_AGE_SECONDS=300
FINANCIAL_TEAM4_WEBHOOK_ENABLED=false
```

Usar un cliente de servicio registrado y activo con scopes financial:purchase:prepare y financial:purchase:read. Su secreto se entrega por canal privado; no se guarda en este ZIP ni en la lista de configuración. La lista identifica al integrador autorizado a declarar pedidos para esos negocios, no le otorga permiso para debitar usuarios ni equivale a una asignación de roles financieros. Los nombres del cliente/negocio de la pantalla salen de esta lista, no del cuerpo remoto.

Después de editar .env: php artisan config:clear. Reiniciar procesos persistentes. Mantener PHP serve y frontend configurados como en el proyecto. Para interfaces con npm.ps1 bloqueado usar npm.cmd.

Los 600 s de preparación y 300 s de contraseña reciente son valores técnicos iniciales configurables (rangos admitidos: 60–1800 s y 60–900 s). Cambiar la vigencia afecta nuevas solicitudes; el vencimiento original de una solicitud existente no se prolonga por reintentar. Los límites monetarios se evalúan en 2.10 usando la política financiera existente.

## Contrato nuevo para Equipo 3

### 1. Preparar la compra

POST /api/v1/financial/marketplace/payment-requests
Authorization: Bearer <token de servicio con financial:purchase:prepare>
Idempotency-Key: <clave estable del mismo checkout>
Accept y Content-Type: application/json

```json
{
  "buyer_id":"<ID canónico de la cuenta en Finanzas>",
  "order_id":"<el mismo ID usado para reserva y confirmación>",
  "business_id":"<negocio permitido>",
  "amount_cents":2500,
  "currency":"MXN",
  "description":"Resumen de la compra",
  "items":[{"product_id":"P-1","variant_id":null,"location_id":"L-1","quantity":1}]
}
```

amount_cents y quantity son enteros JSON. No enviar floats, precios convertidos con float, wallet_id, actor_id, URLs de retorno, estado de pago ni Idempotency-Key dentro del body. items puede omitirse si no hay una lista canónica acordada; nunca fabricar ubicación/variante. Cantidades/precios del pedido son responsabilidad del Marketplace autorizado; la validación estructural del receptor no los certifica ni consulta inventario. El comprador revisa el importe declarado y decide si paga.

Respuesta 201 en creación / 200 al repetir:

```json
{"data":{"payment_request_id":"<UUID solicitud>","order_id":"<pedido>","business_id":"<negocio>","status":"PENDING","amount_cents":2500,"currency":"MXN","payment_intent_id":null,"expires_at":"<ISO8601>","confirmation_path":"/finanzas/compras/solicitudes/<UUID solicitud>"}}
```

La misma clave o el mismo pedido del mismo cliente/negocio recupera la solicitud si el contenido canónico coincide; alterarlo devuelve 409. No prolongar, sustituir o crear otro pedido ante timeout. Las restricciones únicas arbitran carreras; si hay conflicto concurrente, consultar/reintentar la misma preparación, no otra clave de cobro.

### 2. El comprador confirma

Eq3 debe mostrar un enlace «Revisar y pagar en Finanzas» usando el origen web conocido de Finanzas + confirmation_path (ruta relativa). No seguir URLs enviadas por el usuario. El comprador debe iniciar sesión en Finanzas; no se presupone cookie compartida ni SSO entre aplicaciones. buyer_id debe coincidir con esa identidad canónica y con wallets.owner_id. No usar el ObjectId local de Eq3 como fallback, ni mapear cuentas por correo de forma implícita.

Las solicitudes también aparecen en Finanzas → Compras por confirmar. La pantalla exige confirmación reciente de contraseña y una casilla explícita con negocio e importe. No usa un botón genérico de «autorizar API». No se aceptan en el POST datos para elegir otra wallet, comprador o monto. La confirmación usa las protecciones CSRF del grupo web. El segundo factor de inicio de sesión que ya ofrece Fortify se conserva; este cambio no implementa ni afirma un segundo factor reciente por cada compra.

PAID indica débito financiero realizado, no inventario confirmado ni entrega. Tras pagar se abre el comprobante existente; pueden descargarse sus PDF como antes. Las devoluciones siguen su ciclo de solicitud/aprobación/ejecución existente, no se ejecutan por cancelar una solicitud pendiente.

### 3. Consultar resultado

GET /api/v1/financial/marketplace/payment-requests/by-order?order_id=<pedido>&business_id=<negocio>
Token con financial:purchase:read. Devuelve data con el mismo contrato, status PENDING, PAID, CANCELLED o EXPIRED. Solo consulta solicitudes del mismo cliente y negocios permitidos; ajenas/ausentes son 404. En PAID, payment_intent_id es el UUID público de PurchasePayment en minúsculas. payment_request_id no es payment_intent_id y ninguno se debe sustituir por otro al confirmar inventario.

Eq3 debe guardar el pedido antes de reservar, usar un ID único en reserva/preparación/confirmación y conservar su intento. Si consulta PAID puede continuar la confirmación de inventario con ese payment_intent_id. La outbox es asíncrona: Eq3 debe recuperar confirmaciones pendientes duraderamente si Eq4 aún no vio el evento; no basta un bucle HTTP de segundos. No llamar directamente a wallet debit ni marcar confirmado un pedido por recibir PENDING. No confiar en un status devuelto por el navegador.

Errores: 401 token ausente/inválido; 403 scope/lista/cliente revocado; 422 datos inválidos; 409 conflicto; 503 integración apagada o dependencia de identidad no disponible. No se expone el historial interno ni las sesiones por API.

## Garantías y límites

El dueño, la sesión y los permisos del cliente se revalidan al confirmar, también después de esperar el lock de la wallet. SQL y Mongo son bases distintas: no hay garantía de revocación linealizable compartida en una misma transacción distribuida. Una revocación posterior a la última comprobación no deshace un débito ya confirmado. No se cachean decisiones positivas.

Los listados y detalles son exclusivos del comprador; un rol embebido no habilita acceso a compras ajenas. Se audita usuario, cliente, sesión de identidad, hash de sesión nativa, IP y momento de confirmación de contraseña. No se almacenan contraseñas, tokens OAuth ni cookies de sesión. El token de revisión solo tiene sentido con la sesión autenticada; no es una credencial delegable para servicios.

Una solicitud vencida/cancelada no se reabre. Un pago confirmado no se cancela por la ruta de cancelación. La consulta PAID sigue siendo el resultado del cobro original aunque después exista una devolución: sus eventos y su historial financiero son independientes. No se reconstruye ni inventa historial anterior.

No se agregan workers ni callbacks automáticos de Eq3. La integración externa requiere que Equipo 3 implemente el nuevo contrato y que Equipo 4 resuelva los pendientes del receptor antes de activar el emisor. No se probaron llamadas reales entre equipos.

## Retiro

Restaurar providers y Dashboard del respaldo y retirar los nuevos archivos desconecta estas rutas. No eliminar la tabla si contiene solicitudes/auditoría. El down de esta migración elimina solo solicitudes; no revierte saldos, compras ni outbox. No usar rollback para deshacer pagos.
