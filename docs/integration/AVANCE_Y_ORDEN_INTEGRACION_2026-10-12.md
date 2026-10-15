# Avance y orden de integración — 12 de octubre de 2026

Se revisaron las ramas actuales de `Proyecto-Grupal-API/Proyecto_Grupal` y se compararon con el corte del 9 de octubre. Fechas y horas de este informe: **America/Mexico_City**.

La recomendación principal es **consolidar 1–5–6 → incorporar 4 → conectar 2 por funciones → completar e integrar 3 → incorporar 7 completo**. En paralelo: preparar SQL Server/contratos del Equipo 2, desarrollar la recepción de eventos del Equipo 7 y cerrar la administración comercial del Equipo 3.

## 1. Avance publicado

| Equipo | Corte 09/10 | Corte 12/10 | Nuevos cambios publicados | Commit y fecha local |
|---|---:|---:|---|---|
| 1 · Identidad | ≈90% | **≈90%** | No; mismo commit | [8611154](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/86111548a7659ffa3dab4fd6ee441bb70160b3d9) · 2026-10-07 17:57 |
| 2 · Finanzas y wallet | ≈75% | **≈80%** | Sí | [39de155](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/39de155c98469c8a1972814a3e9d508f1bd70410) · 2026-10-12 00:46 |
| 3 · Marketplace | ≈30% | **≈30%** | No; mismo commit | [85a63cb](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/85a63cbf81d34605c0bd8d6470b730e9b26fde54) · 2026-10-07 18:13 |
| 4 · Inventarios | ≈70% | **≈80%** | Sí | [1dec1fc](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/1dec1fc7f0ebe027101ad8a645a0617e766228b2) · 2026-10-11 22:51 |
| 5 · Servicios al estudiante | ≈75% | **≈75%** | No; mismo commit | [257baeb](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/257baeb67ff72c4efd6fe04db05f81fe75351835) · 2026-10-08 20:51 |
| 6 · Comunidad | ≈80% | **≈80%** | No; mismo commit | [233293b](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/233293b2d392daa49e27dfe08e55635bbb5775c1) · 2026-09-24 13:35 |
| 7 · Recompensas y auditoría | ≈20% | **≈20%** | No; mismo commit | [71d49b9](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/71d49b959c095f6951a17e9825d0896e3ab2894a) · 2026-09-29 22:54 |

Las estimaciones corresponden al **alcance completo visible en GitHub**, no a horas trabajadas ni a una calificación oficial. Comunidad local conserva una estimación previa de **≈85%**, separada del ≈80% publicado. Ese avance local no está todavía publicado en `Testings`.

### Método y límites

Se conserva el método anterior: funciones del PDF ponderadas por sus 42 puntos de esfuerzo, más cobertura de interfaz e integración. Combinación orientativa: **60% backend + 20% interfaz + 20% integración**, redondeada a cinco puntos. Considerar incertidumbre de al menos ±5–10 puntos. El PDF aporta el alcance y los pesos; no prescribe esta fórmula.

Referencia: [Campus_Digital_Diseno_Modulos.pdf](/home/daniel/campus_digital/docs/Campus_Digital_Diseno_Modulos.pdf), páginas 7–13 y Definition of Done de la página 16.

Se verificaron los commits de todas las ramas. E1, E3, E5, E6 publicado y E7 no cambiaron: se conservan sus estimaciones y se contrastaron los puntos principales que condicionan el orden. Se profundizó en los cambios de E2 y E4. **No se ejecutaron suites funcionales, compilaciones ni pruebas con las bases de los equipos en esta revisión.** Las pruebas publicadas o mencionadas por cada equipo no se presentan como ejecutadas por nosotros.

| Equipo con cambios | Backend anterior → actual | UI anterior → actual | Integración anterior → actual | Motivo principal |
|---|---|---|---|---|
| 2 | 83.8% → 89.4% | 80% → 85% | 40% → 45% | Transferencias y comprobantes nuevos; autoridades y delegación pendientes. |
| 4 | 82.4% → 82.9% | 85% → 85% | 30% → 55% | Cliente OAuth compatible con E1 y mejoras de API/índices; conexión real con sesión y consumidores pendiente. |

