# Documento Integrador — Módulo 1: Identidad, cuentas y autorizaciones (E1)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 0.9 | 2026-09-07 | Equipo 1 | SRS IEEE 830 del Módulo 1 (RF-01..RF-21; 1.8/1.9 con datos simulados) |
| 1.0 | 2026-11-12 | Equipo 1 (autores en Git: Julian-Darkstar, Iridium707, PausEsc, Hashun); integración y verificación asistidas por Claude Code | Estado vigente sobre MERGE_COMPLETO `ffb4abd` + parche de cierre; requisitos E1-01..E1-03 de la solicitud E6 (11/11); reporte CSV; evidencias de laboratorio aislado |

Estado del documento: **vigente para la evaluación del tercer parcial**. Sustituye a las guías E1 con estados «no publicado» anteriores al 11/11.

## 1. Propósito y alcance
E1 es el propietario de la identidad del campus: cuentas y perfiles estudiantiles, autenticación (Fortify + 2FA), sesiones y dispositivos, credenciales NFC/QR, roles y permisos contextuales, designaciones administrativas oficiales y el servicio interno de resolución de identidad y autorización (directorio) para los módulos E2–E7.
Fuera de alcance: dinero (E2), catálogos y stock (E3/E4), servicios (E5), vida asociativa (E6), recompensas/analítica (E7). E1 no consulta bases de otros módulos; expone contratos.

## 2. Arquitectura
- Aplicación Laravel integrada (Laravel 13, Inertia + Vue 3, PHP 8.5). Datos E1 en **MongoDB** (réplica); E2 en SQL Server. Ver `../DICCIONARIO-DE-DATOS.md` (sección E1 Identidad / núcleo) y `../EQUIVALENCIA-POSTGRESQL.md`.
- Contratos: OAuth 2.0 client credentials (`POST /api/oauth/token`, scopes por consumidor); API v1 (`/api/v1/students/{id}/status|history|consents|preferences`, directorio `/api/v1/identity/directory/*`, validación NFC/QR); eventos de dominio en outbox con transporte `legacy` (Bearer) u opcional `platform` (sobre HMAC hacia E7).
- Guardas: sesión registrada vigente (`EnsureSessionIsActive`, cierre forzado con evento de seguridad), 2FA obligatorio en rutas sensibles, TOTP reciente (300 s) para operaciones financieras delegadas, cuotas y auditoría obligatoria en el directorio.

## 3. Requisitos vigentes (SRS + ampliaciones)
Tabla completa requisito → código → pruebas → commits: **`TRAZABILIDAD-E1.md` / `.csv`** (25 requisitos, todos implementados con pruebas en verde en el corte).
Cambios respecto al SRS de septiembre:
- **RF-17 condición estudiantil** y **RF-18 consentimientos y preferencias**: ya no simulados; API reales con historial (pruebas IdentityApiTest, CondicionAcademicaComunidad, AccountDeletionTest).
- **E1-01** designación oficial restringida (sin autodesignación, vigencia, motivo, auditoría; apagada por defecto `IDENTITY_DESIGNATIONS_ENABLED=false`).
- **E1-02** separación `budget.approve/adjust/suspend/resume/commit` (definiciones sin concesiones automáticas: 13 mapeos sin cambio).
- **E1-03** directorio académico: matrícula exacta, IDs conocidos, cuota, aislamiento, revocación de cliente. **Limitación**: no hay fuente canónica de *grupo académico*; no se crea catálogo ni se autoriza por texto libre.
- **Reporte** `GET /students/report.csv` (filtros del listado, máx. 5000 filas, auditoría, neutralización de fórmulas).

## 4. Integraciones verificadas (laboratorio aislado, HTTP real)
| Consumidor | Contrato E1 | Evidencia |
|---|---|---|
| E2 Finanzas | autorización financiera, designaciones de devolución, TOTP | stage-event-paid/refund, fixtures 47/47 |
| E6 Comunidad | `students/{id}/status` (validación de destinatario de avisos), directorio | stage-notices-6 (5/5 tras recuperación) |
| E7 Plataforma | sobre `identity.*` firmado (`PLATFORM_IDENTITY_SECRET`) | stage-platform (Codex) + configuración identity |
| E3/E4 | aprovisionamiento de dueños de negocio, asignaciones | BusinessIdentityApiTest 54/54 |

## 5. Calidad y pruebas (corte)
Unit 515 OK (+10 de aislamiento verificadas con PHPUnit) · Feature completa con perfil `integrated-financial`: 2213/2223 (10 de entorno/diseño, ninguno de E1 salvo StudentPhotoTest por falta de GD) · navegador: login 2FA → panel → /students (filtros, CSV, 390 px). Detalle: `campus-cierre-resultados-20261112/04-PRUEBAS-Y-REGRESIONES.md`.

## 6. Seguridad y configuración
Valores productivos seguros: autorizadores financieros `Pending*`, designaciones apagadas, transporte de eventos `legacy`, secretos sólo por entorno (`.env.example` sin valores). Ningún secreto ni `.env` real versionado.

## 7. Limitaciones y riesgos conocidos
- Grupo académico sin fuente canónica (E1-03).
- Política 302 (cierre forzado de E1) frente a 403 (módulos) para sesión revocada: ambas deniegan; acordar presentación con E2.
- Fotos de estudiante sin prueba en el laboratorio por falta de GD en la imagen.
- Publicación del corte pendiente de autorización expresa.

## 8. Referencias
SRS IEEE 830 Módulo 1 (docx, 2026-09-07) · `docs/integration/E1-CIERRE-E6.md` · `docs/integration/E1-CIERRE-FUNCIONAL-EVALUACION.md` · `docs/integration/E1-DIRECTORY-D1-D4-LOCAL.md` · `TRAZABILIDAD-E1.md` · `../DICCIONARIO-DE-DATOS.md` · `../CONTROL-DOCUMENTAL.md`.
