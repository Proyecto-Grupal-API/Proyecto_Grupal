# Auditoría funcional e integración de Campus Digital — 15 de octubre de 2026

Este informe contrasta los **74 submódulos del PDF** con el código disponible y la integración local **1–3–4–5–6**. No modifica funciones, bases de datos, credenciales ni ramas remotas.

Base: [Campus_Digital_Diseno_Modulos.pdf](/home/daniel/campus_digital_integracion_e1/docs/Campus_Digital_Diseno_Modulos.pdf), 18 páginas, diseño v1.0. El hash del archivo y las referencias exactas se conservan en el JSON adjunto.

## Alcance y criterios

Se revisaron las ramas remotas actuales mediante Git y el código local, incluidos cambios sin commit. Los HEAD siguen siendo los del corte 13/14 de octubre. Para E1/E3/E4/E5/E6 se evalúa la copia local adaptada; para E2/E7, su implementación publicada, todavía sin instalar en el alterno. Por tanto, avance funcional de E2/E7 no significa disponibilidad local.

**Avance funcional:** suma de cada porcentaje estimado × esfuerzo del PDF, dividida entre 42. El documento asigna 42 puntos a cada equipo. Se valoran resultados de negocio, reglas, interfaz e integraciones exigidas, no líneas de código o cantidad de pantallas.

**Incorporación al alterno:** cinco criterios de 0–20 puntos cada uno: (A) dominio/datos preparados; (B) UI y sesión unificadas; (C) APIs vecinas reales; (D) recuperación/estados/auditoría entre dominios; (E) verificación conjunta. Este indicador mide incorporación técnica y no sustituye el cumplimiento funcional del PDF.

Los porcentajes son **estimaciones técnicas con incertidumbre de ±5–10 puntos**, no una calificación oficial ni horas restantes. 0 significa ausencia; 5–20 estructuras/placeholder; 25–50 flujo parcial; 55–75 uso útil con partes sustanciales pendientes; 80–95 avanzado con cierres pendientes. No se declara 100% sin aceptación completa.

No deben compararse directamente con el indicador antiguo 60% backend +20% UI +20% integración. Esta revisión es más estricta y descompone todo el PDF; una reducción no significa que se haya perdido trabajo.

Los acuerdos posteriores al PDF prevalecen: MongoDB para dominios no financieros y SQL Server para E2; HTTP/API entre equipos; E1 como autoridad de identidad y E6 como dueño de cargos organizacionales. La recomendación PostgreSQL del PDF no se trata como incumplimiento. Tampoco se exige un microservicio por equipo.

## Resultado por equipo

| Equipo | Avance funcional ponderado | Incorporación al alterno | Fuente evaluada |
|---|---:|---:|---|
| 1 · Identidad, acceso, NFC/QR y perfil | ≈91% | ≈85% | integrado_local |
| 2 · Wallet, dinero digital, bonos, caja y conciliación | ≈83% | ≈0% | publicado_no_integrado |
| 3 · Marketplace, negocios, ventas y pagos directos | ≈63% | ≈75% | integrado_local |
| 4 · Inventarios, proveedores, compras y abastecimiento | ≈83% | ≈80% | integrado_local |
| 5 · Servicios al estudiante | ≈81% | ≈80% | integrado_local |
| 6 · Asociaciones, Consejo, becas, eventos y comunicación | ≈78% | ≈80% | integrado_local_propio |
| 7 · Recompensas globales, administración, auditoría y analítica | ≈23% | ≈0% | publicado_no_integrado |

Los valores de E2/E7 describen desarrollo inspeccionado en sus ramas; su incorporación local es 0%. Las APIs preparadas para ellos no cambian ese 0%. No se calcula un promedio global que mezcle código remoto no instalado con funciones locales.

| Equipo | A /20 | B /20 | C /20 | D /20 | E /20 | Total /100 |
|---|---:|---:|---:|---:|---:|---:|
| 1 | 20 | 20 | 10 | 15 | 20 | 85 |
| 2 | 0 | 0 | 0 | 0 | 0 | 0 |
| 3 | 20 | 20 | 10 | 10 | 15 | 75 |
| 4 | 20 | 20 | 10 | 15 | 15 | 80 |
| 5 | 20 | 20 | 10 | 15 | 15 | 80 |
| 6 | 20 | 20 | 10 | 15 | 15 | 80 |
| 7 | 0 | 0 | 0 | 0 | 0 | 0 |

C y D permanecen parciales por los contratos financieros/institucionales, delegaciones y auditoría transversal que faltan. Las comprobaciones E2/E7 no se puntúan como integración por existir en sus repositorios.

## Equipo 1 — Identidad, acceso, NFC/QR y perfil

**PDF:** páginas 7, 8. **Avance funcional:** 90.83% → ≈91%. **Incorporación local:** ≈85%.

Base final de E1 adaptada localmente; incluye su corrección de consentimientos.

Rama publicada: `feat/modulo-1-equipo-1`; commit [`8611154`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/86111548a7659ffa3dab4fd6ee441bb70160b3d9).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 1.1 · Gestión de cuentas y perfil | 5 | ≈95% | Altas, importación, perfil académico, fotografía, condición y preferencias. | Directorio consumible por otros módulos, en particular búsqueda por matrícula y proyección de participantes sin leer User. |
| 1.2 · Autenticación con Fortify | 5 | ≈95% | Login de E1, logout, recuperación, confirmación, contraseña inicial, 2FA y sesiones compartidas. | Validación completa de todos los flujos sensibles cuando entren Finanzas y los operadores especializados. |
| 1.3 · Roles y permisos contextuales | 4 | ≈80% | RoleAssignment vigente, roles globales/business, autorización institucional interna y comprobaciones por negocio. | Contrato consumible de autoridad institucional/financiera/servicios; capacidad operativa de buyer; fuente de departamento. Los cargos internos de E6 conservan su propietario acordado. |
| 1.4 · Registro de tarjetas NFC | 5 | ≈95% | Registro, asociación, validación de UID, control de duplicados y API. | Completar las comprobaciones con lectores reales y los flujos de E2/E6 que aún no consumen NFC. |
| 1.5 · Ciclo de vida de credenciales | 4 | ≈95% | Bloqueo, reemplazo enlazado, suspensión e historial. | Probar propagación de cambios y revocaciones con todos los consumidores restantes. |
| 1.6 · Identidad QR | 4 | ≈95% | QR temporal, validación, límites de intentos y API; usado por Servicios. | Consumo pendiente desde caja/eventos; conservar la distinción entre credencial personal y boleto. |
| 1.7 · Dispositivos y sesiones confiables | 4 | ≈95% | Dispositivos, sesiones, cierre remoto y reautenticación; correcciones de integración aplicadas. | Regresión de las nuevas operaciones sensibles de los módulos financieros y transversales. |
| 1.8 · Validación de condición estudiantil | 4 | ≈90% | Estado e historial académicos por API; E5 y resumen de E6 los consumen por HTTP. | No confundir estado con elegibilidad de beca; terminar la política/contrato de restricciones en cada consumidor. |
| 1.9 · Consentimientos y preferencias | 3 | ≈90% | Consentimientos por funcionalidad y corrección final de E1 incorporada. | Contrato OAuth de servicio para campañas/segmentación; conciliar preferencias globales con las preferencias propias de Comunidad. |
| 1.10 · API/servicio de identidad | 4 | ≈75% | APIs de condición, credenciales y autorización business; provisión inicial de propietario. | Directorio, IDs canónicos de campus/programa por API, permisos institucionales/delegaciones y escritura de personal de negocio consumible por Marketplace. |

