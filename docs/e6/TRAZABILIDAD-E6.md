# Trazabilidad E6 — compromisos → código → pruebas → commits

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 6 (autores en Git: Daniel Beltrán, Codex, Julian-Darkstar, Iridium707); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Fuentes de requisitos: docs/Entrega_1..Entrega_9 del Equipo 6, solicitud E6→E1 (11/11) y corrección D-07 de esta integración. Resultados: suite Feature completa (perfil integrated-financial) y Unit. Commits: git log real.

| Req. | Compromiso | Fuente | Estado | Pruebas (resultado) | Evidencia | Commits | Primer → último |
|---|---|---|---|---|---|---|---|
| E6-01 | Organizaciones en MongoDB, membresías y cargos | Entrega_1 | Implementado | ComunidadTest, OrganizationSummaryApiTest (34 OK / 0 fallos) |  | 7 | 0d5e3ac → a260d4d |
| E6-02 | Eventos, cupo, espera y boletos (incluido cobro vía E2) | Entrega_2 | Implementado | EventosTest, ReservasEventosTest, IntentosReservasEventosTest, EventoPaymentAvailabilityTest, MetricasPagosEventosTest (69 OK / 0 fallos) | D02 persistente + boleto en UI | 8 | 0d5e3ac → f845782 |
| E6-03 | Becas y apoyos: convocatorias, solicitudes, dictamen y comité | Entrega_3 | Implementado | BecasTest, ComiteBecasTest, CouncilBecaPanelTest (39 OK / 0 fallos) |  | 10 | 0d5e3ac → 429c345 |
| E6-04 | Campañas, segmentación y bandeja | Entrega_4 | Implementado | ComunicacionTest, AvisosServiciosTest (29 OK / 0 fallos) |  | 4 | 0d5e3ac → 0fb1223 |
| E6-05 | Encuestas y votaciones internas | Entrega_5 | Implementado | ConsultasTest (16 OK / 0 fallos) |  | 2 | 055af14 → 736600c |
| E6-06 | Transparencia, reportes y dashboard | Entrega_6 | Implementado | ReportesTest (15 OK / 0 fallos) | F04; UI /modulo6/transparencia | 2 | 0d5e3ac → 055af14 |
| E6-07 | Staff de acceso y continuidad de administración | Entrega_7 | Implementado | StaffEventosTest, StaffNfcTest, StaffNfcLocalHttpTest, ContinuidadComunidadTest (26 OK / 0 fallos) |  | 3 | 055af14 → 849c139 |
| E6-08 | Alta, suspensión y reactivación de asociaciones y Consejo | Entrega_8 | Implementado | GestionOrganizacionesTest, PeriodosOrganizacionTest, DelegacionesComunidadTest, DocumentosOrganizacionTest, PermisosComunidadTest (40 OK / 0 fallos) |  | 6 | 055af14 → 0641a56 |
| E6-09 | Rediseño de la interfaz | Entrega_9 | Implementado | ComunidadTest (18 OK / 0 fallos) | 38 vistas en navegador (evidencias/interfaz) | 60 | 246f86d → 0963365 |
| E6-10 | Integración con Identidad (condición académica, sesión, 2FA) | Solicitud E6→E1 | Implementado | CondicionAcademicaComunidadTest, IntegracionIdentidadComunidadTest, IntegracionFinalIdentidadComunidadTest (31 OK / 0 fallos) |  | 5 | 8642a02 → a260d4d |
| E6-11 | Conciliación de devoluciones externas de E2 (D-07) | Corrección de integración | Implementado | ReservasEventosTest (14 OK / 0 fallos) | checkpoint 13 | 3 | 42496fa → f845782 |

Código por compromiso: columna `codigo` del CSV.
Nota: ReservasEventosTest se amplió con 3 casos D-07 después de la suite completa y se re-ejecutó en verde (55/55 con las pruebas de pagos; checkpoint 13).
