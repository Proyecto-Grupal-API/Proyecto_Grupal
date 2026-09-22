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

## Integración

Las APIs de integración están preparadas para Equipo 3 y Equipo 7, y para futuros contratos con Equipo 6/2. Las credenciales reales y secretos deben estar en `.env`, nunca en GitHub.

## Nota académica

El diseño fuente recomienda un monolito modular Laravel con contratos internos/eventos y establece que no es obligatorio crear microservicios separados. Si el profesor exige despliegues independientes, este dominio puede extraerse conservando los contratos HTTP definidos aquí.
