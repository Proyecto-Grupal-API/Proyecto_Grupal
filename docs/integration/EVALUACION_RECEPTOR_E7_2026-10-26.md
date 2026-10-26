# Compatibilidad del receptor E7 con Comunidad

Fecha: 26 de octubre de 2026. Fuente: `Equipo-7`, commit `e48031ee28a53ebfd60162ba84f727cb4edd6e0c`,
backend efectivo en `backend_respaldo/`. Evaluación en copia temporal `/e7`
del contenedor de laboratorio; no se instala el módulo ni se envían auditorías
reales. MongoDB exclusivo del laboratorio, no el de la app integrada.

## Lo comprobado

Se instalaron sus dependencias bloqueadas en vendor independiente y se aplicaron
sus migraciones. Guardas locales de pruebas exigen `campus_virtual_testing` en
`mongo-eval`; no se modificó la implementación del receptor.

La batería publicada `PlatformIngestApiTest`: **3 pruebas aprobadas, 8 aserciones**.
El receptor registra un sobre válido, deduplica por event_id y rechaza un usuario
estudiante. Estas pruebas no cubren todos los requisitos de entrega entre módulos.

Dos comprobaciones adicionales de compatibilidad E6, ejecutadas en la copia
externa a nuestra suite integrada, fallaron de forma reproducible:

| Caso | Resultado observado | Necesidad antes de conectar |
|---|---|---|
| Mismo event_id, distinto payload | HTTP 200 «duplicado» | La misma identidad con contenido distinto debe producir conflicto; conservar y comparar una huella canónica del sobre. |
| Auditoría declarada de Comunidad | Se guarda `source_domain=services` | El origen debe corresponder al emisor autorizado. No atribuir una decisión de beca de E6 a Servicios. |

`CommunityIntegrationReadinessTest`: 2 fallos esperados de compatibilidad,
4 aserciones, sin errores de ejecución. Se conserva el código de reproducción
en `storage/logs/actualizacion-20261026/e7/CommunityIntegrationReadinessTest.php`.
No se registra como prueba aprobada ni se introduce un test rojo en la app.

## Contrato a coordinar con E7

- `POST /api/v1/plataforma/eventos` exige event_id UUID, nombre con dominio y
  sufijo de versión (por ejemplo `community.event.attendance.v1`), source_domain,
  aggregate_id, occurred_at y payload. El evento E4 existente no utiliza todavía
  este mismo sobre; no basta configurar su URL como destino del emisor actual.
- Las rutas están bajo Sanctum y permisos de sus usuarios locales. No está
  demostrada compatibilidad con nuestros clientes OAuth E1. Acordar autenticación
  de servicio y aislamiento por origen sin copiar usuarios/roles de su demo.
- Auditoría debe obtener el dominio emisor de contexto autenticado; actor/cargo
  son evidencia histórica de E6, no permisos globales nuevos de E7.
- Idempotencia: mismo sobre reintentado, un recibo; mismo ID con distinto contenido,
  conflicto; carreras y respuestas perdidas sin duplicar. Definir retención.
- Payload por tema con lista mínima de IDs/estados. La validación de nombres/correos
  actual revisa claves superiores, no certifica privacidad de objetos anidados.
  No enviar expedientes, contraseñas, tokens ni datos personales de auditoría cruda.
- La aceptación de un evento analítico no acredita asignación de puntos ni canje.

El receptor recién publicado está orientado inicialmente a E5. El origen fijo
puede corresponder a ese alcance: es una brecha para incorporar E6, no evidencia
de que toda la entrega E7 sea incorrecta. Requiere acuerdo y pruebas adicionales.

No se modifican sus fuentes remotas ni se envía este documento como mensaje.
No se activan nuestros emisores. Logs y XML: `storage/logs/actualizacion-20261026/e7/`.
