# Informe del Equipo 3 frente al diseño de Campus Digital

**Fecha de revisión:** 9 de octubre de 2026, horario de México.  
**Repositorio:** Proyecto-Grupal-API/Proyecto_Grupal.  
**Rama revisada:** `equipo3_negocios`.  
**Commit:** `85a63cbf81d34605c0bd8d6470b730e9b26fde54`.  
**Fecha del commit:** 7 de octubre de 2026, 18:13:03 −06:00.  
**Mensaje:** Primera integración funcional de negocios.

**Documento de referencia:** [Campus_Digital_Diseno_Modulos.pdf](/home/daniel/campus_digital/docs/Campus_Digital_Diseno_Modulos.pdf), versión 1.0: sección 10, página 9; reglas comunes, páginas 14–15; definición de terminado y criterio de éxito del Equipo 3, página 16.

## 1. Resultado y alcance de la revisión

La entrega publicada contiene una base comercial con persistencia en MongoDB: consulta de negocios y productos, adición al carrito, creación de pedidos e intentos de pago y consulta de compras del usuario. Sin embargo, gran parte de la administración del negocio y del ciclo posterior a la compra todavía no aparece como flujo operativo en el código revisado.

Por eso se mantiene **aproximadamente 30% como estimación orientativa del alcance completo definido en el documento**. Esta cifra compara capacidades entregadas con requisitos; no mide horas trabajadas ni evalúa a las personas del equipo. Tampoco es una calificación oficial del profesor.

Se revisaron rutas, controladores, modelos, proveedores, adaptadores, seeders, páginas Vue, pruebas y documentación. La revisión es estática: **no se levantó su aplicación ni se ejecutaron sus pruebas**, por lo que no se afirma que la demo completa funcione de principio a fin. “No encontrado” significa que no aparece en esta entrega; podría existir trabajo adicional sin publicar.

Se verificó nuevamente el encabezado de la rama remota. También se comparó el ZIP recibido, `Campus-Digital-Equipo3-Marketplace.zip`, con la carpeta publicada: **sus 70 archivos coinciden al normalizar CRLF/LF**, incluidos archivos ocultos. No se encontró un avance adicional dentro de ese ZIP.

## 2. Qué sí se reconoce como avance

- Modelos MongoDB para negocios, integrantes, solicitudes, tienda, productos, carrito, pedidos, intentos de pago y devoluciones.
- Seeder con tres negocios y nueve productos/servicios de ejemplo, integrantes y configuración de tiendas. Incluye una solicitud pendiente como dato de muestra. [Datos de ejemplo][seeder]
- Seeder para índices de consulta en las colecciones. Su existencia se reconoce; la creación desde una base limpia no se verificó ejecutándolo. [Índices][indices]
- Consulta de catálogo y negocios mediante dos endpoints JSON. [Consultas][catalogo]
- Validación básica y persistencia del carrito; creación de pedido, folio, intento de pago y estados iniciales. [Carrito][carrito], [checkout][checkout]
- Cuatro páginas de Marketplace —inicio, negocios, productos y pedidos— más una de login: **cinco páginas Vue en total**. El conteo anterior de seis páginas fue incorrecto.
- Interfaces para sustituir integraciones simuladas y documentación de sus necesidades. [Interfaces][interfaces], [documentación][contratos]

Estos elementos dan una base para continuar. Los registros precargados y los campos de los modelos, por sí solos, no permiten que un usuario real administre todo el negocio.

## 3. Comparación de las diez funciones obligatorias

Las funciones y sus pesos proceden de la página 9 del PDF. Las estimaciones de la última columna corresponden a la evaluación anterior del backend, revisadas ahora contra el código. Son aproximaciones, no porcentajes medidos por pruebas.