**Trazabilidad de código y pruebas:**

- **1.1:** [app/Http/Controllers/StudentController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/StudentController.php), [app/Http/Controllers/StudentImportController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/StudentImportController.php), [tests/Feature/StudentManagementTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentManagementTest.php).
- **1.2:** [app/Providers/FortifyServiceProvider.php](/home/daniel/campus_digital_integracion_e1/app/Providers/FortifyServiceProvider.php), [tests/Feature/RequiredTwoFactorAuthenticationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/RequiredTwoFactorAuthenticationTest.php), [tests/Feature/SecuritySessionFlowTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/SecuritySessionFlowTest.php).
- **1.3:** [app/Services/InstitutionalAuthorizationService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InstitutionalAuthorizationService.php), [app/Services/BusinessAuthorizationService.php](/home/daniel/campus_digital_integracion_e1/app/Services/BusinessAuthorizationService.php), [tests/Feature/BusinessRoleAdministrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/BusinessRoleAdministrationTest.php).
- **1.4:** [app/Http/Controllers/NfcCardController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/NfcCardController.php), [tests/Feature/NfcCardRegistrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/NfcCardRegistrationTest.php), [tests/Feature/NfcIdentityApiTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/NfcIdentityApiTest.php).
- **1.5:** [tests/Feature/NfcCardLifecycleTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/NfcCardLifecycleTest.php), [tests/Feature/NfcCardReplacementTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/NfcCardReplacementTest.php).
- **1.6:** [app/Http/Controllers/QrController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/QrController.php), [tests/Feature/QrIdentityTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/QrIdentityTest.php), [tests/Feature/QrValidationAuthorizationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/QrValidationAuthorizationTest.php).
- **1.7:** [app/Http/Controllers/SecurityDeviceController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/SecurityDeviceController.php), [tests/Feature/SecuritySessionFlowTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/SecuritySessionFlowTest.php).
- **1.8:** [app/Services/StudentStatusService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentStatusService.php), [app/Services/IdentidadComunidadApi.php](/home/daniel/campus_digital_integracion_e1/app/Services/IdentidadComunidadApi.php), [tests/Feature/IdentityApiTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/IdentityApiTest.php).
- **1.9:** [app/Enums/ConsentType.php](/home/daniel/campus_digital_integracion_e1/app/Enums/ConsentType.php), [app/Http/Controllers/StudentServicesController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/StudentServicesController.php), [docs/integration/Dependencias_Equipo_1_Final.md](/home/daniel/campus_digital_integracion_e1/docs/integration/Dependencias_Equipo_1_Final.md).
- **1.10:** [routes/api.php](/home/daniel/campus_digital_integracion_e1/routes/api.php), [docs/integration/TEAM-1-INTEGRATION.md](/home/daniel/campus_digital_integracion_e1/docs/integration/TEAM-1-INTEGRATION.md), [docs/integration/Dependencias_Equipo_1_Final.md](/home/daniel/campus_digital_integracion_e1/docs/integration/Dependencias_Equipo_1_Final.md).

## Equipo 2 — Wallet, dinero digital, bonos, caja y conciliación

**PDF:** páginas 8, 9. **Avance funcional:** 82.98% → ≈83%. **Incorporación local:** ≈0%.

Inspección estática actual de su rama. No se instaló ni se ejecutó SQL Server o su suite.

Rama publicada: `feat/modulo-2-equipo-2`; commit [`39de155`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/39de155c98469c8a1972814a3e9d508f1bd70410).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 2.1 · Cuentas wallet | 5 | ≈85% | Dominio SQL Server, tipos de cuenta, estados y saldos controlados. | Identidad/autoridad efectiva para titulares usuario/organización/negocio y alta/consulta real dentro del alterno. |
| 2.2 · Ledger de movimientos | 5 | ≈90% | Débitos/créditos, referencias, claves de idempotencia y servicios transaccionales. | Pruebas reales de consistencia en SQL Server integrado, contrapartida comercial y conciliación de operaciones entre módulos. |
| 2.3 · Recargas de dinero digital | 4 | ≈80% | Servicios, folio, estados, operaciones de caja y confirmación del estudiante. | Conectar permisos de agente, identificación por API y vínculo de la asociación/caja de E6. |
| 2.4 · Retiros / cash-out en asociaciones | 5 | ≈75% | Solicitud, saldo/límites, confirmaciones, aprobaciones y recuperación de efectivo. | Autoridad vigente, delegación y flujo E6/E1/E2 probado de extremo a extremo, incluido segundo aprobador. |
| 2.5 · Bonos y saldos restringidos | 4 | ≈75% | Emisión/administración internas, consumo, vigencia, restricciones y separación del dinero. | API final de emisión desde beca, fuente autorizada de fondos, restricciones estructuradas y revocación/consumo parcial con E6. |
| 2.6 · Transferencias y regalos de saldo | 4 | ≈85% | Políticas, confirmación, débito/crédito, auditoría, interfaz y API delegada. | Reemplazar proveedores Pending de autorización/delegación y lecturas directas heredadas de Identidad; probar usuario real. |
| 2.7 · Retenciones, devoluciones y reversos | 4 | ≈85% | Servicios y APIs de retención/captura/liberación, devoluciones y reversos vinculados. | Conectar pedidos, eventos y depósitos; resolver respuesta perdida, cancelación simultánea y devolución integral a negocio/usuario. |
| 2.8 · Caja de asociaciones y turnos | 4 | ≈85% | Apertura/cierre, movimientos, administración, arqueo y autorizaciones separadas. | Mapeo organización↔asociación financiera, roles de agentes/tesorería y permisos efectivos por acción. |
| 2.9 · Tickets y comprobantes financieros | 3 | ≈85% | Consulta personal, PDF, revisión administrativa/histórica y API. | Delegación confiable: el proveedor predeterminado impide el acceso hasta integrar E1; verificación conjunta y consumo desde otros módulos. |
| 2.10 · Límites, alertas y conciliación | 4 | ≈85% | Políticas, historial, alertas, conciliación programada y diferencias. | Autoridad operativa real, despliegue del scheduler y prueba con transacciones de todos los dominios; entrega de auditoría a E7. |

