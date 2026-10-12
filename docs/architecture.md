# Equipo 3 — Arquitectura

## Alcance
El módulo implementa el dominio **Marketplace, Negocios Virtuales, Ventas y Pagos Directos**.

Se mantiene desacoplado de los dominios vecinos mediante interfaces:
- `Team1IdentityGateway`
- `Team2PaymentGateway`
- `Team4InventoryGateway`
- `Team7RewardsGateway`

Mientras las APIs reales no estén disponibles, el proyecto usa adaptadores `MockTeam*`. Cuando cada equipo publique su contrato, se sustituye el binding por un cliente HTTP/event-driven sin cambiar los casos de uso de Marketplace.

## Módulos
1. Registro y clasificación de negocios
2. Flujo de autorización
3. Constructor de tienda virtual
4. Catálogo de productos y servicios
5. Carrito, pedido y estados
6. Checkout y orquestación de pago
7. Configuración SPEI directa
8. Adaptador Openpay por negocio
9. Panel del vendedor
10. Postventa comercial

## Rutas principales
- `GET /tienda`
- `GET /tienda/negocios`
- `GET /tienda/productos`
- `GET /tienda/pedidos`
- `POST /tienda/carrito`
- `POST /tienda/checkout`
- `GET /api/tienda/catalogo`
- `GET /api/tienda/negocios`

## Decisiones
- MongoDB como persistencia solicitada para este módulo.
- No se replica stock real en Marketplace; `stock_snapshot` es únicamente informativo para demo.
- El pago real pertenece al dominio del equipo correspondiente.
- La reserva de inventario pertenece al Equipo 4.
- La acumulación/reverso de puntos pertenece al Equipo 7.
- Los secretos de proveedores no se muestran en UI ni logs.