| Función del documento | Peso | Evidencia publicada | Parte que falta para completar el requisito | Estimación backend |
|---|---:|---|---|---:|
| **3.1 Registro y clasificación de negocios** | 4 | Modelo con tipo, propietario, estado y visibilidad; negocios y propietario precargados; página que los lista. [Modelo][negocio], [vista][vista_negocios] | Alta/edición desde formulario o API, gestión de responsables y verificación con reglas y autorización. No aparecen rutas para estas operaciones. | 35% |
| **3.2 Flujo de autorización de negocio** | 4 | Modelo de solicitud con estado y observaciones; solicitud pendiente creada por el seeder. [Solicitud][solicitud], [seeder][seeder] | Envío de solicitud, revisión, observaciones del revisor, aprobación, suspensión y baja con historial. No hay un flujo administrativo conectado. | 15% |
| **3.3 Constructor de tienda virtual** | 4 | Modelo y datos precargados de marca, descripción, políticas, horarios y entrega. [Modelo][tienda], [seeder][seeder] | Editor de tienda, carga de imágenes, configuración de contactos y publicación por su responsable. No se encontró una operación para guardar esos cambios. | 30% |
| **3.4 Catálogo de productos y servicios** | 4 | Modelo de productos/servicios, catálogo consultable, búsqueda JSON y tablas de productos. [Modelo][producto], [consulta][catalogo], [vista][vista_productos] | Crear, editar y desactivar artículos; administrar imágenes, categorías, variantes, atributos y configuración de precios/impuestos. Tener campos `variants` y `attributes` no implementa su gestión. | 55% |
| **3.5 Carrito, pedido y estados** | 5 | Carrito persistente, acumulación de cantidades, pedido con líneas, punto de entrega, folio y estados iniciales; listado del comprador. [Carrito][carrito], [pedido][checkout] | Modificar/eliminar líneas, atención del pedido por el vendedor, transiciones hasta entrega/cancelación e historial. Deben verificarse reintentos y tratamiento de fallos. El checkout actual limita cada compra a un negocio; el PDF no obliga expresamente a comprar a varios a la vez. | 70% |
| **3.6 Checkout y orquestación de pago** | 5 | Selector de métodos, pedido e intento de pago; llamadas a interfaces de reserva, cobro y acumulación. [Checkout][checkout] | Integraciones reales, métodos habilitados por cada negocio, restricciones de bonos, canje de puntos, desglose de pagos mixtos, rechazo/reintento y compensaciones. El código envía también `points` al adaptador de cobro del Equipo 2; la interfaz del Equipo 7 no ofrece canje. | 45% |
| **3.7 Configuración SPEI directa** | 3 | Seleccionar SPEI crea una referencia y un estado pendiente de validación. [Rama de pago directo][directos] | Capturar datos bancarios por negocio, mostrar instrucciones, adjuntar comprobante, revisión manual autorizada y resolución del pago. No se encontraron esos flujos. | 20% |
| **3.8 Adaptador Openpay por negocio** | 5 | Openpay figura como opción y produce el mismo resultado pendiente usado para SPEI. [Rama de pago directo][directos] | Adaptador de proveedor, perfil por negocio, configuración protegida, creación de operación, callbacks/webhooks y conciliación de estados. No se encontró un cliente Openpay ni sus rutas de callback. | 10% |
| **3.9 Panel del vendedor** | 4 | Contadores globales de negocios/productos/pedidos/ventas en el inicio. La lista de pedidos filtra por **comprador**, no por vendedor. [Contadores][catalogo], [pedidos][pedidos] | Pedidos de los negocios autorizados para el usuario, atención/entrega, ingresos por método, productos más vendidos y cancelaciones. Los contadores globales no cubren ese panel. | 35% |
| **3.10 Postventa comercial** | 4 | Modelo de solicitud de devolución e interfaces simuladas de reembolso/reverso. [Modelo][postventa], [interfaces][interfaces] | Solicitar, revisar y resolver cancelaciones/devoluciones con motivos, evidencia y aprobación; coordinar reembolso, inventario y puntos. No aparecen rutas ni acciones de usuario para ello. | 15% |

**Evidencia transversal de las ausencias:** el archivo de rutas solo expone consultas comerciales, adición al carrito y checkout. Se verificó también el arranque: no registra otro archivo de rutas de dominio. [Rutas][rutas], [arranque][arranque]

## 4. Por qué una demo puede aparentar mayor cobertura

La demo empieza con negocios ya aprobados, tiendas publicadas y productos activos creados por el seeder. Esto permite mostrar catálogo y compra sin pasar por las funciones de registro, revisión, edición de tienda o publicación de productos. [Seeder][seeder]

Algunos encabezados nombran funciones aún sin acciones: la página de negocios menciona registro/autorización, pero solo lista registros; la de pedidos menciona postventa, pero solo muestra una tabla. El inicio también anuncia administración de negocios, pagos y postventa sin enlazar operaciones que la implementen. [Negocios][vista_negocios], [pedidos][vista_pedidos], [aviso del inicio][aviso_panel]

Los nombres de esas pantallas y la lista de diez funciones en la documentación representan alcance previsto. Para considerarlas terminadas hace falta una ruta de ejecución con validaciones, permisos y resultado persistido.

