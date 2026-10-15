# Integración local de Identidad, Inventarios, Servicios y Comunidad

Fecha: 2026-10-13T23:45:43-06:00. Proyecto alterno: `/home/daniel/campus_digital_integracion_e1`.

## Resultado

Inventarios del equipo 4 queda incorporado en la aplicación local 1–5–6, con sesión de E1, autorización real por API, datos MongoDB propios, vistas Vue y endpoints de servicio registrados. Acceso: [Inventarios](http://127.0.0.1:8086/equipo4). También aparece en la navegación de Campus Digital.

Origen: rama `Modulo_4`, commit [`1dec1fc7f0ebe027101ad8a645a0617e766228b2`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/1dec1fc7f0ebe027101ad8a645a0617e766228b2). Se importó su dominio, no su aplicación/infraestructura completa. El proyecto principal `/home/daniel/campus_digital` y su configuración no se editaron. No se hizo commit, merge ni push.

## Componentes incorporados

15 vistas operativas: dashboard, productos, souvenirs, categorías, almacenes/ubicaciones, inventario, kardex, proveedores, compras, recepciones, costos, reservas, devoluciones, alertas y conteos. La pantalla de acceso no autorizado permite elegir negocio y volver a Campus Digital.

Las clases se ubican en `InventoryModule` dentro de modelos, servicios, controladores, requests, middleware, APIs y comandos. Los datos usan la conexión `inventory`, sobre el mismo servidor MongoDB del contenedor alterno y la base `campus_integracion_inventarios`. Evita conflictos con productos/negocios del futuro Marketplace y con los datos de E1/E5/E6.

En pruebas, ambas conexiones usan exclusivamente `campus_virtual_testing`, compatible con la protección destructiva del TestCase existente. No se ejecutan pruebas contra las bases de demostración.

## Autorización y actor

E4 consume mediante OAuth los contratos oficiales de E1:

- `GET /api/v1/identity/assignments`, scope `identity:assignments:read`.
- `POST /api/v1/identity/authorization/check`, scope `identity:authorization:check`.

Se usa el ID del usuario de la sesión, en el negocio seleccionado. No hay usuario por defecto, cambio de usuario por URL ni consulta del directorio de E1 desde Inventarios. La caché compartida sólo conserva tokens; asignaciones y decisiones se reutilizan dentro de una petición y se vuelven a consultar en la siguiente.

Los permisos finos propios de E4 se subordinan a las capacidades vigentes de E1. `business_owner` se presenta como propietario; `business_manager` conserva operación/gestión de inventario sin adquirir `business.manage`. Aprobar/cancelar compras y borrar catálogo requiere la capacidad de propietario. `inventory_manager` puede operar inventario y emitir solicitudes de compra, pero no autorizarlas.

**Buyer pendiente de definición en E1:** el rol existe, pero el catálogo publicado no le concede una capacidad persistente que permita ejecutar esas acciones de Inventarios. No se añadió un grant inventado ni un fallback. El administrador global tampoco recibe autoridad sobre todos los negocios por ser administrador.

El selector admite negocios habilitados en el registro propio `inventory_businesses`; se valida autorización en E1 antes de cambiar de contexto. Controladores y servicios resuelven el negocio al ejecutar la operación, evitando capturar un ID incorrecto antes de los middleware. Las firmas externas siguen ligadas al negocio configurado para el cliente; cambiar una query/header no cambia su ámbito.

Ajustes, autorizaciones, recepciones, conteos, alertas y movimientos conservan el actor real de la sesión. El cuerpo de una petición no puede reemplazarlo. Las operaciones de servicio/scheduler tienen actor de servicio o SYSTEM, separado de una persona.

## APIs registradas

Se preservan los paths/headers y el algoritmo HMAC descritos por E4. Se separan las claves de los consumidores E3/E2/E7; ninguna se entrega al navegador.

- Lecturas para E3: `/api/equipo4/products`, `warehouses`, `locations`, `inventory`, `movements`, `suppliers`, `purchase-orders`. Requieren firma y filtran por negocio.
- E3: `/api/equipo4/integration/availability`, `reservations`, `reservations/{id}`, `reservations/{id}/confirm`, `reservations/{id}/release`, `returns`, `transfers`, `adjustments`.
- E7: `/api/v1/inventory/availability`, `reservations`, `reservations/{id}`, `reservations/{id}/confirm`, `reservations/{id}/release`.
- E2: `POST /api/equipo4/webhooks/payment-status`. **Deshabilitado hasta cerrar y conectar Finanzas.**

La firma sigue siendo HMAC-SHA256 de `timestamp|METHOD|/path|sha256(body)` con `X-Team-Timestamp` y `X-Team-Signature`. GET debe firmar el cuerpo efectivamente enviado; con cuerpo vacío su hash es `sha256('')`. No se copió el secreto compartido del prototipo. Las claves nuevas quedan en `.env` y no se imprimen ni se versionan.

Las rutas de reservas E3 y el webhook E2 tenían controladores/documentación en la fuente pero no registro en el archivo de rutas publicado; ahora están registrados dentro del grupo API, sin depender de cookies/CSRF del navegador.

Reserva/liberación/vencimiento funcionan. La confirmación comercial sigue requiriendo un pago registrado por Finanzas; no se fabrican pagos, reembolsos ni puntos. Registrar E7 aquí prepara su adaptador, no equivale a instalar el módulo 7 o conectar sus reglas/canje.

## Correcciones necesarias para integrar

- Imports agrupados de modelos se adaptaron al namespace de Inventarios, incluido el catálogo de servicio.
- Fechas escritas directamente con el driver nativo usan `UTCDateTime`. El seeder anterior producía documentos vacíos al insertar DateTime PHP. La migración de normalización corrige fechas recuperables; para timestamps vacíos del catálogo usa la fecha de creación conservada en ObjectId. No inventa vencimientos financieros.
- Crear/editar una compra no permite saltarse el permiso de autorización enviando `AUTORIZADA` o `CANCELADA` en el formulario.
- Una recepción parcial deja la compra autorizada con unidades pendientes; sólo termina al recibir todas las partidas. Se validan partidas del negocio/orden, duplicados y cantidades máximas. El modal consulta el pendiente real. Un lock por negocio/orden evita dos recepciones simultáneas normales; no sustituye una certificación de consistencia bajo fallos/distribución.
- Se reparó el propietario de la caché/storage del alterno para servidor y scheduler. Ejecutar comandos con el usuario `1000:1000` evita volver a crear archivos root.
- Se incorporaron los cuatro archivos de ejecución/vista de la corrección final de consentimientos de E1 `86111548a7659ffa3dab4fd6ee441bb70160b3d9`, cuyos originales coincidían con su padre, y sus dos archivos de pruebas. E5/E6 conservan sus adaptaciones.

## Preparación y demostración

Con Docker iniciado, dentro del servicio alterno, como UID 1000:

```bash
php artisan integracion:preparar-inventario --demo
```

Prepara índices, un negocio demo, catálogo/almacenes/stock/proveedores y un cliente OAuth E4→E1. Los scripts mongosh de la entrega standalone no se instalaron: apuntaban a otra base y se sustituyen por las migraciones/comandos del entorno integrado.

Para probar el panel usa **`identidad@campus.test`**, con su contraseña y flujo de autenticación existentes. Un seeder explícito de Identidad le asigna `business_owner` y membresía para `BUS-CD-SOUV-001` (Souvenirs del Campus), sólo como fixture local. No cambia contraseñas ni desactiva MFA. Repetir la preparación conserva las credenciales y no reactiva asignaciones que se hayan revocado.

Variables propias: `INVENTARIO_IDENTIDAD_API_URL`, `INVENTARIO_IDENTIDAD_CLIENT_ID`, `INVENTARIO_IDENTIDAD_CLIENT_SECRET`, `INVENTARIO_EQ3_SECRETO`, `INVENTARIO_EQ2_SECRETO`, `INVENTARIO_EQ7_SECRETO`, `INVENTARIO_DATABASE`, `TEAM4_BUSINESS_ID`, `TEAM4_API_BUSINESS_ID`. `INVENTARIO_PAGOS_HABILITADOS` permanece falso por defecto; habilitarlo requiere el contrato/emisor E2 real.

Scheduler: `inventario:expirar-reservas` cada cinco minutos, con exclusión de ejecuciones simultáneas. Recorre los negocios activos, libera reservas de los motores propios y prepara el evento local de expiración para E7 cuando corresponda; no afirma entrega central de auditoría.

## Verificación realizada

- **41 pruebas E4, 369 aserciones:** 15 páginas, sesión, permisos vigentes/revocación, contexto, actor, propietario vs gerente, caídas de E1, firmas, siete lecturas, reservas/liberación/idempotencia exacta, vencimiento, pagos pendientes, compras y recepción total/parcial, cantidades y fechas heredadas.
- **196 pruebas de regresión E1/E5/E6, 1444 aserciones:** StudentServices, integración Servicios-Comunidad, avisos, persistencia/outbox de servicios E1 y contratos business de E1. Los grupos no se solapan con E4: total **237 pruebas y 1813 aserciones**.
- HTTP real dentro del contenedor: asignaciones y checks OAuth de E1, 43 permisos efectivos del propietario demo y catálogo HMAC E4 con 200/10 productos. La prueba de transporte real se ejecutó sin Http::fake.
- Preparación local repetida sin generar nuevos clientes ni sobrescribir existencias; ejecución de avisos existentes terminó correctamente (1 registro revisado, 0 pendientes).
- Vite compilado y sintaxis PHP comprobada. No se afirma una prueba visual manual de todos los formularios ni ejecución completa de las suites de los siete módulos.

## Recuperación y próximos pasos

Respaldo del código anterior, sin `.env`, dependencias o datos: `/tmp/campus-pre-integracion4-20261014.zip`; inventario de hashes: `/tmp/campus-pre-integracion4-manifest.json`. Son archivos temporales y deben conservarse en otro lugar si se desea retención permanente. No borrar los avances 1–5–6 al volver atrás: el respaldo los incluye.

La integración queda local, sin publicar. Siguiente fase: incorporar negocios/catálogo/pedidos pendientes de E3 y sustituir sus mocks de identidad/inventario por estos contratos. E2 y el rol buyer de E1 se coordinan en paralelo; los efectos financieros y las recompensas reales siguen pendientes de sus propietarios.
