# Tareas — comisiones y documentos

- [x] Comisión del Consejo por API: alta, edición con versión, periodo y cierre
  auditados; un miembro normal o de otra organización no puede modificar.
  Verificación: `ComisionesConsejoTest` en `campus_virtual_testing`.
- [x] Integrantes de comisión: membresía vigente, máximo una coordinación,
  retiro histórico y limpieza en bajas; no se conceden capacidades por comisión.
  Verificación: pruebas de bajas/reingreso y denegación de privilegios.
- [x] Vista de comisiones para Consejo, vacíos/errores y formularios accesibles.
  Verificación: compilación y flujo en navegador aislado; commit del incremento.
- [x] Documentos privados: alta idempotente y versiones inmutables; archivos con
  tipo/tamaño válidos, compensación si falla persistencia y auditoría.
  Verificación: `DocumentosOrganizacionTest`, disco falso y fallos controlados.
- [x] Visibilidad y retiro: integrantes solo versión actual compartida;
  presidencia ve historial/retirados; contexto y descargas privadas auditados.
  Verificación: pruebas de permisos, versiones, concurrencia lógica y retiro.
- [x] Vista documental: carga, edición, nueva versión, descarga, historial y retiro.
  Verificación: navegador aislado sin delegaciones/roles adicionales; compilación.
- [ ] Cierre: suite completa serial, documentación, commits locales y árbol limpio.

Las dos primeras tareas forman el incremento funcional de comisiones. Las tareas
documentales forman un segundo incremento. No publicar ni integrar otros equipos.
