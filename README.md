# Campus Digital — Equipo 3 V2

**Marketplace · Negocios virtuales · Catálogo · Ventas · Pagos directos · Postventa**

Esta versión reorganiza el módulo de Equipo 3 a partir de la revisión estática del tester del 9 de octubre de 2026. Mantiene Laravel, Vue 3, Inertia y MongoDB, además de la interfaz Campus existente. Las colecciones de wallet, inventario y puntos siguen perteneciendo a los equipos responsables; Equipo 3 conserva únicamente su estado comercial y las referencias a esos sistemas.

## Requisitos
- PHP 8.2+
- Composer 2
- Node.js 20+
- MongoDB 7+ o Docker

## Instalación local
```bash
cp .env.example .env
composer install
php artisan key:generate
npm install
npm run build
php artisan campus:seed
php artisan serve --host=127.0.0.1 --port=8000
```
Abrir `http://127.0.0.1:8000/login`.

### Credenciales de desarrollo
- Usuario: `admin@campus.local`
- Contraseña inicial: `admin12345`

Son credenciales únicamente para la demo académica. Cambia `CAMPUS_ADMIN_PASSWORD` antes de iniciar una base nueva fuera de desarrollo y nunca publiques el `.env`.

## MongoDB con Docker
```bash
docker compose up --build
```
El compose local levanta MongoDB y la aplicación. Base por defecto: `campus_digital`. El seeder de índices es de mejor esfuerzo; revisa los logs y las colecciones cuando prepares un entorno limpio.

## Flujos incluidos en V2

1. **Registro y autorización de negocios:** envío de solicitud; revisión administrativa, observaciones, aprobación/rechazo; al aprobar se crea el negocio, se asigna su propietario inicial y se crea un borrador de tienda.
2. **Gestión comercial:** configuración de visibilidad y métodos de pago, publicación de la tienda solo si el negocio está aprobado y administración de integrantes por rol contextual (`manager`, `cashier`, `inventory`).
3. **Catálogo:** alta y desactivación de productos/servicios, precio decimal, categoría, URL de imagen, disponibilidad, atributos, variantes y stock de referencia.
4. **Carrito y checkout:** quitar líneas/vaciar carrito, volver a leer precios al checkout, cálculo en centavos enteros, validación de negocio/método de pago, clave de idempotencia y registro del historial de estado.
5. **Pedidos del vendedor:** panel por negocios asignados y transiciones permitidas de confirmado → preparación → listo → entregado, con cancelación y auditoría.
6. **SPEI directo:** pedido pendiente, envío de URL del comprobante/referencia y revisión manual autorizada. No se declara una transferencia real solo por crear el pedido.
7. **Openpay:** se conserva como método configurado y flujo de evidencia/revisión de desarrollo; el cliente real, los callbacks y la conciliación de Openpay siguen pendientes.
8. **Postventa:** solicitud con motivo/evidencia, resolución por propietario/gerente y marca de reembolso pendiente cuando aplica. El reembolso real y la liberación/ajuste de puntos requieren los contratos propietarios.
9. **Auditoría:** decisiones de autorización, cambios de estado, catálogo, pagos directos y postventa generan eventos en `commercial_audit_events`.

## Integraciones y estados simulados

`AppServiceProvider` vincula los gateways de Equipos 1, 2, 4 y 7 a adaptadores `MockTeam*`. Los resultados `MOCK-*` son simulaciones. En particular, un adaptador mock puede responder `authorized`, pero V2 guarda `demo_authorized` y deja el pedido en `awaiting_payment_validation`; **no marca el pedido como cobrado** ni afirma que se movió el saldo real.

Antes de una demo con efectos reales se deben sustituir los adaptadores por clientes de las APIs propietarias, validar la identidad canónica y probar errores, idempotencia y compensaciones:
- **Equipo 1:** identidad y permisos contextuales reales; V2 usa membresías locales como control provisional.
- **Equipo 2:** cobro/reembolso de wallet/bonos/pagos mixtos. Los métodos no implementados por el servicio financiero no se deben habilitar como cobro real.
- **Equipo 4:** reserva, consumo y liberación de inventario.
- **Equipo 7:** acumulación, canje y reverso de puntos.
- **Openpay:** perfil protegido por negocio, cliente del proveedor, callbacks firmados y conciliación.
- **SPEI:** instrucciones por negocio y validación manual del comprobante. La decisión manual no sustituye una confirmación bancaria.

No se debe leer ni modificar directamente las bases de datos de los equipos vecinos.

## Rutas principales
- `/tienda`: marketplace y carrito.
- `/tienda/negocios`: negocios visibles y configuración de negocios administrados.
- `/tienda/solicitudes`: solicitudes propias; revisión para administración.
- `/tienda/productos`: catálogo y alta de productos para negocios administrados.
- `/tienda/pedidos`: compras del usuario y comprobantes de pagos directos.
- `/tienda/vendedor`: panel de ventas por negocio.
- `/tienda/vendedor/pedidos`: atención de pedidos.
- `/tienda/postventa`: solicitudes de devolución.
- `/tienda/auditoria`: eventos comerciales (admin).

## Verificación
El paquete V2 se preparó con revisión de sintaxis PHP y validación de estructura/JSON. No se considera prueba de aceptación hasta ejecutar Composer, compilar Vue, iniciar MongoDB y recorrer los flujos de extremo a extremo. Revisa `docs/v2-tester-remediation.md` para pendientes y límites conocidos.
