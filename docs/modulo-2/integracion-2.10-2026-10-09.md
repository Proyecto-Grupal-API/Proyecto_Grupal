# Auditoría e integración — 9 de octubre de 2026

## Fuentes y alcance

Se compararon el corte actual del usuario, el ZIP de mejora REQ-E2-2.10-01, el ZIP del Equipo 1, el diseño completo, la continuidad del Módulo 2 y las reglas de bonos/transferencias. Se generó inventario por archivo y se revisaron manualmente los contratos, autorizaciones, servicios financieros, controladores, migraciones y pruebas relevantes para esta integración. Esto no constituye ejecución funcional ni revisión línea por línea de cada pantalla del Módulo 1.

Inventario de app/rutas/bootstrap/config/database/tests/resources, comparado por bytes con el corte actual:

| Fuente | Nuevos | Diferentes | Idénticos |
|---|---:|---:|---:|
| ZIP Módulo 1 | 115 | 85 | 73 |
| ZIP mejora 2.10 | 79 | 14 | 204 |

Los inventarios JSON incluyen cada ruta. Las diferencias de formato también cuentan como diferentes; los números no representan funcionalidades completadas.

## Hallazgos y tratamiento

| Hallazgo | Evidencia | Acción |
|---|---|---|
| 2.10 usa un corte anterior a las devoluciones actuales | No incluye FinancialAdjustmentService/PurchaseRefundService ni sus rutas actuales; BonusMovementType carece de DEVOLUCION | Integración selectiva; preservados servicios, enum y pruebas actuales |
| Los roles de 2.10 provienen de información histórica | Module1RoleAdapter lee User.roles; Team 1 documenta RoleAssignment como autoridad sin fallback | No incorporar adaptador; proveedor financiero pendiente con rechazo explícito |
| No hay contrato público global/institucional para roles financieros | API check/read de Team 1 es business-only; resolución institucional interna | No inventar endpoint ni equivalencia entre roles de negocio y tesorería |
| El actor de API de 2.10 podía elegirse en el body | Controladores de límites/alertas/conciliación aceptan actor_id requerido | Actor del cliente OAuth, pruebas adaptadas para comprobar que el body no lo suplanta |
| El guard de límites podía omitirse | TopUpService/WithdrawalService tenían dependencia nullable y llamada nullsafe | Dependencia obligatoria, sin omisión silenciosa |
| Edición de límite validaba un objeto desactualizado | FinancialLimitService construía merged antes de lockForUpdate | Validación dentro del lock con snapshot actual; conserva motivo en historial |
| Idempotencia global solo tenía lock por fecha | Misma llave con fechas distintas podía competir por índice único | Lock adicional por hash de llave; solicitud EN_PROCESO no se anuncia como terminada |
| Un worker cuyo lock expiró podía guardar resultados | TTL recuperable sin comprobación de propietario al persistir | Comprobación y renovación de ambos locks bajo la transacción de persistencia |
| El lock ocultaba errores de base de datos | FinancialJobLock atrapaba cualquier QueryException como ocupado | Solo conflictos de unicidad reconocidos se tratan como ocupación |
| Conciliación podía reportar falsos errores tras devoluciones | Solicitudes completadas sin ledger; ejecución PURCHASE_REFUND solo de bonos | Reconoce solicitudes administrativas y existencia de retorno de bonos sin abono wallet; enlaza transacciones de devolución en alcance por wallet |
| Limpieza de pruebas podía liberar locks de otros procesos | fcCleanup borraba reconciliation:% sin distinguir dueño | Limpieza limitada al propietario explícito de datos de prueba |
| Identidad actual sigue siendo demostrativa | StudentServicesController devuelve estado active y datos de ejemplo sin lectura persistente | No utilizar ese controlador para autorizar operaciones reales; integrar Team 1 en bloque dedicado |
| OAuth de ambos cortes no tiene la misma emisión de scopes | Actual intersecta scopes; Team 1 rechaza scopes no concedidos con invalid_scope | Preservado en este bloque; integrar servicio y controlador Team 1 juntos, con sus regresiones |
| Consentimientos tienen fronteras distintas | Actual agrupa bajo OAuth; Team 1 exige Sanctum, contraseña inicial y propietario | No copiar rutas sueltas sin controladores, middleware y pruebas correspondientes |
| Emisión real de bonos no tiene binding de autorización | BonusAuthorizationProvider existe; suites lo reemplazan con mocks | Dependencia pendiente; no crear un proveedor que permita toda emisión |

