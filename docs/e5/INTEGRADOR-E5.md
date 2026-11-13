# Documento Integrador — Servicios estudiantiles: biblioteca, lockers, espacios, rentas, impresiones y soporte (E5)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 5 (Servicios estudiantiles) (autores en Git: Codex, Daniel Beltrán, Viko43, Daniel Beltran); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
Biblioteca (catálogo, ejemplares, préstamos, multas), lockers (periodos, solicitudes, asignaciones, acceso), reservación de instalaciones y zonas de descanso con motor común de calendarios y cupos, renta de equipos con garantía, órdenes de servicio/impresión, tickets de soporte y validación de acceso. Cobros vía E2 y auditoría/analítica hacia E7.

## 2. Arquitectura y datos
Laravel + MongoDB. Pantallas Inertia en /servicios/*; API de cobros con E2 (PR#8) y emisión firmada de auditoría/analítica a E7. Autoridad por servicio (ServiceAuthority) sobre roles efectivos de E1.
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E5 Servicios). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E5.md` (12 compromisos; 12 implementados).

## 4. Integraciones verificadas
- E2: cobros de multas y servicios (ServicesFinancePaymentsTest, LibraryFineFinanceTest; PR#8)
- E7: auditoría y analítica firmadas (PlatformAuditDeliveryTest, ServicePlatformEmitterTest; stage-platform-sources-2)
- E1: identidad, roles efectivos y estado de cuenta
- E6: avisos a la bandeja de Comunidad (F02)

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 125 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Autoridad por servicio con roles efectivos de E1; validaciones de servidor en cada escritura; auditoría de acciones sensibles; secretos de emisión hacia E7 y credenciales de cobro sólo por entorno.

## 7. Limitaciones conocidas
- Los cobros dependen de la habilitación de E2 por entorno (apagados por defecto).
- Validaciones del backend devueltas en español desde este corte (lang/es).

## 8. Referencias
Contratos en docs/integration; evidencia F02 en evidencias/laboratorio/stage-platform-sources-2.json.
