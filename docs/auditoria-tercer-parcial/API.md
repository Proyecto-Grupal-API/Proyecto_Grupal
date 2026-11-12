# API de Comunidad: inventario y estado de contratos

Corte documental: 11 de noviembre de 2026. Fuente de rutas: `contracts/runtime-routes.json`.

- Operaciones inventariadas: **120** (HEAD implícito excluido).
- Operaciones con esquema revisado: **105**.
- Operaciones parciales o pendientes: **15**.

La cobertura de rutas no implica aceptación integral. `reviewed` acredita revisión estática y validación documental; únicamente las respuestas históricas contrastadas en el reporte tienen evidencia de DTO ejecutado.
Los contratos usan OpenAPI3.1 y distinguen sesión/CSRF del portal y OAuth de servicios. No habilitan fondos, concesiones o transportes al importarlos.

Construcción y verificación: véase [contracts/README.md](contracts/README.md). El [OpenAPI](comunidad.openapi.json) contiene los esquemas, fuentes, reglas y estados por operación.

| Método | Ruta | Estado del contrato |
|---|---|---|
| GET | `/api/v1/comunidad/becas/{solicitud}/bono` | Revisado |
| GET | `/api/v1/comunidad/notificaciones` | Revisado |
| POST | `/api/v1/comunidad/notificaciones` | Revisado |
| POST | `/api/v1/comunidad/notificaciones/{id}/retiro` | Revisado |
| GET | `/api/v1/comunidad/organizaciones/comercio` | Revisado |
| GET | `/api/v1/comunidad/portal/bandeja` | Revisado |
| GET | `/api/v1/comunidad/portal/bandeja/categorias` | Revisado |
| PUT | `/api/v1/comunidad/portal/bandeja/categorias/servicios_estudiante` | Revisado |
| GET | `/api/v1/comunidad/portal/bandeja/preferencias` | Revisado |
| PUT | `/api/v1/comunidad/portal/bandeja/preferencias/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/bandeja/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/bandeja/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/becas` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/becas` | Parcial / pendiente |
| GET | `/api/v1/comunidad/portal/becas/mis-solicitudes` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/solicitudes/{id}` | Parcial / pendiente |
| PUT | `/api/v1/comunidad/portal/becas/solicitudes/{id}` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/aval-tesoreria` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/confirmacion-presidencia` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/solicitudes/{id}/contrato` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/dictamen` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/documentos` | Revisado |
| DELETE | `/api/v1/comunidad/portal/becas/solicitudes/{id}/documentos/{documento}` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/solicitudes/{id}/documentos/{documento}` | Revisado |
| PUT | `/api/v1/comunidad/portal/becas/solicitudes/{id}/recomendacion` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/retirar` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/solicitudes/{id}/revocacion-beneficio` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/tesoreria` | Revisado |
| PUT | `/api/v1/comunidad/portal/becas/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/{id}/comite` | Parcial / pendiente |
| PUT | `/api/v1/comunidad/portal/becas/{id}/comite` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/{id}/estado` | Revisado |
| POST | `/api/v1/comunidad/portal/becas/{id}/solicitud` | Revisado |
| GET | `/api/v1/comunidad/portal/becas/{id}/solicitudes` | Parcial / pendiente |
| GET | `/api/v1/comunidad/portal/comunicacion` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/comunicacion` | Parcial / pendiente |
| PUT | `/api/v1/comunidad/portal/comunicacion/{id}` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/comunicacion/{id}/cancelar` | Revisado |
| POST | `/api/v1/comunidad/portal/comunicacion/{id}/enviar` | Revisado |
| POST | `/api/v1/comunidad/portal/comunicacion/{id}/preview` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/comunicacion/{id}/reintentar` | Revisado |
| GET | `/api/v1/comunidad/portal/consultas/encuestas/eventos` | Revisado |
| GET | `/api/v1/comunidad/portal/consultas/{tipo}` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}` | Revisado |
| GET | `/api/v1/comunidad/portal/consultas/{tipo}/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/consultas/{tipo}/{id}` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}/{id}/cancelar` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}/{id}/cerrar` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}/{id}/preview` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}/{id}/publicar` | Revisado |
| POST | `/api/v1/comunidad/portal/consultas/{tipo}/{id}/responder` | Revisado |
| GET | `/api/v1/comunidad/portal/dashboard` | Revisado |
| GET | `/api/v1/comunidad/portal/estudiante/boletos` | Revisado |
| GET | `/api/v1/comunidad/portal/estudiante/mi-boleto` | Revisado |
| GET | `/api/v1/comunidad/portal/eventos` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/eventos` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/eventos/checkin` | Revisado |
| PUT | `/api/v1/comunidad/portal/eventos/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/eventos/{id}/boleto` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/cancelar` | Revisado |
| DELETE | `/api/v1/comunidad/portal/eventos/{id}/inscripcion` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/inscripcion` | Revisado |
| GET | `/api/v1/comunidad/portal/eventos/{id}/inscritos` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/pago` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/pago/consultar` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/publicar` | Revisado |
| GET | `/api/v1/comunidad/portal/eventos/{id}/staff` | Revisado |
| POST | `/api/v1/comunidad/portal/eventos/{id}/staff` | Revisado |
| DELETE | `/api/v1/comunidad/portal/eventos/{id}/staff/{staff}` | Revisado |
| GET | `/api/v1/comunidad/portal/gestion-organizaciones` | Revisado |
| POST | `/api/v1/comunidad/portal/gestion-organizaciones` | Revisado |
| POST | `/api/v1/comunidad/portal/gestion-organizaciones/titular` | Revisado |
| GET | `/api/v1/comunidad/portal/gestion-organizaciones/{id}` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/gestion-organizaciones/{id}/completar` | Revisado |
| POST | `/api/v1/comunidad/portal/gestion-organizaciones/{id}/estado` | Revisado |
| GET | `/api/v1/comunidad/portal/identidad/mi-condicion` | Revisado |
| GET | `/api/v1/comunidad/portal/notificaciones` | Revisado |
| PUT | `/api/v1/comunidad/portal/notificaciones/leer` | Revisado |
| DELETE | `/api/v1/comunidad/portal/notificaciones/leidas` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones` | Parcial / pendiente |
| GET | `/api/v1/comunidad/portal/organizaciones/comisiones` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/comisiones` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/comisiones/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/organizaciones/comisiones/{id}` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/comisiones/{id}/cerrar` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/comisiones/{id}/integrantes` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/comisiones/{id}/integrantes/{asignacion}/retirar` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/delegaciones` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/delegaciones` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/delegaciones/{id}/revocar` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/documentos` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/documentos` | Revisado |
| PUT | `/api/v1/comunidad/portal/organizaciones/documentos/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/documentos/{id}/descargar` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/documentos/{id}/retirar` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones/{version}/descargar` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/miembros` | Revisado |
| DELETE | `/api/v1/comunidad/portal/organizaciones/miembros/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/organizaciones/perfil` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/periodos` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/periodos` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/periodos/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/organizaciones/periodos/{id}` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/periodos/{id}/cancelar` | Revisado |
| GET | `/api/v1/comunidad/portal/organizaciones/periodos/{id}/historial` | Parcial / pendiente |
| POST | `/api/v1/comunidad/portal/organizaciones/roles` | Revisado |
| DELETE | `/api/v1/comunidad/portal/organizaciones/roles/{id}` | Revisado |
| PUT | `/api/v1/comunidad/portal/organizaciones/roles/{id}` | Revisado |
| POST | `/api/v1/comunidad/portal/organizaciones/seleccionar` | Revisado |
| GET | `/api/v1/comunidad/portal/staff/eventos` | Revisado |
| GET | `/api/v1/comunidad/portal/staff/eventos/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/transparencia` | Revisado |
| POST | `/api/v1/comunidad/portal/transparencia/reportes` | Revisado |
| GET | `/api/v1/comunidad/portal/transparencia/reportes/{id}` | Revisado |
| GET | `/api/v1/comunidad/portal/transparencia/reportes/{id}/csv` | Revisado |
| GET | `/api/v1/comunidad/portal/transparencia/reportes/{id}/imprimir` | Revisado |
| POST | `/api/v1/comunidad/portal/transparencia/reportes/{id}/publicar` | Revisado |
| POST | `/api/v1/comunidad/portal/transparencia/reportes/{id}/retirar` | Revisado |