**Trazabilidad de código y pruebas:**

- **2.1:** [app/Domains/Financial/Services/WalletService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/WalletService.php), [app/Domains/Financial/Adapters/PendingFinancialRoleProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Adapters/PendingFinancialRoleProvider.php).
- **2.2:** [app/Domains/Financial/Services/LedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/LedgerService.php), [tests/Feature/Financial/LedgerServiceTest.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/tests/Feature/Financial/LedgerServiceTest.php).
- **2.3:** [app/Domains/Financial/Services/TopUpService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/TopUpService.php), [app/Domains/Financial/Adapters/PendingCashAuthorizationProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Adapters/PendingCashAuthorizationProvider.php).
- **2.4:** [app/Domains/Financial/Services/WithdrawalService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/WithdrawalService.php), [app/Domains/Financial/Services/CashWithdrawalRecoveryService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/CashWithdrawalRecoveryService.php).
- **2.5:** [app/Domains/Financial/Services/BonusService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/BonusService.php), [app/Domains/Financial/Adapters/PendingBonusAuthorizationProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Adapters/PendingBonusAuthorizationProvider.php).
- **2.6:** [app/Domains/Financial/Services/StudentTransferService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/StudentTransferService.php), [app/Domains/Financial/Adapters/PendingTransferDelegationProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Adapters/PendingTransferDelegationProvider.php).
- **2.7:** [app/Domains/Financial/Services/WalletHoldService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/WalletHoldService.php), [app/Domains/Financial/Services/PurchaseRefundService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/PurchaseRefundService.php).
- **2.8:** [app/Domains/Financial/Services/CashShiftService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/CashShiftService.php), [docs/modulo-2/2.8-api.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/2.8-api.md).
- **2.9:** [docs/modulo-2/2.9-api-comprobantes.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/2.9-api-comprobantes.md), [app/Providers/PersonalReceiptServiceProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Providers/PersonalReceiptServiceProvider.php).
- **2.10:** [app/Domains/Financial/Services/ReconciliationService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/ReconciliationService.php), [app/Domains/Financial/Services/FinancialLimitService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Domains/Financial/Services/FinancialLimitService.php), [docs/modulo-2/operacion-conciliacion-programada.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/operacion-conciliacion-programada.md).

## Equipo 3 — Marketplace, negocios, ventas y pagos directos

**PDF:** páginas 9. **Avance funcional:** 62.74% → ≈63%. **Incorporación local:** ≈75%.

Se evalúa la copia adaptada, que supera las conexiones simuladas de la rama original.

Rama publicada: `equipo3_negocios`; commit [`b9534b9`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/b9534b9c7eb4da2783a29f210c4a9c69b36e6ff4).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 3.1 · Registro y clasificación de negocios | 4 | ≈80% | Tipos, solicitud, negocio y vínculo con asociación/Consejo consultado a E6. | Administración del personal mediante E1 y condición académica específica para negocio estudiantil; cerrar tipos/reglas institucionales. |
| 3.2 · Flujo de autorización de negocio | 4 | ≈90% | Revisión, observaciones, aprobación, suspensión/baja; propietario mediante E1 y registro en E4 con reintento. | Completar validaciones de elegibilidad estudiantil y decisiones pendientes en la transición de responsables de organizaciones. |
| 3.3 · Constructor de tienda virtual | 4 | ≈80% | Nombre, marca, descripción, logo/banner por URL, políticas, horarios en JSON, puntos de entrega y publicación. | Gestión de canales/contacto y validación estructurada; facilitar edición de horarios y administración de medios desde la UI. |
| 3.4 · Catálogo de productos y servicios | 4 | ≈80% | Altas/edición, precios, imágenes, atributos y vínculo físico a producto/ubicación reales de E4. | Variantes operativas completas, impuestos/configuración académica y agenda/cupos de servicios; medios administrados. |
| 3.5 · Carrito, pedido y estados | 5 | ≈85% | Carrito de un negocio, centavos, reserva real, idempotencia, cancelación, vencimiento y recuperación. | Ruta normal pagado→preparación→entrega y sus cancelaciones; dirección/entrega y trazabilidad financiera completas. |
| 3.6 · Checkout y orquestación de pago | 5 | ≈40% | Crea pedido pendiente, recalcula precios y reserva E4 por 15 minutos. | Wallet/bonos con E2, puntos con E7, selección mixta real y confirmación/descuento de inventario. Hoy no ejecuta ningún cobro. |
| 3.7 · Configuración SPEI directa | 3 | ≈60% | Instrucciones por negocio y envío de referencia/evidencia; estado pendiente. | CLABE/datos estructurados y validación autorizada que cierre el pedido. El PDF permite validación manual; debe acordarse su autoridad, sin fingir confirmación bancaria. |
| 3.8 · Adaptador Openpay por negocio | 5 | ≈10% | Campo de perfil y método seleccionable con aviso de integración pendiente. | Adaptador real/sandbox por negocio, credenciales protegidas, intención, referencia, webhook verificado, reintentos y devolución. Es desarrollo propio de E3. |
| 3.9 · Panel del vendedor | 4 | ≈70% | Pedidos, pendientes/cancelados, importe de ventas pagadas y productos principales. | Alimentar ventas reales, desgloses por método/periodo y atención completa tras pago confirmado. |
| 3.10 · Postventa comercial | 4 | ≈45% | Solicitud, evidencia, decisión y estado de devolución/reembolso pendiente. | Reembolso real E2/proveedor, devolución de stock E4 y reverso de puntos E7; casos parciales y consistencia ante reintentos. |

**Trazabilidad de código y pruebas:**

