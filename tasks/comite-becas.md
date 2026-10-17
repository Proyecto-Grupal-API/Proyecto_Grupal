# Comité por convocatoria de becas

Base 86f92e1, alterno. Usuario aprobó revisores con recomendaciones y un
responsable de resolución final.

- [x] Configuración por presidencia con versión; miembros activos distintos, 1–10 revisores.
- [x] Lectura solo de expedientes enviados; asignación local por convocatoria, sin permisos globales.
- [x] Recomendaciones con revisión optimista, auditoría y protección de solicitud propia.
- [x] Dictamen por responsable con recomendaciones independientes completas; evidencia congelada.
- [x] Baja/reingreso no reactiva asignación; suspensión y reemplazos conservan historial.
- [x] Vistas de configuración, revisión y decisión; pruebas y navegador.
- [x] Migración aditiva del alterno, documentación y commits locales.

Las convocatorias sin comité conservan el comportamiento anterior. Presidencia
conserva supervisión pero solo el responsable asignado dicta la resolución.
Reemplazar comité incrementa versión e invalida recomendaciones para decisiones
futuras; no altera resoluciones previas. El solicitante no recibe notas internas.
No cambia E1/OAuth/finanzas; no publica a GitHub ni toca la copia principal.

Cierre local: backend ad62e48 y vistas e07b395. Regresión 115/1049,
328.32s, once archivos/clases cotejados sin fallos ni omisiones. Compilación
9.94s, tres sesiones de navegador y comprobación posterior sin mutaciones.
Migración aplicada solo al alterno; demostración cancelada/rechazada, con
evidencia y ninguna asignación. Publicación a GitHub fuera de este incremento.
