# MongoDB — Equipo 3

## Colecciones propietarias
| Colección | Propósito |
|---|---|
| `businesses` | Negocios, clasificación, propietario, estatus, visibilidad y métodos de pago |
| `business_members` | Personal y roles por negocio |
| `business_applications` | Solicitudes y revisión de alta |
| `storefronts` | Configuración pública de la tienda |
| `products` | Productos/servicios, variantes, atributos y precio |
| `carts` | Carritos por usuario |
| `orders` | Pedidos y trazabilidad comercial |
| `payment_intents` | Orquestación de pagos y referencias |
| `return_requests` | Solicitudes de devolución/postventa |

## Índices
Se crean en `MongoIndexesSeeder` para:
- email
- slug
- estado/visibilidad
- negocio
- comprador + fecha
- folio
- idempotency key
- referencias de pago

## Regla de propiedad
El marketplace no modifica ledger de wallet, stock operativo o puntos globales. Consume contratos de esos dominios.