- **3.1:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [app/Services/Marketplace/MarketplaceIntegration.php](/home/daniel/campus_digital_integracion_e1/app/Services/Marketplace/MarketplaceIntegration.php).
- **3.2:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [tests/Feature/MarketplaceIntegrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/MarketplaceIntegrationTest.php).
- **3.3:** [resources/js/Pages/Marketplace/Businesses.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/Businesses.vue), [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php).
- **3.4:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [app/Services/Marketplace/MarketplaceIntegration.php](/home/daniel/campus_digital_integracion_e1/app/Services/Marketplace/MarketplaceIntegration.php).
- **3.5:** [app/Services/Marketplace/MarketplaceOrders.php](/home/daniel/campus_digital_integracion_e1/app/Services/Marketplace/MarketplaceOrders.php), [tests/Feature/MarketplaceIntegrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/MarketplaceIntegrationTest.php).
- **3.6:** [app/Services/Marketplace/MarketplaceOrders.php](/home/daniel/campus_digital_integracion_e1/app/Services/Marketplace/MarketplaceOrders.php), [resources/js/Pages/Marketplace/Index.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/Index.vue).
- **3.7:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [resources/js/Pages/Marketplace/Orders.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/Orders.vue).
- **3.8:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [resources/js/Pages/Marketplace/Index.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/Index.vue).
- **3.9:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [resources/js/Pages/Marketplace/SellerDashboard.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/SellerDashboard.vue).
- **3.10:** [app/Http/Controllers/Marketplace/BusinessManagementController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Marketplace/BusinessManagementController.php), [resources/js/Pages/Marketplace/Returns.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Marketplace/Returns.vue).

## Equipo 4 — Inventarios, proveedores, compras y abastecimiento

**PDF:** páginas 10. **Avance funcional:** 82.86% → ≈83%. **Incorporación local:** ≈80%.

La copia local corrige actor, autorización, ámbito, fechas, recepciones y API para E3.

Rama publicada: `Modulo_4`; commit [`1dec1fc`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/1dec1fc7f0ebe027101ad8a645a0617e766228b2).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 4.1 · Inventario multi-negocio | 5 | ≈90% | Datos aislados por negocio, contexto real y autorización E1; se habilitan negocios desde E3. | Completar la comprobación con múltiples negocios reales y el ciclo venta/devolución; evitar cualquier stock paralelo. |
| 4.2 · Almacenes y ubicaciones | 4 | ≈90% | Catálogo de almacenes/ubicaciones y transferencias con contexto de negocio. | Validar todos los escenarios de consignación/ubicaciones virtuales y su integración comercial. |
| 4.3 · Kardex y movimientos | 4 | ≈90% | Entradas, ajustes, transferencias, reservas y referencias/actor real. | Salidas por ventas pagadas, canjes y devoluciones sincronizadas; auditoría central. |
| 4.4 · Proveedores | 4 | ≈90% | Alta, datos administrativos, contactos, catálogo y estado. | Comprobar condiciones/productos suministrados en recorridos completos de abastecimiento. |
| 4.5 · Órdenes de compra | 4 | ≈90% | Solicitud, autorización, partidas, costos y estados; gerente solicita y propietario autoriza. | Definición operativa del rol buyer en E1 y comprobación con sus usuarios reales. |
| 4.6 · Recepción y devolución a proveedor | 4 | ≈85% | Recepción parcial/total validada, cantidades pendientes y devolución con movimientos. | Completar evidencias/diferencias y recuperación ante interrupciones en escrituras relacionadas; lotes son opcionales. |
| 4.7 · Costos y precios de referencia | 4 | ≈75% | Último costo, promedio de los últimos cinco costos, margen sugerido e historial. | Normalizar importes actualmente float, acordar criterio de promedio y validar costos durante recepción/devolución. |
| 4.8 · Reserva de stock para pedidos/canjes | 4 | ≈80% | E3 reserva/libera por HTTP firmado; vencimientos y consulta recuperable de estado. | Confirmación definitiva tras pago E2, devolución y canje real E7. Registrar endpoints no demuestra esos flujos. |
| 4.9 · Alertas de reabastecimiento | 3 | ≈65% | Mínimos/reorden, alertas activas/resueltas y prioridades. | Productos sin movimiento y notificación al responsable; completar recorrido máximo/reorden con compra. |
| 4.10 · Conteos y ajustes de inventario | 3 | ≈85% | Conteos, diferencias, aplicación de ajustes, permisos y bitácora. | Comprobar ciclo de autorización y escenarios concurrentes; consolidar auditoría transversal. |
| 4.11 · Operación especializada de souvenirs | 3 | ≈60% | Catálogo de souvenirs usando los mismos servicios y stock; demo vinculada a E3. | Operación completa de colecciones, tallas/colores, temporadas, paquetes y preventas; no basta una categoría souvenirs. |

**Trazabilidad de código y pruebas:**

- **4.1:** [app/Services/InventoryModule/BusinessContext.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/BusinessContext.php), [app/Services/InventoryModule/BusinessService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/BusinessService.php), [tests/Feature/InventoryIntegrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/InventoryIntegrationTest.php).
- **4.2:** [app/Http/Controllers/InventoryModule/WarehouseController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/WarehouseController.php), [app/Http/Controllers/InventoryModule/LocationController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/LocationController.php).
- **4.3:** [app/Services/InventoryModule/InventoryService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/InventoryService.php), [app/Http/Controllers/InventoryModule/KardexController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/KardexController.php).
- **4.4:** [app/Http/Controllers/InventoryModule/SupplierController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/SupplierController.php), [resources/js/Pages/Equipo4/Proveedores.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Equipo4/Proveedores.vue).
- **4.5:** [app/Http/Controllers/InventoryModule/PurchaseOrderController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/PurchaseOrderController.php), [tests/Feature/InventoryIntegrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/InventoryIntegrationTest.php).
- **4.6:** [app/Http/Controllers/InventoryModule/GoodsReceiptController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/GoodsReceiptController.php), [app/Services/InventoryModule/ReturnService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/ReturnService.php).
- **4.7:** [app/Services/InventoryModule/CostService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/CostService.php), [app/Http/Controllers/InventoryModule/CostController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/CostController.php).
- **4.8:** [app/Services/InventoryModule/ReservationService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/ReservationService.php), [routes/inventory-api.php](/home/daniel/campus_digital_integracion_e1/routes/inventory-api.php), [tests/Feature/InventoryIntegrationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/InventoryIntegrationTest.php).
- **4.9:** [app/Services/InventoryModule/AlertGeneratorService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/AlertGeneratorService.php), [app/Services/InventoryModule/ReorderRuleService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/ReorderRuleService.php).
- **4.10:** [app/Http/Controllers/InventoryModule/StockCountController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/StockCountController.php), [app/Services/InventoryModule/InventoryService.php](/home/daniel/campus_digital_integracion_e1/app/Services/InventoryModule/InventoryService.php).
- **4.11:** [resources/js/Pages/Equipo4/Souvenirs.vue](/home/daniel/campus_digital_integracion_e1/resources/js/Pages/Equipo4/Souvenirs.vue), [app/Http/Controllers/InventoryModule/ProductController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/InventoryModule/ProductController.php).

## Equipo 5 — Servicios al estudiante

