# Trazabilidad E4 — compromisos → código → pruebas → commits

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 4 (Inventarios) (autores en Git: Daniel Beltrán, Codex); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Fuentes de requisitos: docs/Campus_Digital_Diseno_Modulos.pdf (apartados 4.1–4.11 del módulo 4) y contratos de integración con E2 (pagos/devoluciones), E3 (reservas del Marketplace) y E7 (eventos de inventario). Resultados: suite Feature completa (perfil integrated-financial) y Unit. Commits: git log real.

| Req. | Compromiso | Fuente | Estado | Pruebas (resultado) | Evidencia | Commits | Primer → último |
|---|---|---|---|---|---|---|---|
| 4.1 | Inventario multi-negocio | Diseño 4.1 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) | Aislamiento por negocio y ajuste con actor real | 1 | 21e26a2 → 21e26a2 |
| 4.2 | Almacenes y ubicaciones | Diseño 4.2 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) | Lecturas firmadas por negocio | 1 | 21e26a2 → 21e26a2 |
| 4.3 | Kardex y movimientos | Diseño 4.3 | Implementado | InventoryIntegrationTest, InventoryPaymentEventsTest (65 OK / 0 fallos) | Kardex filtrable (estados-e4/07) | 2 | 21e26a2 → 0e90f02 |
| 4.4 | Proveedores | Diseño 4.4 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) |  | 2 | 21e26a2 → 25dfdec |
| 4.5 | Órdenes de compra con autorización | Diseño 4.5 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) | Gerente no aprueba; propietario sí | 2 | 21e26a2 → 25dfdec |
| 4.6 | Recepción y devoluciones | Diseño 4.6 | Implementado | InventoryIntegrationTest, InventoryPaymentEventsTest (65 OK / 0 fallos) | Recepción parcial y sobre-recepción rechazada | 2 | 21e26a2 → 0e90f02 |
| 4.7 | Costos y precios de referencia | Diseño 4.7 | Implementado | InventoryAdvanceTest, InventoryIntegrationTest (59 OK / 0 fallos) |  | 2 | 21e26a2 → 25dfdec |
| 4.8 | Reserva de stock | Diseño 4.8 | Implementado | InventoryIntegrationTest, InventoryAdvanceTest (59 OK / 0 fallos) | Reserva única, expiración y liberación | 3 | 21e26a2 → 5cf5c6e |
| 4.9 | Alertas de reabastecimiento | Diseño 4.9 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) | Prueba nueva de alerta LOW_STOCK y descarte | 1 | 21e26a2 → 21e26a2 |
| 4.10 | Conteos y ajustes | Diseño 4.10 | Implementado | InventoryAdvanceTest, InventoryIntegrationTest (59 OK / 0 fallos) | Ajustes reales en kardex (estados-e4/06) | 2 | 21e26a2 → faa6fd6 |
| 4.11 | Operación de souvenirs y preventas | Diseño 4.11 | Implementado | InventoryIntegrationTest (55 OK / 0 fallos) |  | 3 | 21e26a2 → f83cf22 |
| E4-X | Integración firmada con E2, E3 y E7 y auditoría | Contratos | Implementado | InventoryE7ContractTest, InventoryOutboxTest, InventoryPaymentEventsTest, InventoryAuditTest (24 OK / 0 fallos) | stage-inventory-e7 6/6 | 5 | 21e26a2 → 5cf5c6e |

Código por compromiso: columna `codigo` del CSV.
