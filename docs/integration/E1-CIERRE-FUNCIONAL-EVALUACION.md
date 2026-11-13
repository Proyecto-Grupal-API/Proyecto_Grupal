# Cierre funcional de evaluación E1–E7

Versión: candidata del 11-11-2026 sobre MERGE_COMPLETO `1b648b7da21c29a4e8e6b26d9b5ccfc3358c789d`. Sin publicación ni habilitación institucional. Responsable de la preparación: Equipo 1; cambios de analítica requieren revisión de E7.

## Reporte de estudiantes

`GET /students/report.csv`, nombre `students.report`, sesión web autenticada y la misma Policy `viewAny(StudentProfile)` del listado. Un estudiante sin esa capacidad recibe 403. Filtros: `search`, `academic_status`, `campus_id`; mismos resultados que el listado. Máximo 5000 filas; reducir filtros ante 422. La generación se audita con actor, filtros y cantidad; si no puede persistirse la auditoría se deniega con 503.

CSV UTF-8: nombre, matrícula, campus, programa, semestre, estado. No exporta correo, teléfono, credenciales ni datos de sesión. Las celdas neutralizan fórmulas de hojas de cálculo. Respuesta privada, sin caché. El acceso sigue requiriendo autorización; conocer la URL no la concede.

El encabezado del listado apila acciones en pantallas reducidas y conserva el diseño existente. La exportación mantiene los filtros aplicados.

## Transporte optativo E1 → E7

El valor predeterminado continúa siendo `EVENTS_TRANSPORT=legacy`: conserva el cuerpo y Bearer publicados. No se cambia automáticamente ningún receptor.

La opción `EVENTS_TRANSPORT=platform_hmac` adapta exclusivamente estos eventos al contrato E7 `/api/v1/plataforma/eventos`:

| E1 | E7 |
|---|---|
| student.profile.changed.v1 | identity.student.profile.changed.v1 |
| student.consent.changed.v1 | identity.student.consent.changed.v1 |
| identity.credential.changed.v1 | identity.credential.changed.v1 |

Requiere `EVENTS_SINK_URL` HTTPS y `EVENTS_PLATFORM_SECRET` compartido explícitamente con el receptor E7 habilitado para `identity`. HTTP sólo se permite en loopback y entorno local/testing. Se conservan UUID, agregado, instante y outbox; no se inventa una recepción. La firma HMAC cubre instante, método, ruta y hash del cuerpo exacto. Campos del payload sujetos a lista cerrada; los motivos libres no se envían.

El receptor deduplica por UUID. El publicador sólo confirma el outbox tras respuesta satisfactoria; errores y reintentos conservan su ciclo de vida. Eventos no mapeados se rechazan como `contract_mismatch`; no debe activarse esta opción para un catálogo de eventos diferente sin acordar su contrato.

## Analítica de recompensas E7

El ranking de `AnalyticsController::overview()` agrega canjes confirmados o entregados por recompensa, suma `points_spent` y ordena por cantidad con desempate por ID. No utiliza el stock como sustituto de popularidad. Excluye cancelados y revertidos. Conserva las claves anteriores de recompensa y añade `redemptions_count` y `points_consumed` consumidas por la interfaz. El ranking es histórico total, como indica la tabla; el gráfico usa la ventana solicitada.

La escala del gráfico se deriva de ambas series, sin etiquetas fijas en miles. Los porcentajes por fuente retirados en el HEAD publicado no se reintroducen: la interfaz informa ausencia de segmentación.

## Límites y activación

Las designaciones administrativas siguen desactivadas por defecto; no se otorgan roles a usuarios reales ni se activan flags financieros. Las pruebas utilizaron configuración privada y bases efímeras propias. No se añade un catálogo de grupos académicos: no hay una fuente canónica acreditada. No se certifican todas las historias futuras ni toda la UI de cada módulo. La entrega y sus evidencias requieren revisión antes de commit o publicación.