**PDF:** páginas 10, 11. **Avance funcional:** 80.60% → ≈81%. **Incorporación local:** ≈80%.

Mismo árbol funcional importado desde 257baeb. La copia local añade APIs reales E1/E6 y bloquea pagos ficticios.

Rama publicada: `Equipo-5`; commit [`ea7f0dc`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/ea7f0dc36e4c49119d7abbac60ab31cbca9c6d57).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 5.1 · Biblioteca y catálogo | 5 | ≈90% | Libros/ejemplares, préstamo, devolución, reserva, renovación, historial y sanciones. | Permiso específico de bibliotecario y cierre de adeudos/pagos y bloqueos; hoy opera admin E1. |
| 5.2 · Multas y pagos de biblioteca | 4 | ≈60% | Cálculo/registro de multas y cargos con estados. | Cobro real, conciliación y liberación del bloqueo por confirmación E2; no hay confirmación manual de pago. |
| 5.3 · Lockers | 4 | ≈90% | Catálogo, zonas, tamaño/estado, periodos, costo y disponibilidad. | Rol de responsable, comprobar ciclo de mantenimiento e integración financiera. |
| 5.4 · Solicitud/asignación de locker | 4 | ≈80% | Solicitud, disponibilidad, asignación becada E6, acceso y ciclo propio. | Pago de reserva/renovación, devolución y revocación coordinada de beneficio ya utilizado. |
| 5.5 · Reservación de instalaciones | 4 | ≈85% | Espacios, calendario, capacidad, reglas y reservas. | Cargos/cancelaciones financieras y permisos especializados de responsable de instalaciones. |
| 5.6 · Zonas de descanso | 3 | ≈90% | Recursos, franjas, límites, solapamientos y uso. | Validación de operación física completa y conexión de métricas con E7. |
| 5.7 · Renta de equipos | 4 | ≈65% | Activos, reserva, retiro/entrega, devolución y cálculo de daños. | Retención/liberación/cobro real de garantía con E2; integración E4 si se adopta para activos, sin duplicar propiedad. |
| 5.8 · Servicios e impresiones | 4 | ≈75% | Archivo, opciones, cotización, pedido, estado/entrega y saldo de impresiones becadas. | Pago ordinario, devolución/conciliación y cobertura de beneficios de servicios todavía no soportados. |
| 5.9 · Tickets de soporte e incidencias | 4 | ≈80% | Categoría, prioridad, evidencia, asignación, comentarios, fecha SLA y cierre. | Agente especializado E1, acceso controlado a evidencias y seguimiento/escalamiento automático de vencimiento SLA. |
| 5.10 · Calendarios, cupos y reglas | 3 | ≈90% | Motor compartido, disponibilidad, solapamientos, cancelación y restricciones. | Cerrar políticas no-show/listas y probarlas junto con pagos y devoluciones de cada servicio. |
| 5.11 · Validación de acceso/uso | 3 | ≈85% | Credenciales QR/NFC reales de E1 y eventos de acceso/entrega. | Pruebas con lectores reales y todos los tipos de entrega/retorno; publicación transversal de eventos. |

**Trazabilidad de código y pruebas:**

- **5.1:** [app/Services/StudentServices/Library/LoanService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Library/LoanService.php), [tests/Feature/StudentServices/LibraryLoansTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/LibraryLoansTest.php).
- **5.2:** [app/Services/StudentServices/Library/FineService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Library/FineService.php), [app/Services/StudentServices/Payments/PendingStudentPaymentGateway.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Payments/PendingStudentPaymentGateway.php).
- **5.3:** [app/Http/Controllers/StudentServices/Lockers/LockerController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/StudentServices/Lockers/LockerController.php), [tests/Feature/StudentServices/LockersTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/LockersTest.php).
- **5.4:** [app/Services/StudentServices/Lockers/LockerAssignmentService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Lockers/LockerAssignmentService.php), [app/Services/StudentServices/Benefits/ServiceBenefitService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Benefits/ServiceBenefitService.php).
- **5.5:** [app/Services/StudentServices/Reservations/FacilityReservationService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Reservations/FacilityReservationService.php), [tests/Feature/StudentServices/FacilityReservationTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/FacilityReservationTest.php).
- **5.6:** [app/Services/StudentServices/RestSpaces/RestBookingService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/RestSpaces/RestBookingService.php), [tests/Feature/StudentServices/RestSpaceTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/RestSpaceTest.php).
- **5.7:** [app/Services/StudentServices/Rentals/RentalService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Rentals/RentalService.php), [tests/Feature/StudentServices/RentalsTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/RentalsTest.php).
- **5.8:** [app/Services/StudentServices/Services/ServiceOrderService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Services/ServiceOrderService.php), [tests/Feature/StudentServices/ServiceOrdersTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/ServiceOrdersTest.php).
- **5.9:** [app/Services/StudentServices/Support/SupportTicketService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Support/SupportTicketService.php), [tests/Feature/StudentServices/SupportTicketsTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/SupportTicketsTest.php).
- **5.10:** [app/Services/StudentServices/Calendars/BookingService.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Calendars/BookingService.php), [app/Services/StudentServices/Calendars/CalendarRuleChecker.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/Calendars/CalendarRuleChecker.php).
- **5.11:** [app/Services/StudentServices/ServiceAccess/IdentityCredentialResolver.php](/home/daniel/campus_digital_integracion_e1/app/Services/StudentServices/ServiceAccess/IdentityCredentialResolver.php), [tests/Feature/StudentServices/ServiceAccessTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StudentServices/ServiceAccessTest.php).

## Equipo 6 — Asociaciones, Consejo, becas, eventos y comunicación

**PDF:** páginas 11, 12. **Avance funcional:** 77.62% → ≈78%. **Incorporación local:** ≈80%.

Se evalúa el avance local, superior a lo publicado; no se considera el número de pantallas como cumplimiento completo.

