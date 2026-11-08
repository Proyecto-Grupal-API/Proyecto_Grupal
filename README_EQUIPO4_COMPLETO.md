# Campus Digital — Equipo 4 completo

Dominio: Inventarios, Proveedores, Compras y Abastecimiento.

## Módulos

4.1 Inventario multi-negocio
4.2 Almacenes y ubicaciones
4.3 Kardex y movimientos
4.4 Proveedores
4.5 Órdenes de compra
4.6 Recepción y devolución a proveedor

Devoluciones adicionales requeridas por el proyecto: devolución de cliente, mediante `customer_returns` y `customer_return_items`, integrada con movimientos de inventario.
4.7 Costos y precios de referencia
4.8 Reserva de stock para pedidos/canjes
4.9 Alertas de reabastecimiento
4.10 Conteos y ajustes de inventario
4.11 Operación especializada de souvenirs

## Instalación

1. `composer install`
2. Copiar `.env.example` a `.env`
3. Configurar `DB_CONNECTION=mongodb`, `DB_URI` y `DB_DATABASE`.
4. `php artisan key:generate`
5. Instalar/extender MongoDB PHP compatible con la versión de PHP instalada.
6. `npm install`
7. `npm run dev`

## MongoDB

Inicialización:

`mongosh "mongodb://127.0.0.1:27017" database/mongo/team4_init.js`

Datos:

`mongosh "mongodb://127.0.0.1:27017" database/mongo/team4_seed.js`

Fixtures adicionales para probar aislamiento multi-negocio y merchandising:

`mongosh "mongodb://127.0.0.1:27017" --file database/mongo/team4_specialized_demo.js`

El script es idempotente y no reemplaza existencias existentes. Crea el negocio
de prueba `BUS-CD-SOUV-DEMO-002` con el SKU `SOUV-MULTI-TEST-001`, y agrega al
negocio principal `BUS-CD-SOUV-001` el souvenir oficial
`SOUV-EDICION-OFICIAL-2026`, con cuatro variantes en `LOC-DEMO-OFICIAL`,
colección, temporada, paquete y preventa limitada. Estos datos sirven para
demostrar alcance por negocio y administración de merchandising sin depender
de las credenciales de los equipos externos.

Migraciones versionadas de MongoDB (ejecutar en orden; se pueden repetir):

`mongosh "mongodb://127.0.0.1:27017/campus_digital" --file database/mongo/migrations/001_team4_inventory_indexes.js`

`mongosh "mongodb://127.0.0.1:27017/campus_digital" --file database/mongo/migrations/002_team4_alerts_and_approvals.js`

## Integración

Las APIs de Equipo 3 y Equipo 7 tienen contratos y pruebas locales. La activación real de Equipo 1 requiere credenciales; Equipo 2 y 3 dependen del emisor/cliente real de sus equipos; Equipo 6 requiere que se acuerde y publique su contrato de abastecimiento. Las credenciales y secretos deben estar en `.env`, nunca en GitHub. Las alertas necesitan `TEAM4_ALERT_EMAIL` o un correo responsable en el documento del negocio.

## Nota académica

El diseño fuente recomienda un monolito modular Laravel con contratos internos/eventos y establece que no es obligatorio crear microservicios separados. Si el profesor exige despliegues independientes, este dominio puede extraerse conservando los contratos HTTP definidos aquí.
