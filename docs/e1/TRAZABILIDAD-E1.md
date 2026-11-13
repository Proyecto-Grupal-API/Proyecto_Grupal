# Trazabilidad E1 — requisitos → código → pruebas → commits

| Versión | Fecha | Responsable | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 1 (Identidad); preparado con Claude Code | Generada por `scripts/generar_trazabilidad.py` sobre MERGE_COMPLETO ffb4abd + parche de cierre |

Fuentes: SRS IEEE 830 del Módulo 1 (RF-01..RF-21, sep. 2026), solicitud E6→E1 del 11/11 (E1-01..E1-03), rúbrica del tercer parcial (10.3/10.4). Resultados: suite Feature completa con el perfil `integrated-financial` y suite Unit del laboratorio aislado (evidencias/laboratorio). Commits: `git log` real sobre las rutas de código citadas (incluye historia anterior a la integración).

| Req. | Nombre | Estado | Pruebas (resultado) | Commits | Autores principales | Primer → último |
|---|---|---|---|---|---|---|
| RF-01 | Autenticación web con Fortify | Implementado | AuthenticationTest (8 OK / 0 fallos) | 4 | Julian-Darkstar, Hashun | 246f86d → 9b92866 |
| RF-02 | Autenticación de dos factores | Implementado | TwoFactorAuthenticationTest, RequiredTwoFactorAuthenticationTest (45 OK / 0 fallos) | 3 | Julian-Darkstar, Codex | b11b2a1 → d194239 |
| RF-03 | Gestión de perfiles académicos | Implementado | StudentManagementTest (13 OK / 0 fallos) | 6 | Julian-Darkstar | 06ef201 → f41c780 |
| RF-04 | Matrícula única | Implementado | StudentManagementTest, StudentImportTest (27 OK / 0 fallos) | 43 | Daniel Beltrán, Julian-Darkstar, Codex, Iridium707 | 246f86d → 0963365 |
| RF-05 | Consistencia campus–carrera | Implementado | StudentImportTest, StudentManagementTest (27 OK / 0 fallos) | 7 | Julian-Darkstar | 06ef201 → f41c780 |
| RF-06 | Historial de condición estudiantil | Implementado | StudentManagementTest, IdentityApiTest (23 OK / 0 fallos) | 13 | Julian-Darkstar, Codex, Daniel Beltrán | 06ef201 → 0f8c707 |
| RF-07 | Importación CSV validada | Implementado | StudentImportTest (14 OK / 0 fallos) | 4 | Julian-Darkstar | 06ef201 → f41c780 |
| RF-08 | Ciclo de vida de credenciales NFC | Implementado | NfcIdentityApiTest (17 OK / 0 fallos) | 8 | Julian-Darkstar, Iridium707, PausEsc | 6bb5414 → 5968187 |
| RF-09 | QR dinámico de un solo uso | Implementado | QrIdentityTest (7 OK / 0 fallos) | 5 | Iridium707, Julian-Darkstar | de40cb4 → f41c780 |
| RF-10 | Consulta y revocación de dispositivos/sesiones | Implementado | SecuritySessionFlowTest (8 OK / 0 fallos) | 3 | Iridium707, Julian-Darkstar | 474a05b → f41c780 |
| RF-11 | Token OAuth 2.0 para servicios internos | Implementado | BusinessIdentityApiTest, IdentityDirectoryApiTest, FinancialIdentityApiTest (104 OK / 0 fallos) | 3 | Julian-Darkstar | 06ef201 → f41c780 |
| RF-12 | Persistencia de eventos en outbox | Implementado | CredentialChangedOutboxTest, OutboxPublisherTest, OutboxLocalHttpTest, IdentityPlatformEnvelopeTest (28 OK / 0 fallos (+ lote puro 148/269)) | 4 | Julian-Darkstar | 06ef201 → 5968187 |
| RF-13 | Formato uniforme de API v1 | Implementado | IdentityApiTest, IdentityContractTest (12 OK / 0 fallos) | 17 | Julian-Darkstar, Codex, Daniel Beltrán, Iridium707 | 51b2c91 → 0f8c707 |
| RF-14 | Registro, recuperación y verificación de cuenta | Implementado | RegistrationTest, PasswordResetTest, EmailVerificationTest, PasswordConfirmationTest, PasswordUpdateTest (20 OK / 0 fallos) | 2 | Julian-Darkstar | 246f86d → 9b92866 |
| RF-15 | Roles y permisos contextuales | Implementado | RoleAssignmentTest, RoleAdministrationTest, DomainAuthorizationCatalogTest, DomainAuthorizationDefinitionTest, InstitutionalAuthorizationLifecycleTest, E6ClosureTest (74 OK / 10 fallos (+ lote puro 148/269)) | 9 | Julian-Darkstar, Codex, Iridium707, Hashun | ff3b6f7 → 56b6708 |
| RF-16 | Identidad QR fija | Implementado | QrIdentityTest (7 OK / 0 fallos) | 5 | Iridium707, Julian-Darkstar | de40cb4 → f41c780 |
| RF-17 | Consulta de condición estudiantil | Implementado | IdentityApiTest, CondicionAcademicaComunidad, IntegracionIdentidadComunidad (10 OK / 0 fallos) | 7 | Daniel Beltrán, Julian-Darkstar, Codex | 06ef201 → a260d4d |
| RF-18 | Consentimientos y preferencias | Implementado | IdentityApiTest, AccountDeletionTest (19 OK / 0 fallos) | 4 | Julian-Darkstar, Daniel Beltrán | 06ef201 → 21e26a2 |
| RF-19 | Servicio interno de resolución de identidad y autorización | Implementado | IdentityDirectoryApiTest, FinancialIdentityApiTest, BusinessIdentityApiTest, IdentityDirectoryTest (164 OK / 0 fallos (+ lote puro 148/269)) | 1 | Julian-Darkstar | 5968187 → 5968187 |
| RF-20 | Scopes y errores de autorización de la API | Implementado | IdentityDirectoryApiTest, BusinessIdentityApiTest, IdentityApiTest (89 OK / 0 fallos) | 3 | Julian-Darkstar | 06ef201 → 5968187 |
| RF-21 | Auditoría de acciones sensibles | Implementado | SecuritySessionFlowTest, IdentityDirectoryApiTest, AccountDeletionTest (42 OK / 0 fallos) | 2 | Iridium707 | 474a05b → 7373fa5 |
| E1-01 | Designación oficial restringida de autoridades (solicitud E6, 11/11) | Implementado | E6ClosureTest, InstitutionalAuthorizationLifecycleTest (26 OK / 10 fallos (+ lote puro 148/269)) | 0 |  |  → nuevo en parche de cierre (sin commit) |
| E1-02 | Separación de permisos de presupuesto (approve/adjust/suspend/resume/commit) | Implementado | E6BudgetContractTest, InstitutionalBudgetAuthorizationTest, DomainAuthorizationDefinitionTest (17 OK / 0 fallos (+ lote puro 148/269)) | 0 |  |  → nuevo en parche de cierre (sin commit) |
| E1-03 | Directorio académico y audiencia (matrícula exacta, ámbitos) | Implementado | IdentityDirectoryTest, IdentityDirectoryApiTest, E6DefectRegressionTest (107 OK / 0 fallos (+ lote puro 148/269)) | 1 | Julian-Darkstar | 5968187 → 5968187 |
| E1-R1 | Reporte CSV de estudiantes con filtros (rúbrica 10.3/10.4) | Implementado | StudentReportCsvTest (6 OK / 0 fallos (+ lote puro 148/269)) | 5 | Julian-Darkstar | 06ef201 → f41c780 |

Código por requisito: ver `TRAZABILIDAD-E1.csv` (columna `codigo`).
Notas: RF-17 y RF-18 figuraban como «parcial: datos simulados» en el SRS de septiembre; hoy existen API reales `GET/PATCH /api/v1/students/{id}/status`, `/status/history`, `/consents` y `/preferences`.
Las 8 pruebas de StudentPhotoTest fallan por entorno (sin GD) y no afectan a ningún RF de esta tabla.
Los 10 «fallos» de InstitutionalAuthorizationLifecycleTest (RF-15/E1-01) son del aislamiento de procesos bajo Pest; pasan 10/10 con PHPUnit directo y dentro del lote puro 148/269.