En backend, E2 incrementa 2.6 de 50% a 90% y 2.9 de 65% a 90%; E4 incrementa 4.8 de 85% a 90%. Los demás pesos/estimaciones funcionales se conservan. Referencias y cálculo: [JSON del corte](AVANCE_Y_ORDEN_INTEGRACION_2026-10-12.json).

## 2. Equipo 2: avance y condiciones pendientes

Rama seleccionada: `feat/modulo-2-equipo-2`, commit `39de155`. La rama `Equipo-2` sigue siendo el prototipo anterior. Desde `d4a7e6b` hay **12 commits y cambios netos en 122 archivos**.

El avance nuevo comprende transferencias/regalos entre estudiantes con políticas, confirmación, débito/crédito, idempotencia, auditoría e interfaz; API de transferencias delegadas; consulta personal de comprobantes, API de comprobantes, PDF y código de consulta/revisión administrativa. [Servicio][e2_transfers], [transferencias][e2_transfer_routes], [comprobantes][e2_receipts]

El último commit retiró la propuesta de administración de roles propia; la autorización administrativa financiera continúa pendiente del contrato aprobado de Identidad. Los registros y servicios financieros se conservan. [Estado administrativo][e2_admin]

Todavía hay seis puntos que impiden conectarlo como núcleo financiero completo:

1. **Autoridad:** los bindings activos conservan proveedores pendientes para permisos financieros, bonos, caja y transferencias. Deniegan operaciones o declaran dependencia no disponible. No se deben sustituir por permisos inventados para facilitar la demo. [Bindings][e2_bindings]
2. **Delegación:** las APIs de transferencias/comprobantes existen, pero requieren prueba de autorización del usuario. Los proveedores pendientes no resuelven esa prueba. Un token de aplicación por sí solo no identifica ni autoriza al estudiante. [Proveedor de comprobantes][e2_receipt_provider], [configuración][e2_receipt_config]
3. **Identidad por API:** el adaptador de destinatarios consulta `User` y `StudentProfile` directamente. Debe consumir el contrato aprobado de Identidad. [Adaptador][e2_recipient]
4. **Becas y cobros externos:** no se encontró una API de emisión de bonos/becas para Comunidad ni un checkout comercial público completo para E3/E5/E6. La administración web de bonos no sustituye esa integración.
5. **Contrapartida comercial:** el pago de compra revisado debita al comprador y registra el pago; no se encontró acreditación coordinada al negocio en ese flujo. Las nuevas transferencias entre estudiantes no cierran automáticamente esa contrapartida. [Pago][e2_payment]
6. **Evento para inventario:** E4 dispone del receptor de pagos, pero sigue faltando el emisor compatible desde E2 para demostrar confirmación de stock por un pago financiero real. [Estado de E4][e4_status]

**E2 puede entrar ya en una fase de preparación y adaptación en SQL Server aislado.** Cada función se habilita al cerrar su autoridad/API y verificar sus efectos financieros; el ≈80% no significa que estos contratos estén resueltos.

## 3. Equipo 4: avance y condiciones pendientes

Rama `Modulo_4`, commit `1dec1fc`. Desde `8876af1` hay **4 commits y cambios netos en 31 archivos**. El último limpia documentos de auditoría; las mejoras funcionales se encuentran en los commits previos del conjunto.

Avances nuevos:

- Cliente HTTP OAuth con consulta de asignaciones/autorización, compatible en forma y scopes con el contrato business de E1. [Cliente][e4_oauth], [contrato][e1_contract]
- Interpretación de varios roles activos por negocio. [Consumidor][e4_identity]
- Comando de creación de índices, incluido el índice único de idempotencia; preparación del scheduler de vencimientos. [Índices][e4_indexes], [estado][e4_status]
- Compatibilidad de API para E7 y validación de negocio en el receptor de pagos. [Webhook][e4_webhook]
- Quince métodos nuevos de prueba para unión de roles/permisos. Usan usuarios de prueba en modo stub; no acreditan HTTP real contra E1. [Pruebas][e4_tests]

Al incorporarlo a nuestra aplicación hay que resolver:

