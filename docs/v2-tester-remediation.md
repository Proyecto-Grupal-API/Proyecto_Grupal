# V2 — Matriz de correcciones del informe del tester

Base: `INFORME_EQUIPO3_CONTRA_DISENO_2026-10-09.md` (revisión estática de la versión mínima).

| Hallazgo del informe | Corrección en V2 | Estado / límite |
|---|---|---|
| Registro de negocios sin flujo operativo | Alta de solicitud, revisión, observaciones, aprobación y rechazo | Implementado; requiere pruebas con MongoDB |
| Propietario inicial y responsables no gestionados | Al aprobar se crea el negocio, asigna propietario y membresía owner; ruta para agregar gerente/cajero/inventario | Membresía local provisional; identidad Equipo 1 pendiente |
| Tienda sin editor ni publicación | Guardado de marca, descripción, URLs de imagen, políticas, horarios, puntos de entrega y publicación | Implementado; carga binaria de archivos no incluida, usa URL |
| Catálogo sin altas/bajas | Crear producto/servicio, actualizar campos y desactivar sin borrar historial | Alta/desactivación con UI; edición disponible por ruta API autenticada |
| Carrito sin quitar/vaciar y precio obsoleto | Quitar artículo/vaciar; revalidación de producto/precio al checkout | Implementado |
| Importes calculados con `float` | Conversión a centavos enteros para subtotales del carrito y formato decimal | Implementado en la ruta de carrito/checkout; se recomienda ampliar pruebas de frontera |
| Idempotencia no aplicada | Clave estable del checkout, búsqueda previa de pedido con la misma clave y respuesta del pedido existente | Evita duplicar; recuperación/reintento explícito de un pago fallido aún necesita endpoint de reintento |
| Mock podía marcar pedido pagado | `demo_authorized` y `awaiting_payment_validation` si el proveedor indica mock; liberación compensatoria de inventario intentada | No afirma cobro real; requiere probar contra APIs reales |
| Pedido solo visible al comprador | Panel y listado del vendedor limitados a negocios propios/asignados | Implementado con membresía local |
| Estados sin atención por vendedor | Transiciones controladas y registro de historial/auditoría | Implementado; los efectos de inventario/reembolso siguen dependiendo de las APIs externas |
| SPEI sin comprobante ni revisión | Envío de URL/referencia y resolución manual de administración con auditoría | Flujo de desarrollo; no verifica por sí mismo la transferencia bancaria |
| Openpay como opción sin cliente/callback | Se admite evidencia/revisión como flujo provisional y se documenta el faltante | Integración real pendiente: credenciales, cliente, webhooks y conciliación |
| Postventa sin acciones | Solicitud, evidencia, resolución y estados de reembolso pendiente | Reembolso/inventario/puntos reales pendientes de contrato y ejecución del equipo propietario |
| Falta de auditoría | Colección `commercial_audit_events`, actor, entidad, acción, transición, detalles y fecha | Implementado para acciones incluidas; debe probarse con los casos de aceptación |
| Índice de auditoría ausente | Índices para entidad, negocio, actor y fecha | Seeder best-effort; verificar en base limpia |
| `Index.vue` no declara `cart` en props | `cart: Object` declarado | Corregido |

## Riesgos y verificación pendiente
- No se ejecutó el proyecto completo ni se validaron los flujos contra APIs externas. Sintaxis PHP correcta no equivale a aceptación funcional.
- Los permisos de membresía son un control provisional de Equipo 3. En la integración final Equipo 1 debe resolver la autorización contextual.
- La carga de imágenes se acepta como URL; un flujo de subida segura a almacenamiento todavía debe configurarse.
- El reintento de pagos fallidos con la misma orden aún no está implementado como acción de usuario.
- El adaptador de Openpay real y sus callbacks no están implementados.
- Una devolución aprobada puede dejar `refund_pending`; no se finge un reembolso real.
- El estado de stock es una referencia local; el stock operativo pertenece al Equipo 4.
- Pruebas recomendadas: aprobación/rechazo de negocio; permiso entre negocios; método de pago no permitido; precio modificado antes del checkout; doble envío de idempotency key; inventario insuficiente; error de cobro; comprobante SPEI ausente/presente; cancelación y devolución; y verificación de auditoría.
