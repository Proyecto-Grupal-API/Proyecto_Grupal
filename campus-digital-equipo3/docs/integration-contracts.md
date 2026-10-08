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