Rama publicada: `integracion/equipo1-comunidad`; commit [`233293b`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/233293b2d392daa49e27dfe08e55635bbb5775c1).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 6.1 · Gestión de asociaciones | 4 | ≈80% | Alta, perfil, estado, integrantes, responsables, suspensión/reactivación e historial. | Documentos institucionales de la asociación, periodos organizacionales completos, directorio E1 y vínculo canónico campus/programa. |
| 6.2 · Gestión del Consejo Estudiantil | 4 | ≈70% | Consejo como organización, integrantes, cargos, convocatorias/eventos y continuidad de presidencia. | Comisiones del Consejo, atribuciones diferenciadas y ámbitos institucionales; compartir base con asociaciones no cubre toda la especialización. |
| 6.3 · Roles internos y vigencias | 3 | ≈60% | Cinco cargos, fechas, retiro, transferencia y comprobación de presidencia vigente. | Delegaciones temporales; permisos funcionales de tesorería/comunicación/comité y agentes de caja; múltiples periodos futuros. No otorgar autoridad financiera por el nombre del cargo. |
| 6.4 · Convocatorias y becas | 5 | ≈90% | Convocatoria, requisitos/documentos privados, solicitud, cierre, dictamen, cupo e idempotencia. | Comité/revisores delegados y comprobaciones académicas pactadas; revocación coordinada cuando ya se entregó el apoyo. |
| 6.5 · Asignación de beneficios | 4 | ≈60% | Aprobación separada de entrega; locker e impresiones reales E5, recibo y reintentos. | Dinero/bonos E2, comidas/transporte y otros servicios; restricciones estructuradas, fondos, consumo y revocación coordinada. |
| 6.6 · Eventos y actividades | 4 | ≈80% | Eventos, cupos, inscripción, espera FIFO, boleto QR, asistencia y staff por evento. | NFC de identidad, cobro E2, plazo financiero de reserva, pago tardío, generaciones de reinscripción y devolución/cancelación pagada. |
| 6.7 · Mensajería segmentada | 5 | ≈60% | Audiencias de miembros, inscritos/espera y solicitudes/aprobados; cola, vista previa, lotes y reintentos. | Todos/campus/carrera/semestre/grupo; consentimiento global por API y permisos institucionales/de Comunicación. |
| 6.8 · Bandeja y notificaciones | 4 | ≈90% | Privacidad, leído/importante/archivo, preferencias, enlaces, campana y avisos E5 por API. | Avisos de E2/E3/E7 y contrato específico de lotes Rewards; conciliar preferencias globales E1. Push/correo son ampliaciones posteriores. |
| 6.9 · Encuestas y consultas | 3 | ≈90% | Población objetivo propia, preguntas, periodo, respuestas y resultados agregados. | Segmentación académica externa cuando corresponda y publicación hacia analítica E7. |
| 6.10 · Votaciones internas | 4 | ≈90% | Padrón congelado, periodo, opciones, voto único y resultados privados hasta cierre. | Validación institucional acordada para procesos especiales y auditoría transversal. Son votaciones académicas, no certificación electoral. |
| 6.11 · Transparencia y resultados | 2 | ≈90% | Cifras agregadas, reportes inmutables/publicados/retirados, CSV e impresión y privacidad. | Incorporar entrega financiera real y datos externos autorizados; enviar hechos a auditoría/analítica global. |

**Trazabilidad de código y pruebas:**

- **6.1:** [app/Models/Organizacion.php](/home/daniel/campus_digital_integracion_e1/app/Models/Organizacion.php), [app/Http/Controllers/OrganizacionController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/OrganizacionController.php), [app/Services/RegistroOrganizaciones.php](/home/daniel/campus_digital_integracion_e1/app/Services/RegistroOrganizaciones.php).
- **6.2:** [app/Models/Organizacion.php](/home/daniel/campus_digital_integracion_e1/app/Models/Organizacion.php), [app/Models/RolOrganizacion.php](/home/daniel/campus_digital_integracion_e1/app/Models/RolOrganizacion.php), [app/Policies/OrganizacionPolicy.php](/home/daniel/campus_digital_integracion_e1/app/Policies/OrganizacionPolicy.php).
- **6.3:** [app/Models/RolOrganizacion.php](/home/daniel/campus_digital_integracion_e1/app/Models/RolOrganizacion.php), [app/Policies/OrganizacionPolicy.php](/home/daniel/campus_digital_integracion_e1/app/Policies/OrganizacionPolicy.php), [app/Services/AutoridadOrganizaciones.php](/home/daniel/campus_digital_integracion_e1/app/Services/AutoridadOrganizaciones.php).
- **6.4:** [app/Http/Controllers/BecaController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/BecaController.php), [tests/Feature/BecasTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/BecasTest.php).
- **6.5:** [app/Services/EntregaBeneficiosServicios.php](/home/daniel/campus_digital_integracion_e1/app/Services/EntregaBeneficiosServicios.php), [app/Services/BeneficiosBeca.php](/home/daniel/campus_digital_integracion_e1/app/Services/BeneficiosBeca.php), [tests/Feature/IntegracionServiciosComunidadTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/IntegracionServiciosComunidadTest.php).
- **6.6:** [app/Services/InscripcionesEvento.php](/home/daniel/campus_digital_integracion_e1/app/Services/InscripcionesEvento.php), [app/Http/Controllers/StaffEventoController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/StaffEventoController.php), [tests/Feature/StaffEventosTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/StaffEventosTest.php).
- **6.7:** [app/Services/AudienciaCampana.php](/home/daniel/campus_digital_integracion_e1/app/Services/AudienciaCampana.php), [docs/Entrega_4_Comunicacion.md](/home/daniel/campus_digital_integracion_e1/docs/Entrega_4_Comunicacion.md), [tests/Feature/ComunicacionTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/ComunicacionTest.php).
- **6.8:** [app/Http/Controllers/BandejaController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/BandejaController.php), [app/Http/Controllers/Api/Community/AvisoController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Api/Community/AvisoController.php), [tests/Feature/AvisosServiciosTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/AvisosServiciosTest.php).
- **6.9:** [app/Services/ConsultasComunidad.php](/home/daniel/campus_digital_integracion_e1/app/Services/ConsultasComunidad.php), [tests/Feature/ConsultasTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/ConsultasTest.php).
- **6.10:** [app/Http/Controllers/ConsultaController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/ConsultaController.php), [tests/Feature/ConsultasTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/ConsultasTest.php).
- **6.11:** [app/Services/MetricasComunidad.php](/home/daniel/campus_digital_integracion_e1/app/Services/MetricasComunidad.php), [app/Http/Controllers/ReporteController.php](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/ReporteController.php), [tests/Feature/ReportesTest.php](/home/daniel/campus_digital_integracion_e1/tests/Feature/ReportesTest.php).

## Equipo 7 — Recompensas globales, administración, auditoría y analítica

**PDF:** páginas 12, 13. **Avance funcional:** 23.21% → ≈23%. **Incorporación local:** ≈0%.

Inspección estática de servicio/modelos/migraciones/pruebas; no se ejecutó su suite ni se instaló en el alterno.

Rama publicada: `Equipo-7`; commit [`71d49b9`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/71d49b959c095f6951a17e9825d0896e3ab2894a).

