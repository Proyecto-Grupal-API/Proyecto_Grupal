# Marketplace en la integración local 1–3–4–5–6

Se integra el dominio de `equipo3_negocios`, commit `b9534b9c7eb4da2783a29f210c4a9c69b36e6ff4`, en `/home/daniel/campus_digital_integracion_e1`. Se conserva la aplicación actual de E1 y el entorno principal. No se publica a GitHub.

## Alcance

Catálogo, tiendas, solicitudes y aprobación de negocios, productos/servicios, carrito, pedidos, panel vendedor, postventa y auditoría. Las nueve vistas de E3 utilizan la navegación actual. Los datos propios usan la conexión `marketplace` y colecciones `marketplace_*`; Inventarios conserva su base separada.

E3 consulta a E1 por API para comprobar membresía y capacidades vigentes de cada negocio. El propietario almacenado en Marketplace y el administrador global no sustituyen esa autorización. El rol administrativo global, evaluado por el middleware oficial de E1, sirve para revisar solicitudes institucionales. El solicitante original recibe la propiedad mediante la API publicada de E1, una vez aprobada la solicitud.

Los productos físicos se relacionan con producto y ubicación de E4 por API firmada. Un pedido reserva existencias durante 15 minutos, queda pendiente de pago y permite cancelar. El scheduler revisa reservas desconocidas y liberaciones pendientes. Una respuesta perdida no produce otro pedido ni otro intento con una clave distinta. Los precios se calculan en centavos y se comprueban de nuevo al confirmar el carrito.

Ningún método cobra fondos, acepta manualmente un comprobante como pago, fabrica reembolsos ni otorga puntos. La gestión de integrantes espera la API de escritura de Identidad; no se crean roles locales paralelos.

## Activación local completada

El comando de preparación es `app/Console/Commands/PrepareMarketplaceIntegration.php`:

```bash
php artisan integracion:preparar-marketplace --demo
```

Su ejecución se limita al alterno local y:

1. Crea un cliente E3→E1 con `identity:assignments:read`, `identity:authorization:check` e `identity:business-owner:provision`.
2. Añade únicamente ese cliente a `OAUTH2_BUSINESS_OWNER_CLIENTS`, conservando los clientes existentes. Esto permite asignar al solicitante original como propietario de un negocio aprobado por Marketplace.
3. Crea un cliente E3→E6 con `community:organizations:read`.
4. Guarda las credenciales en el `.env` ignorado por Git, sin imprimirlas.
5. Crea índices propios de Marketplace y vincula el catálogo demo al negocio de E4 ya autorizado por E1. No cambia contraseñas, MFA ni asignaciones revocadas.

La activación fue autorizada explícitamente y ejecutada en el alterno. Se vincularon 10 artículos demo al inventario existente. La cuenta `identidad@campus.test` conserva la asignación de propietario ya vigente del negocio `BUS-CD-SOUV-001`; no se reprovisionó ni se cambiaron contraseñas/MFA.

## APIs de integración

- E1: `GET /api/v1/identity/assignments`, `POST /api/v1/identity/authorization/check`, `POST /api/v1/identity/business-owner/provision`.
- E6: `GET /api/v1/comunidad/organizaciones/comercio?subject_id=...`, scope `community:organizations:read`. Comprueba membresía activa y presidencia vigente antes de solicitar una tienda de asociación; se vuelve a comprobar al aprobarla.
- E3: `GET /api/v1/marketplace/catalog` y `GET /api/v1/marketplace/businesses`, scope `marketplace:read`. Solo información comercial pública de tiendas aprobadas/publicadas, paginada.
- E4: `POST /api/equipo4/integration/businesses`; lecturas y reservas en `/api/equipo4/businesses/{businessId}/...`. El ámbito está firmado en el path, junto al método y cuerpo. Las rutas previas de E4 siguen disponibles.
- E4: `POST .../integration/reservation-status`, consulta idempotente de una reserva por `order_id` y su clave original. Permite recuperar respuestas perdidas sin adivinar el resultado.

## Límites

Los resultados desconocidos permanecen pendientes de revisión hasta que E4 confirma qué ocurrió. Un proceso interrumpido durante una escritura nativa de E4 puede requerir revisión manual; no se declara certificación de consistencia distribuida. Los pedidos vencidos no admiten comprobantes nuevos ni preparación/entrega. La confirmación de ventas queda pendiente del contrato y la autoridad financieros de E2.

## Validación

- E3: **33 pruebas**, 224 assertions. Cubre nueve vistas, E1 vigente/revocado, separación de propietario/revisor, aprobación y reintento, cargos E6, productos físicos/servicios, carrito, centavos, cambio de precio, idempotencia, cancelación, vencimiento, resultados desconocidos y APIs de catálogo.
- E4: **43 pruebas**. Incluye firma/ámbito, fechas explícitas de vencimiento y resolución correcta del ID de reserva cuando la ruta contiene también el ID del negocio.
- E1/E5/E6: **213 pruebas**, 1547 assertions, incluyendo los contratos de negocio de E1 y la regresión completa de Comunidad.
- **Total: 289 pruebas satisfactorias**. Se ejecutaron de forma serial contra `campus_virtual_testing`; el demo conserva su base separada.
- Vite: compilación exitosa. Pint aplicado únicamente a los archivos nuevos de E3 y el puente de integración.
- HTTP real: E1 confirma membresía/capacidad; E4 reserva una unidad sin consumir `on_hand`, un reintento devuelve el mismo pedido y cancelar restaura `available`/`reserved`; E6 responde la consulta de organizaciones. La prueba de elegibilidad del cliente de provisión en E1 devuelve 422 para un sujeto inexistente, sin crear asignaciones.
- Navegador aislado: `/tienda` sin sesión redirige al login de E1; formulario presente y cero errores JavaScript. Las vistas autenticadas se verificaron mediante pruebas de Inertia; esta comprobación de navegador no sustituye una sesión interactiva completa.

Los pedidos creados para comprobar el HTTP real quedaron cancelados y con su inventario liberado. No se marcaron pagados ni se modificó el stock físico.

## Complementos

Agent Skills: implementación por incrementos y comprobación de contratos/pruebas. Ponytail: reutilización del cliente HTTP existente y dos servicios concretos de Marketplace; sin adaptadores financieros ficticios ni roles locales paralelos. Graphify: sus herramientas están disponibles, pero el servidor requiere seleccionar un workspace autorizado para consultar el repositorio; no se atribuye una revisión de grafo a esta integración.

## Respaldo

Se conserva un snapshot del código integrado, sin `.env`, claves, `.git`, dependencias, cachés ni datos de MongoDB: `/home/daniel/campus_integracion_respaldos/integracion_1_3_4_5_6_2026-10-14.zip`. El respaldo temporal citado antes del reinicio ya no estaba disponible. Este snapshot conserva el estado actual; no se presenta como respaldo anterior a E3.

Acceso: `http://127.0.0.1:8086/tienda`. La navegación superior incluye Tienda; las organizaciones administrables tienen el enlace para solicitar su tienda.