## 5. Qué está simulado y qué depende de otros equipos

`AppServiceProvider` vincula las interfaces de Identidad, Finanzas, Inventario y Recompensas con clases `MockTeam*`. No se encontró un cliente HTTP activo que sustituya esos bindings. [Bindings][providers]

| Dominio vecino | Comportamiento en esta entrega | Integración que falta |
|---|---|---|
| **Equipo 1: identidad/autorización** | Adaptador preparado que devuelve estado activo y rol `admin`; el flujo comercial no muestra validación contextual mediante ese servicio. | Identidad canónica y autoridad real del propietario, gerente y cajero por negocio, usando el contrato del Equipo 1. |
| **Equipo 2: wallet/bonos** | El adaptador devuelve `authorized` y una referencia `MOCK-WALLET` sin llamar al módulo financiero. | Cobro/reembolso con referencias verificables y efectos en el módulo financiero. |
| **Equipo 4: inventario** | Devuelve `reserved` y una referencia fabricada; no modifica stock. | Disponibilidad, reserva, consumo por venta y liberación ante rechazo/cancelación mediante su API. |
| **Equipo 7: puntos** | Calcula una cantidad de ejemplo y devuelve `accepted`, sin movimiento en el saldo global. | Acumulación, canje y reverso con reglas del módulo propietario. |
| **Equipo 6: organizaciones** | El seeder crea un negocio de tipo asociación como ejemplo. | Relación estable con la organización y sus responsables; creación/vinculación y validación mediante los acuerdos de integración. |

Fuente del comportamiento simulado: [adaptadores][mocks]. La propia documentación reconoce estos reemplazos y que los contratos son provisionales. [README][readme], [contratos][contratos]

Cuando el checkout recibe `authorized` del mock, marca el pedido como pagado. **Ese estado comercial no demuestra que haya ocurrido un cobro**, una salida de inventario o una acreditación real de puntos. [Actualización de estados][directos]

### Pendientes que pueden avanzar sin esperar las APIs externas

Registro y aprobación de negocios, edición de tienda, administración de catálogo, atención de pedidos, solicitudes/resoluciones de postventa y configuración/manual de SPEI son trabajo del Equipo 3. Pueden construir sus reglas y probarlas con adaptadores controlados mientras cierran la integración.

La configuración y el flujo manual de SPEI tampoco requieren confirmación bancaria automática: el PDF permite revisión manual, siempre que existan instrucciones, comprobante y decisión autorizada. El adaptador Openpay puede prepararse con casos de prueba y callbacks simulados; la validación real del proveedor seguirá pendiente.

## 6. Criterios de terminado que aún no quedan acreditados

La sección 21 del PDF exige autorización, pruebas críticas, auditoría e integración comprobada, además de pantallas y datos de ejemplo.

- **Pruebas:** se encontró un único método de prueba, que comprueba la redirección de `/tienda` a login cuando no hay sesión. No prueba compra, rechazo de pago, stock insuficiente, reintento, permisos por negocio ni devolución. No se ejecutó en esta revisión. [Prueba publicada][prueba]
- **Autorización contextual:** las rutas comerciales tienen autenticación de sesión; no se encontraron políticas o validaciones del propietario/gerente/cajero para los flujos pendientes. [Rutas][rutas]
- **Auditoría e historial:** existen folios, estados y timestamps, pero no se encontró un registro del historial de decisiones comerciales y sus responsables. Las actualizaciones iniciales del pedido no sustituyen un historial de atención/cancelación. [Checkout][checkout]
- **Idempotencia:** se guarda una clave, pero se deriva de un UUID nuevo en cada ejecución. No se encontró recuperación de una operación previa con la misma clave de solicitud ni pruebas de reintentos. Guardar el campo no acredita evitar duplicados. [Checkout][checkout]
- **Importes:** los modelos declaran casts decimales, pero el controlador convierte precio/subtotal a `float` y hace cálculos con ellos. Debe alinearse con el requisito de precisión fija de la página 14 y con el contrato financiero. [Carrito][carrito], [checkout][checkout]
- **Interfaz del carrito:** el backend envía `cart`, pero `Index.vue` no lo declara en `defineProps` y después consulta `props.cart`. Es una inconsistencia observable en el código; su efecto en ejecución no se reprodujo aquí. Debe corregirse/verificarse antes de dar por cerrada la demo. [Vista][vista_carrito], [respuesta backend][catalogo]
- **Base limpia:** hay seeders e índices, lo cual se reconoce. No se verificó su ejecución desde cero; la ausencia de migraciones SQL no se considera por sí sola un defecto de un módulo MongoDB.
- **Integración y revisión por pares:** no se encontraron pruebas publicadas de los flujos entre módulos; el estado de revisión de PR no se verificó.

