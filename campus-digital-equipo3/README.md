# Campus Digital — Equipo 3

**Marketplace · Negocios Virtuales · Ventas · Pagos Directos**

Implementación académica alineada con el documento funcional de Campus Digital. La aplicación está preparada para crecer hacia una arquitectura de microservicios, aunque este módulo puede ejecutarse como una aplicación Laravel independiente durante el desarrollo.

## Stack
- Laravel 11
- Vue 3
- Inertia.js
- Fortify
- MongoDB
- Tailwind CSS
- Adaptadores de integración para Equipos 1, 2, 4 y 7

## Requisitos
- PHP 8.2+
- Composer
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
php artisan serve
```

Abrir `http://localhost:8000/login`.

### Credenciales demo
- Usuario: `admin@campus.local`
- Contraseña: `admin12345`

Cambiar estas credenciales antes de cualquier uso fuera del entorno académico.

## MongoDB con Docker

```bash
docker compose up -d mongodb
```

La aplicación usará:
- URI: `mongodb://127.0.0.1:27017`
- Base: `campus_digital`

Si se usa todo el stack Docker:

```bash
docker compose up --build
```

## Flujo demo
1. Iniciar sesión como admin.
2. Seleccionar **Tienda**.
3. Explorar negocios.
4. Agregar un producto al carrito.
5. Abrir carrito.
6. Seleccionar Wallet, bono, puntos, pago mixto, SPEI u Openpay.
7. Confirmar checkout.
8. Consultar **Mis pedidos**.

Los métodos Wallet/bono/puntos utilizan adaptadores mock para no bloquear el desarrollo mientras el Equipo 2 y Equipo 7 terminan sus APIs. SPEI/Openpay se mantienen como estados de validación/configuración.

## Integración futura
Los contratos están documentados en `docs/integration-contracts.md`. Cuando existan las APIs de los demás equipos, sustituir los bindings de `AppServiceProvider` por clientes HTTP, mensajería o eventos según el contrato común.

## Datos y límites
Marketplace es dueño de sus colecciones. No se implementan escrituras directas sobre wallet, inventario o puntos. La propiedad de esos dominios permanece con los equipos correspondientes.

## Estructura

```text
app/
  Http/Controllers/
  Models/
  Providers/
  Services/Integrations/
database/seeders/
resources/js/
  Layouts/
  Pages/Auth/
  Pages/Marketplace/
docs/
docker/
routes/
```

## Nota sobre el documento de diseño
El documento base recomienda PostgreSQL como persistencia general del producto, pero este entregable utiliza MongoDB porque fue solicitado específicamente para el Equipo 3. La arquitectura mantiene la separación de dominio para que la persistencia pueda sustituirse o coexistir en una futura descomposición por microservicios.
