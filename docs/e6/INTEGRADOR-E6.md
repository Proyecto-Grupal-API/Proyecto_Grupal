# Documento Integrador — Comunidad, asociaciones, Consejo, eventos, becas, comunicación, participación y transparencia (E6)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 6 (autores en Git: Daniel Beltrán, Codex, Julian-Darkstar, Iridium707); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
E6 gestiona organizaciones estudiantiles y el Consejo (altas, estados, periodos, documentos, delegaciones), eventos con cupo, espera, boletos QR/NFC y cobro vía E2, becas y comité, campañas y bandeja, encuestas y votaciones, staff de acceso y transparencia con reportes y dashboard. No custodia dinero (E2) ni identidad (E1).

## 2. Arquitectura y datos
Laravel integrado, modelos Mongo sobre la base Documento (34 modelos), transacciones Mongo (InscripcionesEvento::ejecutar), outbox y entrega firmada a E7, clientes OAuth hacia E1/E2 (ClienteModuloHttp).
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E6 Comunidad). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E6.md` (11 compromisos; 11 implementados; 11 con pruebas automatizadas en verde).

## 4. Integraciones verificadas
- E2: checkout de eventos por HTTP/OAuth, devolución y conciliación (stage-event-paid/refund; D-07 corregido)
- E1: estado del destinatario de avisos y condición académica (F05; CondicionAcademicaComunidadTest)
- E7: auditorías y agregados de Comunidad (F02; CommunityAnalyticsDelivery)
- E5: avisos de servicios (IntegracionServiciosComunidadTest, AvisosServiciosTest)

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 299 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Permisos por cargo vigente y delegaciones con vigencia (PermisosComunidadTest, DelegacionesComunidadTest); 2FA de E1; pagos sólo con evidencia de E2; banderas de cobro apagadas por defecto.

## 7. Limitaciones conocidas
- Aceptación NFC física con lector real pendiente.
- 15 serializadores históricos por precisar (contexto de entrega).
- Grupo académico sin fuente canónica (E1-03).

## 8. Referencias
docs/Entrega_1..9, docs/Contratos_Integracion_Modulo_6.md, docs/Propuesta_Roles_Permisos_Modulo_6.md, TRAZABILIDAD-E6.md
