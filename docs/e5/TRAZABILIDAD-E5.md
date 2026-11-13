# Trazabilidad E5 — compromisos → código → pruebas → commits

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 5 (Servicios estudiantiles) (autores en Git: Codex, Daniel Beltrán, Viko43, Daniel Beltran); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Fuentes de requisitos: docs/Campus_Digital_Diseno_Modulos.pdf (apartados 5.1–5.11 del módulo 5), docs/integration/API_E2_COBROS_SERVICIOS.md, COMUNIDAD_E5_E7_CONTRATOS_2026-11-11.md, PILOTO_GARANTIAS_RENTA_E5_E2.md y REVISION_PR8_E5_2026-11-11.md. Resultados: suite Feature completa (perfil integrated-financial) y Unit. Commits: git log real.

| Req. | Compromiso | Fuente | Estado | Pruebas (resultado) | Evidencia | Commits | Primer → último |
|---|---|---|---|---|---|---|---|
| 5.1 | Biblioteca y catálogo, préstamos y devoluciones | Diseño 5.1 | Implementado | LibraryLoansTest (7 OK / 0 fallos) |  | 4 | 21e26a2 → 8c18796 |
| 5.2 | Multas y pagos de biblioteca | Diseño 5.2 | Implementado | LibraryFinesTest, LibraryFineFinanceTest (11 OK / 0 fallos) | Cobro por E2 | 6 | 21e26a2 → 4ffb39c |
| 5.3 | Lockers: catálogo y periodos | Diseño 5.3 | Implementado | LockersTest (5 OK / 0 fallos) |  | 5 | 21e26a2 → 0963365 |
| 5.4 | Solicitud y asignación de locker | Diseño 5.4 | Implementado | LockersTest, BenefitsApiTest (19 OK / 0 fallos) | Asignación por beneficio | 5 | 21e26a2 → 0963365 |
| 5.5 | Reservación de instalaciones | Diseño 5.5 | Implementado | FacilityReservationTest (14 OK / 0 fallos) |  | 2 | 21e26a2 → 8c18796 |
| 5.6 | Zonas de descanso | Diseño 5.6 | Implementado | RestSpaceTest (8 OK / 0 fallos) |  | 2 | 21e26a2 → 8c18796 |
| 5.7 | Renta de equipos con garantía | Diseño 5.7 | Implementado | RentalsTest, RentalDepositPilotTest (14 OK / 0 fallos) | Garantía con E2 | 4 | 21e26a2 → 8c18796 |
| 5.8 | Servicios e impresiones | Diseño 5.8 | Implementado | ServiceOrdersTest, ServicesFinancePaymentsTest (15 OK / 0 fallos) |  | 5 | 21e26a2 → 0963365 |
| 5.9 | Tickets de soporte e incidencias | Diseño 5.9 | Implementado | SupportTicketsTest (9 OK / 0 fallos) |  | 4 | 21e26a2 → c199386 |
| 5.10 | Calendarios, cupos y reglas | Diseño 5.10 | Implementado | FacilityReservationTest, RestSpaceTest (22 OK / 0 fallos) | Motor común de disponibilidad | 2 | 21e26a2 → 8c18796 |
| 5.11 | Validación de acceso y uso | Diseño 5.11 | Implementado | ServiceAccessTest (9 OK / 0 fallos) |  | 2 | 21e26a2 → d194239 |
| E5-X | Auditoría y analítica hacia E7 | COMUNIDAD_E5_E7_CONTRATOS | Implementado | ServiceAuditTest, PlatformAuditDeliveryTest, ServicePlatformEmitterTest (19 OK / 0 fallos) | F02 | 4 | 21e26a2 → 4ffb39c |

Código por compromiso: columna `codigo` del CSV.
