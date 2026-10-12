# Contratos de integración pendientes

## Equipo 1 — Identidad
Entrada mínima:
```json
{
  "user_id": "uuid",
  "status": "active",
  "roles": ["owner", "manager"]
}
```

## Equipo 2 — Cobro
Solicitud:
```json
{
  "payment_intent": "uuid",
  "order_id": "uuid",
  "amount": 399.00,
  "method": "wallet|bonus|points|mixed"
}
```
Respuesta:
```json
{
  "status": "authorized|rejected|pending",
  "reference": "external-reference"
}
```

## Equipo 4 — Inventario
Reserva:
```json
{
  "order_id": "uuid",
  "lines": [
    {"product_id":"uuid","quantity":1}
  ]
}
```

## Equipo 7 — Recompensas
Venta confirmada:
```json
{
  "order_id":"uuid",
  "business_id":"uuid",
  "buyer_id":"uuid",
  "total":399.00
}
```

Estos contratos son una base provisional para que el Equipo 3 pueda desarrollarse sin bloquearse. Deben alinearse en conjunto cuando los equipos publiquen sus APIs reales.


## V2: semántica de mocks y autorización

Los adaptadores `MockTeam*` son exclusivamente para desarrollo. Aunque un mock de pago responda `authorized`, el checkout de V2 no registra el pedido como pagado: conserva `demo_authorized` en el intento de pago y `awaiting_payment_validation` en el pedido. Las referencias `MOCK-*` no prueban una transacción real.

Los permisos comerciales locales se resuelven por `BusinessMember` y `owner_id` como control provisional. Antes de la integración final, Equipo 1 debe resolver la identidad canónica y las acciones autorizadas sobre cada negocio. No se deben consultar directamente colecciones de otros equipos.

El módulo registra `commercial_audit_events` para las decisiones y transiciones incluidas en V2. Las compensaciones externas pueden quedar pendientes cuando el contrato de inventario, wallet o puntos no esté disponible; esos estados se documentan y no se deben presentar como reembolsos o movimientos ya ejecutados.