## 7. Cómo se obtuvo la estimación aproximada

Para mantener comparabilidad con el informe general se conserva su método:

1. Backend: estimación de cada una de las diez funciones, ponderada por los **42 puntos de esfuerzo** del PDF.
2. Interfaz: cobertura de pantallas y acciones necesarias.
3. Integración: conexión comprobable con los dominios vecinos.

Con las estimaciones de la tabla:

- Backend ponderado: aproximadamente **34%**.
- Interfaz: aproximadamente **40%**.
- Integración: aproximadamente **10%**, por preparación de interfaces/documentación, sin acreditar conexiones reales.
- Combinación usada en el informe: `60% backend + 20% interfaz + 20% integración`, cuyo resultado redondeado es **30%**.

**El PDF aporta los pesos funcionales, pero no prescribe esta fórmula ni estos porcentajes.** La valoración tiene incertidumbre y debe actualizarse con nuevos commits o una demostración verificable de funciones no encontradas. La ausencia de integraciones externas baja una parte de la estimación; los flujos internos incompletos explican otra parte considerable.

## 8. Entregables prioritarios para aumentar el avance

1. **Alta y autorización:** un usuario registra negocio; un administrador revisa, observa, aprueba y suspende; se conserva quién decidió y cuándo.
2. **Tienda y catálogo:** el responsable edita su tienda, crea/actualiza productos y configura métodos aceptados; un usuario ajeno no puede administrarlos.
3. **Pedido y vendedor:** corregir/verificar carrito, permitir modificarlo, presentar los pedidos del negocio y registrar preparación, entrega y cancelación.
4. **SPEI manual:** configurar recepción, mostrar referencia/instrucciones, recibir comprobante y resolverlo con permisos y evidencia.
5. **Postventa:** recibir solicitud, dictaminar y coordinar efectos mediante interfaces; probar aceptación/rechazo y reintentos.
6. **Integración real:** adaptar clientes a las APIs de los equipos propietarios, sin leer sus colecciones directamente; demostrar una venta con cobro y stock verificables.
7. **Openpay y cierre:** completar configuración por negocio/callbacks y pruebas de reglas, autorizaciones, fallos y auditoría.

Una entrega que demuestre estos flujos permitirá elevar la estimación con evidencia. El criterio de éxito de la página 16 exige que un negocio autorizado pueda publicar, vender, cobrar por métodos configurados y administrar sus pedidos; la entrega actual acredita principalmente la estructura de datos y partes del recorrido de compra.

## Fuentes de código

Los enlaces apuntan al commit revisado, para que las evidencias no cambien cuando avance la rama.

[rutas]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/routes/web.php#L8-L18
[arranque]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/bootstrap/app.php#L7-L12
[catalogo]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L19-L91
[carrito]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L93-L131
[checkout]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L134-L228
[pedidos]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L58-L64
[reserva]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L150-L157
[directos]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php#L189-L214
[providers]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Providers/AppServiceProvider.php#L17-L22
[mocks]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Services/Integrations/MockTeamAdapters.php#L5-L65
[interfaces]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Services/Integrations/TeamContracts.php#L5-L25
[negocio]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Models/Business.php#L12-L25
[solicitud]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Models/BusinessApplication.php#L12-L15
[tienda]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Models/Storefront.php#L12-L20
[producto]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Models/Product.php#L12-L26
[postventa]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Models/ReturnRequest.php#L12-L17
[seeder]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/database/seeders/DatabaseSeeder.php#L58-L145
[indices]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/database/seeders/MongoIndexesSeeder.php#L12-L32
[vista_negocios]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/resources/js/Pages/Marketplace/Businesses.vue#L8-L21
[vista_productos]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/resources/js/Pages/Marketplace/Products.vue#L8-L18
[vista_pedidos]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/resources/js/Pages/Marketplace/Orders.vue#L8-L26
[vista_carrito]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/resources/js/Pages/Marketplace/Index.vue#L7-L28
[aviso_panel]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/resources/js/Pages/Marketplace/Index.vue#L160-L163
[prueba]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/tests/Feature/MarketplaceTest.php#L7-L14
[readme]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/README.md#L58-L74
[contratos]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/docs/integration-contracts.md#L1-L53