| Submódulo exigido | Esfuerzo | Avance | Evidencia funcional observada | Falta para cerrar el alcance |
|---|---:|---:|---|---|
| 7.1 · Ledger global de puntos | 5 | ≈65% | Servicio earn/redeem/reverse, saldo, referencias e idempotencia; pruebas de servicio. | Ajustes/expiraciones completos, API/UI, permisos y concurrencia; probar consistencia en réplica y con ventas reales. |
| 7.2 · Adhesión de negocios al programa | 4 | ≈10% | Modelo/estructura de membresías. | Alta/baja operativa, aceptación, condiciones, permisos y consulta desde negocio. |
| 7.3 · Reglas de acumulación | 4 | ≈50% | Reglas por negocio, importe/fijos, multiplicador, campaña y topes. | Administración/API, reglas por producto/categoría/acción y evento de venta confirmada. |
| 7.4 · Catálogo de recompensas | 4 | ≈10% | Entidades y migraciones de recompensas. | CRUD, imágenes, puntos, vigencia/límites, stock/cupo y catálogo consultable. |
| 7.5 · Canje y entrega | 5 | ≈20% | Débito de puntos por referencia y base de canje. | Orquestación de reserva E4, validación/QR de canje, entrega, reverso y recuperación. |
| 7.6 · Gobierno de puntos | 4 | ≈35% | Topes, reverso con faltante y FraudFlag. | Expiración operativa, políticas administrables, revisión de alertas y conciliación integral. |
| 7.7 · Administración general | 4 | ≈5% | Estructuras iniciales de configuración/catálogos. | Flujos administrativos, permisos, validaciones, UI y relación con catálogos canónicos. |
| 7.8 · Auditoría transversal | 4 | ≈20% | Auditoría/correlación generadas por su servicio de puntos. | API receptora de todos los equipos, actor real y antes/después, deduplicación, consulta autorizada y retención. |
| 7.9 · Dashboards y analítica | 4 | ≈0% | Sin vistas/controladores de KPIs operativos observados. | Ingesta/lecturas de dominios, métricas y dashboards globales. |
| 7.10 · Integración y observabilidad | 2 | ≈10% | Estructuras base y referencias internas. | Receptor de eventos, webhooks, reintentos, errores, health checks y correlación entre dominios. |
| 7.11 · Gestión de configuraciones sensibles | 2 | ≈5% | Estructuras iniciales. | Edición/aprobación, versiones, vigencias y trazabilidad ejecutables. |

**Trazabilidad de código y pruebas:**

- **7.1:** [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/tests/Feature/Services/PointsLedgerServiceTest.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/tests/Feature/Services/PointsLedgerServiceTest.php).
- **7.2:** [src/app/Models/RewardProgramMembership.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/RewardProgramMembership.php), [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php).
- **7.3:** [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/app/Models/EarningRule.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/EarningRule.php).
- **7.4:** [src/app/Models/Reward.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/Reward.php), [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php).
- **7.5:** [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/app/Models/Redemption.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/Redemption.php).
- **7.6:** [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/app/Models/FraudFlag.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/FraudFlag.php).
- **7.7:** [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php).
- **7.8:** [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/app/Models/AuditLog.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Models/AuditLog.php).
- **7.9:** [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php).
- **7.10:** [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php), [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php).
- **7.11:** [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php).

## Estado de las conexiones concretas

| Conexión | Estado | Qué funciona | Qué falta | Responsables |
|---|---|---|---|---|
| E1 ↔ E6 | Parcial | Sesión, seguridad, baja coordinada y condición académica HTTP. | Directorio/matrícula, IDs/segmentación, consentimiento de servicio y permiso institucional. | E1 publica contratos; E6 sustituye las lecturas heredadas. |
| E1 ↔ E3/E4 | Parcial avanzada | Asignaciones/checks por API; propietario inicial E3; actor real y contexto E4. | API de gestión del personal; definición operativa buyer y escenarios restantes. | E1/E3/E4. |
| E1 ↔ E5 | Parcial avanzada | Estado académico y validación QR/NFC reales. | Roles de bibliotecario, instalaciones, lockers y soporte. Hoy admin E1. | E1/E5. |
| E6 ↔ E5 | Parcial avanzada | Entrega de lockers/impresiones; avisos a bandeja, recibos, reintentos y deduplicación. | Comidas/transporte/otros servicios; revocación después de consumo. | E6/E5. |
| E6 ↔ E3 | Operativa para alta de tienda | Organización/cargo vigentes por API; solicitud, aprobación y enlace de tienda. | Definir continuidad de propiedad al cambiar presidencia; cierre financiero comercial depende E2. | E6/E3/E1 para propiedad, E2 para dinero. |
| E3 ↔ E4 | Parcial avanzada | Catálogo/ubicaciones, registro de negocio, reserva, consulta, vencimiento y liberación reales. | Salida definitiva tras pago, devolución comercial y reconciliación integral. | E3/E4/E2. |
| E6 ↔ E4 | Indirecta a través de E3 | La tienda oficial puede vincular productos al inventario de su negocio. | Demostración completa de abastecimiento/venta de una asociación; no construir otro stock en E6. | E6/E3/E4. |
| E5 ↔ E4 | No conectada; alcance por acordar | E5 conserva sus activos de renta. | Acordar propiedad y contrato si equipo/material se integrará con E4. El PDF no enumera este enlace como obligación directa de E5. | E5/E4. |
| E2 ↔ E1/E3/E4/E5/E6 | No integrada localmente | Contratos, servicios y rutas de la rama financiera inspeccionados. | Autoridad/delegación, SQL Server, pagos/bonos/caja, contrapartida y callbacks con consumidores reales. | E2 más cada dueño del caso de uso. |
| E7 ↔ resto | Preparación sin consumo real de E7 | E6 expone resumen de organizaciones; E4 dispone de rutas para canjes; auditorías propias en cada dominio. | Instalar E7, receptor/API, eventos, recompensas/canje, consultas y dashboards. | E7 y emisores. |

## Pendientes prioritarios de nuestro Equipo 6

1. **API de Identidad y autoridad institucional:** sustituir búsquedas heredadas de User/matrícula y verificaciones de participantes. Consumir IDs canónicos y la decisión institucional cuando exista su contrato. Retirar el permiso local provisional con una transición explícita. No reconstruir IDs a partir de nombres.
2. **Permisos internos y Consejo:** separar funciones de presidencia, comunicación, comité de becas y tesorería; desarrollar delegaciones con vigencia y comisiones. La vigencia de un cargo y la autorización financiera son decisiones distintas.
3. **Segmentación académica:** añadir campus, carrera, semestre, grupo y alcance global autorizado usando el contrato de E1; aplicar preferencias/consentimientos. Hoy no están esos filtros.
4. **Becas financieras:** acordar restricciones estructuradas, origen autorizado de fondos, emisión/consulta/revocación con E2. Conservar los recibos de locker/impresiones existentes y no cambiar la clave de un envío confirmado.
5. **Eventos pagados:** operación recuperable con clave/generación por reinscripción; snapshot del precio en centavos; reserva/cobro/vencimiento, respuesta perdida, pago tardío y cancelación/reembolso. El QR no se habilita por una captura de comprobante.
6. **Servicios no cubiertos:** comidas/transporte y revocación de beneficios consumidos con E5. El código de entrega actual solo selecciona locker e impresiones.
7. **Auditoría y avisos E7:** eventos versionados, correlación, salida recuperable y receptor real; decidir contrato de avisos Rewards. El resumen de organizaciones ya disponible es solo una parte.
8. **Documentos de organizaciones y periodos:** expediente institucional y estructura temporal completa, sin confundirlos con los documentos privados de solicitudes de beca.