- **Activación real:** el modo stub sigue habilitado por defecto. Configurar cliente autorizado y comprobar consultas reales. [Configuración][e4_config]
- **Usuario de sesión:** el panel toma al usuario de impersonación de desarrollo o un ID por defecto. Debe obtener al usuario autenticado de la sesión común; añadir credenciales OAuth no cierra este punto por sí solo. [Resolución][e4_actor]
- **Contexto multi-negocio:** el inventario toma un `business_id` por instalación. La primera demo puede acotarse a un negocio; el requisito completo exige contexto por operación y aislamiento. [Servicio][e4_multibusiness]
- **Consumidores y pagos:** E3/E7 aún no consumen sus APIs en una operación conjunta comprobada; la confirmación de venta depende de evidencia válida del pago de E2. [Estado][e4_status]

**E4 es el siguiente candidato para incorporar a la base común.** Su adaptación a Identidad mejoró considerablemente; aún requiere conectar nuestra sesión/contexto y probarlo.

## 4. Situación de los demás equipos

| Equipo | Situación que condiciona la integración |
|---|---|
| **1** | Base de identidad. Su API business permite avanzar con E4. La autoridad/delegación financiera que pide E2 necesita acuerdo aprobado; no equivale a los roles business. |
| **3** | Mismo commit: consulta de tienda/catálogo, carrito y creación de pedidos con adaptadores simulados. Falta administración de negocios, tienda/catálogo, atención de pedidos y postventa. [Rutas][e3_routes], [bindings][e3_mocks] |
| **5** | Mismo commit. Su rama original conserva adaptadores locales de identidad/credenciales/pago. Parte de la conexión 1–5–6 ya fue resuelta en nuestro checkout alterno. [Bindings publicados][e5_adapters] |
| **6** | La integración local es más reciente que la publicada. Conserva pendientes financieros, tiendas oficiales y partes heredadas de autorización/directorio. |
| **7** | Mismo commit: servicio de puntos y pruebas, sin API/pantallas de dominio publicadas para consumirlo como módulo transversal. [Rutas publicadas][e7_routes] |

Sigue aplicando el [informe específico de E3 contra sus diez requisitos](INFORME_EQUIPO3_CONTRA_DISENO_2026-10-09.md).

## 5. Orden de integración propuesto

| Etapa | Trabajo | Condición para darla por conectada |
|---|---|---|
| **1. Consolidar 1–5–6** | Partir del checkout alterno. Revisar la corrección final de consentimientos de E1 y preparar entrega reproducible para `Testings`. | Login/permisos comunes; beca de locker/impresiones y avisos por HTTP; instalación desde base de prueba limpia y regresión de flujos conectados. |
| **2. Incorporar 4** | Importar dominio/vistas, conectar usuario de sesión, roles y negocio. Configurar API, índices y scheduler. | Responsable autorizado administra stock; otro negocio no puede operarlo; reserva/liberación y reintentos funcionan por HTTP. Confirmación comercial cuando E2 emita evidencia real. |
| **3. Conectar 2 por funciones** | Preparar SQL Server y migraciones financieras; adaptar su dominio conservando Identidad. Consultas autorizadas primero; bonos/pagos/caja según contratos resueltos. | Autoridad/delegación aprobada; beneficio y cobro/reembolso trazables; contrapartida del negocio y evento para E4; pruebas en SQL Server aislado. |
| **4. Completar e integrar 3** | Cerrar administración comercial; catálogo/pedidos con E4 y checkout con E2. Vincular negocios de organizaciones de E6 por API. | Negocio autorizado publica, vende, atiende y cancela/devuelve; cobro y stock verificables; operaciones ajenas al dominio por API. |
| **5. Integrar 7 completo** | Acumulación/canje/reverso con ventas e inventario; catálogo de recompensas, auditoría transversal y reportes. | Venta genera puntos una vez; canje afecta stock; devolución revierte efectos; datos/trazas de dominios conectados. |

El orden sigue dependencias y facilidad de conexión. E2 y E4 rondan el mismo avance global, pero E4 ya tiene un contrato de identidad consumible; varias operaciones de E2 siguen bloqueadas por autoridad/delegación pendiente.

### Trabajo en paralelo

