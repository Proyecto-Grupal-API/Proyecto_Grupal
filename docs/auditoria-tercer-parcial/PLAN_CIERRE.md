# Cierre hacia cumplimiento completo

Corte: 2026-11-10. Responsable de coordinación: Abraham Daniel Beltrán González. Alcance: E6; cambios de auditoría sobre MERGE_COMPLETO.

| Prioridad | Pendiente | Criterios | Qué acredita el cierre |
|---|---|---|---|
| 1 | Completar contratos estrictos del portal, con campos, tipos, límites, multipart, ejemplos y estados reales por endpoint. | 4.8 / 11.3 | Validar OpenAPI y ejecutar la colección con ejemplos válidos e inválidos por operación. El catálogo genérico actual está marcado parcial. |
| 1 | Cerrar el directorio E1 por API y retirar lecturas heredadas de identidad donde corresponda; conservar la autenticación interna compartida acordada. | 7.6 | Configuración autorizada, grants/allowlist y prueba HTTP real de fallos/revocación sin fallback a User. |
| 1 | Acordar y completar segmentación académica/global: campus, carrera, semestre, grupo y alcance del emisor. | 3.8 / 6.7 funcional | Contrato de E1 con identificadores/catálogo y prueba de audiencias/autorización; no inferir esos ámbitos desde texto libre. |
| 1 | Acreditar recorrido integral de becas/beneficios y cobros con usuarios y activaciones del entorno que se presentará. | 3.1 / aceptación operativa | Presidencia, Tesorería y emisor; fondos/presupuesto definidos; recibo y recuperación por referencia; no emitir dinero o conceder roles para aparentar éxito. |
| 2 | Completar nombres, responsabilidades y fecha de entrega del equipo. | 11.8 / datos generales | Confirmación de los integrantes; mantener firma y nota final para el auditor. |
| 2 | Aportar participación verificable de varios integrantes de E6. | 12.2 | Trabajo real revisado y commits de sus autores, o evidencias de pruebas/documentación/diseño aceptadas por el docente. No fabricar historia Git. |
| 2 | Ejecutar una revisión de secretos de todo el historial y remediar cualquier hallazgo real. | 12.6 | Reporte de herramienta/configuración/alcance; revocar credenciales si aparecieran. La revisión actual solo acredita patrones y archivos concretos. |
| 3 | Validar con el docente equivalencias MongoDB y nomenclatura. | 7.1 / 7.3 / 7.4 | Mostrar la corrección del motor y la convención real; no usar esta autoevaluación como autorización del docente. |

## Mejora aplicada y refactor

Antes: el portal consumía `/api/...` sin versión. Después: Axios consume `/api/v1/comunidad/portal/...`; ambos prefijos registran `routes/comunidad.php` con los mismos controladores y middleware. La selección de organización y el alta institucional conservan sus exenciones de contexto; las notificaciones OAuth no cambian de ruta. Los errores del portal son JSON controlado incluso sin Accept y sin trazas en debug.

Se escribieron siete pruebas antes de implementar: resultado RED registrado; resultado posterior 375 aserciones sin fallos. La regresión amplia produjo 303 Feature sin fallos y la compilación confirmó la sintaxis de todas las vistas.

## Cómo revisar las evidencias

Abrir primero Informe_Auditoria_Modulo6.pdf y después el Word llenado. Importar los JSON JUnit en un lector de resultados o consultar sus logs. El video y las capturas pertenecen al laboratorio de auditoría, no afirman que se hayan habilitado nuevos fondos o usuarios productivos. La colección Postman se entrega para reproducir; las pruebas HTTP aportadas se ejecutaron con Python y navegador.

## Seguimiento funcional — 11 de noviembre

El cierre posterior de cobros, devoluciones, beneficios E5, avisos y métricas está documentado en [CIERRE_FUNCIONAL_COMUNIDAD_2026-11-11.md](../integration/CIERRE_FUNCIONAL_COMUNIDAD_2026-11-11.md). Esa entrega suma pruebas HTTP reales con cuentas nuevas y bases aisladas; conserva separados el resultado de la rúbrica y la aceptación funcional. BECA aún requiere presupuesto aprobado y una vía válida de designaciones fuera de testing. El directorio, la segmentación y los esquemas completos del portal siguen pendientes; no se cambia retrospectivamente la calificación del expediente.