## Responsabilidad de los equipos y dependencias

**E2:** tener servicios transaccionales desarrollados no demuestra APIs operativas consumibles. Sus proveedores Pending de roles, caja, bonos, transferencias y delegaciones bloquean acciones por diseño. E1/E2 deben acordar autoridad; E6 aporta cargos y organización, pero no concede mover fondos por ser presidencia/tesorería. También falta comprobar contrapartida del negocio y emisión/consulta de evidencia de pago compatible con E3/E4.

**E3:** Openpay, el constructor de tienda, variantes/agenda, SPEI y panel/postventa tienen trabajo propio. No todo ese faltante depende de Wallet. El PDF permite SPEI con validación manual autorizada y no supone confirmación bancaria automática; hoy la copia local mantiene la confirmación bloqueada hasta cerrar el contrato operativo.

**E4:** no atribuir el coste en float, alertas incompletas o especialización de souvenirs a los módulos que faltan. El cálculo actual de referencia promedia los últimos cinco costos; no se presenta como promedio ponderado por unidades.

**E5:** falta el rol específico de operador de cada servicio, hoy se utiliza admin de E1. La integración E5↔E4 es una posible mejora de arquitectura a acordar, no una obligación directa que el PDF liste para E5; tampoco se debe fusionar stock/activos sin definir quién es su dueño.

**E6:** también tenemos faltantes propios, especialmente segmentación, comisiones, delegaciones y documentos organizacionales. Se puede avanzar en su estructura/reglas sin inventar APIs ni campos académicos.

**E7:** los demás equipos hacen falta para demostrar resultados globales, pero no impiden desarrollar su UI, administración, adhesiones, catálogo o receptor de eventos. Un servicio que debita puntos no equivale a un canje con inventario y entrega.

## Contraste con la Definition of Done y la demo final

El PDF, secciones 21–23, exige migraciones/semillas desde cero, validación backend, permisos probados, manejo de errores, auditoría, integración vecina, PR revisado, documentación y demo. La integración local no está publicada todavía; `Testings` y `main` siguen en el commit inicial observado (`f1d03b1`). No cumple aún el cierre de entrega compartida de la DoD.

La demo final del PDF sigue abierta en recarga/retiro, compra cobrada, puntos, SPEI/Openpay confirmado, bono financiero, canje, devolución coordinada, cierre de caja y dashboard global. Sí hay flujos locales de identidad, organizaciones, préstamo/reservas de servicios, beca de impresiones/locker, campañas/eventos y pedido pendiente con reserva real.

No se penalizan como obligatorias las extensiones de la sección 24: app móvil, push, portal de proveedores, torniquetes, data warehouse, etc. La compensación monetaria entre negocios del modelo avanzado de puntos tampoco se impone al MVP.

## Verificación y límites del corte

- Hoy: revisión estática de código y PDF; HEAD remotos comprobados; contenedores alterno/principal activos; login 200, tienda de invitado redirigida a login y resumen de organizaciones sin token rechazado con 401.
- No se ejecutaron suites ni compilación en este corte. La última validación documentada del 14 de octubre es 33 pruebas E3 +43 E4 +213 E1/E5/E6 =289, y Vite correcto. No son 289 pruebas nuevas ni la suite completa de los siete equipos.
- Las 20 pruebas que contiene la rama E7 y las suites SQL de E2 no se declaran ejecutadas aquí.
- No se inspeccionaron manualmente todos los formularios autenticados ni lectores físicos. No se certifica consistencia distribuida/producción.
- Solo se crean este informe y su JSON; no se habilitan pagos/recompensas ni se envían mensajes/publicaciones.

## Orden recomendado desde este punto

1. Consolidar una referencia reproducible del estado 1–3–4–5–6: revisar cambios, documentar y publicar en la rama acordada cuando el usuario lo solicite. Conservar el entorno principal y respaldo privado.
2. Cerrar con E1 los contratos de directorio, segmentación, autoridad institucional/financiera y personal de negocios; implementar después sus consumidores. No modificar unilateralmente la autoridad de E1.
3. En paralelo, completar funciones propias de E6 (comisiones, delegaciones, documentos) y E3 (constructor, catálogo, SPEI/Openpay), y preparar receptor/API/UI de E7.
4. Incorporar E2 de manera acotada en el alterno, con SQL Server aislado: identidad/consulta primero; luego un flujo monetario por vez. Becas/bonos y eventos E6; checkout E3 y confirmación E4; multas/garantías E5. Cada paso requiere permisos y evidencia reales.
5. Cerrar postventa E3/E2/E4 y revocaciones E6/E2/E5 con reintentos y conciliación.
6. Conectar puntos/canjes E7 y después dashboards/auditoría global; probar la demo final del PDF.

## Informes de integración utilizados

- [Marketplace 1–3–4–5–6](/home/daniel/campus_digital_integracion_e1/docs/integration/INTEGRACION_1_3_4_5_6.md).
- [Inventarios 1–4–5–6](/home/daniel/campus_digital_integracion_e1/docs/integration/INTEGRACION_1_4_5_6.md), respetando que su lista de próximos pasos es histórica.
- [Servicios 1–5–6](/home/daniel/campus_digital_integracion_e1/docs/integration/INTEGRACION_1_5_6.md), respetando sus límites de fase.
- [Dependencias de Identidad](/home/daniel/campus_digital_integracion_e1/docs/integration/Dependencias_Equipo_1_Final.md).
- [Revisión de requerimientos E2→E6](/home/daniel/campus_digital_integracion_e1/docs/integration/REVISION_SOLICITUD_EQUIPO2_A_COMUNIDAD_2026-10-12.md).
- [Organizaciones para E7](/home/daniel/campus_digital_integracion_e1/docs/API_Integracion_Equipo_7.md).

Datos verificables y cálculo: [AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.json](/home/daniel/campus_digital_integracion_e1/docs/integration/AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.json).