## Contratos del Módulo 1 que debemos respetar

- ID externo estudiantil: User._id. La matrícula y StudentProfile._id no son sustitutos.
- Estado/historial: OAuth `students:read`; restrictions y benefits_eligible pueden ser null y no conceden elegibilidad.
- QR y NFC: scopes separados identity:qr:validate e identity:nfc:validate. Identificación no es autorización financiera.
- Business check/read: identity:authorization:check e identity:assignments:read, contexto business explícito y grants persistentes revalidados.
- No existe identity:roles:check ni API institucional externa. Roles financieros globales, aprobación y emisión institucional de bonos requieren acuerdo del contrato.
- Consentimientos/preferencias: Sanctum y vínculo del propietario; no son consulta pública OAuth interequipos.
- Eventos: outbox/publicador local; transporte externo con Equipo 7 aún pendiente, entrega al menos una vez, deduplicación event_id.

## Qué queda implementado en este paquete

Backend 2.10 sobre el corte de devoluciones actual, API y diagnóstico, más correcciones anteriores. Se mantiene SQL Server en financiero. No se movieron saldos en la integración. El código de identidad y la interfaz web quedaron fuera del paquete. No se instalaron dependencias ni se copiaron secretos.

Pruebas nuevas de compatibilidad: contrato de roles ausente, edición con objeto desactualizado, solicitudes administrativas sin movimientos, lock por llave entre fechas. Las pruebas API originales ahora usan clientes reales en vez de apagar el middleware para cambios que exigen un actor autenticado.

## Orden para continuar

1. Aplicar este bloque y ejecutar todas las pruebas financieras en la base de testing. Corregir cualquier regresión antes de commit.
2. Integrar Módulo 1 por grupos coherentes: autenticación/OAuth y estado académico; identificación QR/NFC; consentimientos; roles/contextos y eventos. Resolver los 85 archivos diferentes y superficies compartidas individualmente. Mantener financiera y sus pruebas; no sustituir providers, User o rutas globales indiscriminadamente. Ejecutar la suite del Equipo 1 además de regresiones financieras.
3. Completar contratos de autorización financiera/bonos con Team 1. Mientras falten, conservar rechazo explícito; no leer sus colecciones como workaround.
4. Terminar backend 2.7: retenciones y liberaciones con disponible/ retenido, vencimiento configurado, idempotencia y atomicidad. Adaptar conciliación para no interpretar una retención como dinero destruido.
5. Cerrar 2.6: regalos diferenciados, validación de participantes y API de transferencias. Revisar contrapartida del negocio en pagos: el servicio actual debita la wallet del comprador; no representa por sí mismo el crédito al vendedor descrito en el diseño.
6. Implementar 2.8 caja/turnos y adaptar CashReconciliationSource; luego 2.9 folios/comprobantes/verificación.
7. Tras completar funcionalidades, integrar la interfaz 2.7 y el panel 2.10 con permisos de usuario reales. Ejecutar pruebas web y build.

## Pendientes de negocio que no se inventaron

Hora/calendario del cierre; rol financiero responsable; fuente de autorización de bonos; tratamiento preventivo de cupo de solicitudes pendientes; referencias externas autorizadas para caja; políticas monetarias concretas. Los importes del PDF de transferencias son propuestas, no límites aprobados.

2.10 sigue parcial por dependencia de roles, caja, retenciones, garantías preventivas y validación de ejecución. Tampoco se declara completado todo el Módulo 1 por haber comparado su ZIP.