- **E2:** preparar SQL Server, ejecutar pruebas y acordar con E1 permisos/delegación; con E6 la emisión de becas/bonos y autoridad de caja. Puede avanzar mientras integramos E4.
- **E3:** alta/aprobación de negocios, editor de tienda/productos, panel del vendedor y postventa pueden avanzar sin una wallet final.
- **E7:** preparar API de recepción de eventos con idempotencia y contrato común. Puede probarse con datos de ventas/servicios/Comunidad desde ahora. La API/sink todavía no está publicada; puntos y reportes completos se conectan después de verificar dependencias.

La preparación de eventos de E7 empieza antes que su integración completa, para reducir cambios posteriores en los emisores.

## 6. Base local y `Testings`

Checkout alterno: `/home/daniel/campus_digital_integracion_e1`; rama `integracion/equipo1-servicios-comunidad`; HEAD `b2da6a0` y cambios locales de 1–5–6 sin commit. Se verificaron sus bindings: directorio/credenciales por API de E1 y pagos de Servicios pendientes. Documentación: [INTEGRACION_1_5_6.md](INTEGRACION_1_5_6.md).

La base local procede de una entrega de E1 anterior a su última corrección de consentimientos. Cuatro archivos de ese flujo difieren de `8611154`; deben compararse sus cambios antes de consolidar. No se aplicó una actualización del código en esta evaluación.

**`Testings` y `main` siguen en `f1d03b1`, el commit inicial.** La unión local no está allí. La primera etapa incluye preparar/publicar ese conjunto en la rama acordada cuando se solicite la publicación.

La aplicación objetivo sigue siendo **una sola aplicación Laravel modular**, con SQL Server para Finanzas y MongoDB para los demás dominios. Las integraciones usan las APIs propietarias; no se leen colecciones/tablas ajenas ni se reemplaza el contrato de Identidad.

## 7. Próximo paso

**Cerrar la consolidación de 1–5–6 y abordar E4 en el entorno alterno, preparando E2 en paralelo.** Cada unión debe dejar una demo verificable y una referencia de Git reproducible. Los pagos/beneficios monetarios permanecen pendientes mientras sus proveedores no confirmen una operación autorizada.

## Fuentes de código

Enlaces al commit exacto revisado:

[e2_bindings]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Providers/AppServiceProvider.php#L26-L50
[e2_receipt_provider]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Providers/PersonalReceiptServiceProvider.php#L8-L20
[e2_transfer_routes]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/routes/api.php#L283-L304
[e2_transfers]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/StudentTransferService.php#L48-L113
[e2_receipts]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/routes/financial_receipts_api.php#L5-L12
[e2_receipt_config]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/config/financial_receipts.php#L10-L13
[e2_recipient]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Adapters/Module1TransferRecipientAdapter.php#L21-L47
[e2_payment]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/PurchasePaymentService.php#L103-L145
[e2_admin]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/2.9-consulta-administrativa.md#L1-L5
[e4_oauth]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Services/Team1OAuthClient.php#L46-L81
[e4_identity]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Services/IdentityService.php#L264-L348
[e4_actor]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Services/IdentityService.php#L26-L43
[e4_config]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/config/team1.php#L23-L64
[e4_multibusiness]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Api/Team3ApiService.php#L13-L35
[e4_indexes]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Console/Commands/Team4InitIndexes.php#L8-L53
[e4_webhook]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/app/Api/PaymentWebhookController.php#L20-L48
[e4_status]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/docs/STATUS_INTEGRACIONES.md#L7-L35
[e4_tests]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/1dec1fc7f0ebe027101ad8a645a0617e766228b2/tests/Feature/Equipo4/MultiRoleTest.php#L26-L169
[e1_contract]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/86111548a7659ffa3dab4fd6ee441bb70160b3d9/docs/integration/TEAM-1-INTEGRATION.md#L88-L104
[e3_mocks]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Providers/AppServiceProvider.php#L17-L22
[e3_routes]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/routes/web.php#L8-L18
[e5_adapters]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/257baeb67ff72c4efd6fe04db05f81fe75351835/app/Providers/AppServiceProvider.php#L23-L52
[e7_routes]: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php#L1-L7
