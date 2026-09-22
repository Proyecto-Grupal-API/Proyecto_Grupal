# Seguridad e integración — Equipo 4

## Seguridad aplicada en el baseline

1. Validación estricta con FormRequest.
2. Whitelists de valores para operaciones sensibles.
3. No se construyen filtros MongoDB a partir de operadores enviados por el usuario.
4. Consultas Eloquent con parámetros, evitando `$where`, JavaScript evaluable y operadores dinámicos no validados.
5. CSRF para formularios web Laravel.
6. Rate limiting en APIs.
7. HMAC para contratos de integración entre equipos.
8. Ventana temporal para evitar replay.
9. Idempotencia para reservas y reintentos.
10. Auditoría e integración con `audit_logs` / `integration_logs`.
11. `.env` fuera de Git y secretos únicamente mediante variables de entorno.
12. No se almacenan contraseñas de otros equipos ni credenciales de Atlas en el repositorio.

## Roles

El mínimo local del prototipo contempla:

- `administrador`: administración de inventarios, compras, autorizaciones y ajustes.
- `alumno`: consulta de disponibilidad y operaciones permitidas por negocio.

Equipo 1 es la fuente de verdad de roles en producción. Equipo 4 no debe duplicar la lógica de identidad.

## Microservicios / contratos

Aunque el documento de diseño recomienda un monolito modular por viabilidad académica, la interacción entre dominios se implementa mediante contratos HTTP preparados para extraerse a un microservicio independiente.

Contratos mínimos:

- `GET /api/equipo4/integration/availability`
- `POST /api/equipo4/integration/reservations`

La solicitud firmada usa:

`timestamp|method|path|sha256(body)`

y HMAC-SHA256 con un secreto compartido fuera del repositorio.

## Reglas de propiedad

Equipo 4 es dueño de inventario, abastecimiento y sus reglas centrales. Los demás equipos consumen contratos y no escriben directamente en las colecciones de Equipo 4.

## Devoluciones

Se contemplan dos flujos distintos:

- `supplier_returns` / `supplier_return_items`: devolución de mercancía de Equipo 4 al proveedor, normalmente asociada a una orden/recepción.
- `customer_returns` / `customer_return_items`: devolución de mercancía de un cliente/alumno hacia Equipo 4, asociada a una venta y con resolución `RESTOCK`, `QUARANTINE`, `REPLACE` o `REFUND`.

Ambos flujos deben generar el movimiento de inventario correspondiente cuando sean confirmados. La devolución de cliente no debe asumirse automáticamente como reingreso: se revisa la condición y la resolución autorizada.
