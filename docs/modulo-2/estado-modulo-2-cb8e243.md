# Revisión de pendientes de Módulo 2

Base de código: `cb8e243`, rama `feat/modulo-2-equipo-2`. Revisión del 8 de noviembre de 2026; no supone nuevas entregas de otros equipos.

## Evidencia disponible

La ejecución compartida por el responsable de E2 aprobó 897 pruebas funcionales financieras con 6,451 aserciones, 81 pruebas unitarias con 215 aserciones, cuatro pruebas JavaScript y compilación Vue. El ejecutor independiente aprobó once carreras entre conexiones SQL y el cierre del ciclo BECA. Esos resultados corresponden a esta base, antes del cambio de comprobantes que acompaña esta revisión; no se suman como una única suite ni acreditan operación productiva.

La suite financiera completa necesitó ejecutar Pest con `memory_limit=512M`; con 128 MB se interrumpió en Dompdf. No se modificó el límite de producción ni `.env`.

## Pendientes y responsables

| Prioridad | Pendiente | Estado verificable | Qué falta |
|---|---|---|---|
| 1 | Autoridad para otorgar/revocar emisión BECA global | Consulta E1 implementada y respuesta negativa comprobada; no acredita concesión | Aprobación institucional de la autoridad otorgante; mecanismo E1 y pruebas positivas, vigencia y revocación |
| 1 | Autorizar administración del presupuesto y registrar liquidaciones | `PendingInstitutionalBudgetAuthorization` y `PendingInstitutionalSettlementAuthorization` deniegan por defecto | Facultades y comprobantes externos aprobados; adaptadores con contratos consumibles, sin equiparar automáticamente emisión BECA con aprobación presupuestaria |
| 1 | Conectar pedido real E3 con preparación/consulta del checkout E2 | Checkout nativo y API E2 probados; crear solicitud no descuenta dinero | Integración sobre código actualizado de E3 cuando el responsable autorice ese trabajo; cliente/negocio reales y prueba conjunta |
| 1 | Validar entrega de eventos E4 | Outbox, firma, idempotencia, reintentos y cuarentena implementados; eventos locales no enviados | Receptor y datos válidos, secreto configurado fuera de Git y prueba conjunta del acuse y reintento antes de habilitar entrega |
| 2 | Habilitar consumo BECA nativo en entorno integrado | Consentimiento del comprador implementado; bandera desactivada por defecto | Presupuesto y emisión legítimos, cliente/negocio confiables y activación revisada |
| 2 | Devoluciones y liquidaciones institucionales administrativas | Servicios y controles contables probados; herramientas operativas limitadas | Autoridad y prueba externa aprobadas antes de exponer escrituras administrativas; no habilitar con un usuario o rol inventado |
| 2 | Caja, transferencias y comprobantes administrativos | Existen adaptadores de autorización pendientes, según el flujo | Revisar contratos específicos de capacidades/ámbitos; no restaurar la propuesta de roles descartada |
| 2 | Integración E6 BECA | Coordinación funcional previa, todavía no acreditada como flujo integrado | Contrato consumible de organización/receptor, fuente aprobada, reservas de 15 minutos coordinadas con vencimiento financiero y pruebas conjuntas |
| 3 | Operación y despliegue | Pruebas locales aprobadas; no acredita configuración del servidor final | Revisar programación de tareas, entrega outbox, observación de errores, respaldos/restauración y despliegue sobre el entorno elegido |

No hay evidencia suficiente para declarar ninguno de esos acuerdos externos completado. Una cuenta `admin`, una membresía comercial o un OAuth `client_credentials` no sustituyen la autoridad financiera ni el consentimiento humano requerido.

## Avance propio seleccionado

Se mejora el comprobante de compra institucional para identificar cobertura BECA y devoluciones completadas. Es una consulta sobre operaciones existentes; no requiere conceder autoridad administrativa, crear wallets institucionales, emitir bonos de prueba en desarrollo ni habilitar E1/E4. Conserva el total original y distingue reintegro al bono de liberación al presupuesto por BECA cerrada.

Después de validar este cambio, la prioridad funcional sigue siendo cerrar contratos y pruebas de integración de las filas prioritarias. No se propone añadir indefinidamente escenarios de prueba como sustituto de esos acuerdos.
