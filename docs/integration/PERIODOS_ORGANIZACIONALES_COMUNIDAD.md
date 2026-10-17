# Periodos de asociaciones y Consejo

Incremento local del **16 de octubre de 2026** en el entorno alterno integrado
E1–E3–E4–E5–E6. Cubre el registro de periodos de asociaciones y Consejo y su
relación con los cargos de Comunidad: apartados **6.1–6.3**, páginas 11–12 de
`Campus_Digital_Diseno_Modulos.pdf`. Complementa la entrega publicada el 15 de
octubre y el incremento de comisiones/documentos; conserva sus informes fechados.

## Uso

En **Comunidad → Organizaciones → Periodos de la organización**:

1. La presidencia vigente registra nombre, descripción opcional y fechas de un
   periodo. Los integrantes activos consultan periodos, mesas directivas e historial.
2. El listado distingue **programado**, **vigente**, **finalizado** y **cancelado**.
   Las fechas inicial y final son inclusivas. Dos periodos registrados de la misma
   organización no pueden compartir un día; tampoco pueden repetir nombre normalizado.
3. Al asignar o editar un cargo, presidencia puede seleccionar su periodo. Un
   cargo vinculado necesita inicio y fin explícitos dentro de esas fechas. La
   selección se conserva en posteriores cambios de titular y no se puede quitar
   ni mover a otro periodo.
4. **Ver mesa directiva** incluye cargos retirados o fuera de vigencia.
   **Ver cambios de cargos** muestra asignaciones, cambios de titular, retiros,
   bajas de cuenta y recuperaciones de presidencia relacionados con ese periodo.
5. Si un periodo nunca tuvo cargos, se pueden corregir sus fechas o cancelarlo
   con motivo. Después de utilizarlo, sus fechas quedan protegidas y no se puede
   cancelar, incluso si todos sus cargos ya fueron retirados. Se puede corregir
   nombre/descripción; los cambios quedan auditados.

El calendario se mantiene en **UTC**, igual que las vigencias de cargos existentes.
El último día sigue siendo válido hasta su término en UTC. Este incremento no
cambia ese criterio a Ciudad de México; las comisiones conservan su propio criterio
ya documentado. La pantalla muestra las fechas de calendario sin desplazarlas.

El periodo agrupa y limita fechas; los permisos efectivos continúan dependiendo
del cargo vigente y de la membresía/organización activas. Un cargo histórico no
otorga acceso. Las transferencias y bajas mantienen la revocación de delegaciones.

## Compatibilidad y límites

- Los cargos previos siguen funcionando con `periodo_id` ausente o nulo. No se
  inventan periodos ni se reasigna automáticamente el historial anterior.
- Un cliente anterior que omita `periodo_id` al editar conserva el vínculo. Enviar
  nulo o un periodo diferente cuando ya existe un vínculo se rechaza.
- Se mantiene un titular registrado por tipo de cargo y organización. Registrar
  un periodo futuro no crea una nueva mesa directiva ni un relevo automático.
  Planificar varias mesas simultáneas requiere otro incremento.
- Los cambios de titular dentro del mismo periodo actualizan el cargo existente
  y conservan el antes/después en auditoría. Retirar un cargo conserva su registro.
- La recuperación administrativa por consola mantiene los controles existentes:
  requiere presidencia vencida y un integrante activo. Si el mandato anterior
  estaba vinculado, lo archiva y crea uno nuevo sin periodo. No reescribe el mandato
  anterior ni reactiva delegaciones. El nuevo periodo debe vincularse expresamente
  después, desde la operación autorizada.
- Esto no concede roles institucionales, no cambia OAuth ni contratos de otros
  equipos y no autoriza operaciones financieras. Continúan pendientes la
  segmentación académica y el acuerdo de autoridad institucional con E1.

## API de sesión

Usa autenticación, CSRF y selección de organización existentes. La vista envía
`X-Organization-Id` en las escrituras de periodos y cargos para detectar un cambio
de organización en otra pestaña. No son endpoints de servicio OAuth entre equipos.
La vista consulta esta API; el servidor consulta únicamente los registros E6 del
periodo, cargos y auditoría. Los identificadores de persona son `User._id` de E1.

| Ruta bajo `/api/organizaciones/periodos` | Función y campos |
|---|---|
| `GET /` | Lista de 10 periodos por página; `page`, `situacion` opcional: `todos`, `programado`, `vigente`, `finalizado`, `cancelado` |
| `POST /` | Alta: `nombre`, `descripcion` opcional, `fecha_inicio`, `fecha_fin`, UUID `clave_solicitud` |
| `GET /{id}` | Periodo y 20 cargos por página, incluidos retirados; `page` opcional |
| `PUT /{id}` | Los mismos datos descriptivos/fechas y `version` de revisión |
| `POST /{id}/cancelar` | `version`, `motivo` de 10–1000 caracteres |
| `GET /{id}/historial` | 20 movimientos de cargos por página; `page` opcional |

Fechas: `YYYY-MM-DD`; nombre de 3–150 caracteres; descripción de hasta 1000.
El alta devuelve 201 y el reintento idéntico 200, sin duplicar registros. La clave
está vinculada al actor y contenido; reutilizarla con otra intención devuelve 409.
Reintentar un alta cancelada conserva la cancelación. No equivale a reabrirla.
Los cambios exigen `version` entera positiva; una revisión desactualizada devuelve
409. Una edición correcta incrementa la revisión. Reintentar la cancelación de
un registro ya cancelado devuelve su estado sin repetir auditoría.

Cada periodo incluye `situacion` calculada y `en_uso`. La respuesta de listado
incluye `puede_editar`, `page` y `last_page`. Los historiales exponen solamente
campos del cargo: identificadores, titular, tipo y fechas; omiten metadatos privados,
documentos y eventos de otras organizaciones. Las respuestas de esta API usan
`Cache-Control: private, no-store` y omiten claves/huellas internas de idempotencia.

Las fechas, nombres, vínculos o cancelaciones inválidos reciben 422. Consultar
un periodo de otra organización recibe 404; administrar sin permiso, 403.
El middleware mantiene sus respuestas de sesión y conflicto de contexto existentes.

Los endpoints existentes `POST /api/organizaciones/roles` y
`PUT /api/organizaciones/roles/{id}` aceptan el campo aditivo `periodo_id`.
La respuesta de `GET /api/organizaciones` añade el catálogo de periodos registrados
(`id`, `nombre`, `fecha_inicio`, `fecha_fin`) para ese formulario. No cambia el
contrato de cargos vigentes que consumen otros equipos.

## Persistencia y recuperación

Nueva colección propia: `periodos_organizacion`. La migración añade índices de
unicidad para solicitudes y nombres registrados, consulta de fechas y búsquedas
de cargos/auditoría por periodo. Reutiliza el bloqueo global de responsabilidades
y las transacciones MongoDB de Comunidad: una auditoría fallida revierte el cambio.

En una copia preparada del entorno alterno, como UID/GID `1000:1000`:

```bash
php artisan migrate --path=database/migrations/2026_10_16_000200_create_periodos_organizacion.php --force
```

No necesita reseed ni reinicio de la base. La migración ya está aplicada al
alterno de esta laptop. No ejecutar un rollback para pausar: su `down` elimina
los periodos y sus índices propios, aunque conserva cargos y auditoría. Un respaldo
debe incluir las colecciones E6 del mismo corte; volver a una versión anterior
del código no requiere borrar estos datos.

## Verificación

- Registro: siete pruebas de permisos, solapamiento inclusivo, reintentos,
  revisiones, cancelación y reversión ante auditoría fallida.
- Vínculos: ocho pruebas de límites de fechas, fin obligatorio, aislamiento,
  normalización de identificadores, compatibilidad, historial filtrado,
  último día inclusivo y recuperación sin perder el mandato anterior.
- Navegador Edge aislado: alta/edición/cancelación y lectura de integrante en el
  registro; asignación vinculada, fechas protegidas, retiro e historial en cargos.
- Regresión de Periodos, Vigencias, Comunidad, Continuidad y Delegaciones:
  **47 pruebas aprobadas, 390 aserciones**, 213.67 segundos.
- Sin errores JavaScript ni advertencias observadas en el flujo final. Sin
  desbordamiento horizontal a 320, 768, 1024 y 1440 píxeles.
- Compilación de producción correcta en **15.78 segundos**.

Cierre confirmado el **17 de octubre de 2026** sobre el código `74e4f19`:

- Suite integrada completa: **1161 pruebas aprobadas, 8327 aserciones**, cero
  errores, cero fallos y ninguna prueba omitida.
- Los **83 archivos de pruebas** se ejecutaron en **14 bloques seriales**, usando
  únicamente `campus_virtual_testing`. El inventario y los reportes JUnit coinciden:
  1161 casos únicos, sin clases faltantes ni casos repetidos.
- Tiempo acumulado de los bloques completos: **1399.42 segundos**. Cada bloque
  aprobado conserva su reporte y progreso, vinculados al mismo commit y a la
  huella del inventario para permitir una reanudación verificable.
- Las pruebas específicas y la regresión anterior son subconjuntos de esta suite;
  sus cifras no se suman al total integrado.

Evidencia local excluida de Git:
`storage/logs/periodos-organizacionales-20261016/`. El subdirectorio `validacion/`
contiene `manifest.xml`, `progress.json`, los 14 reportes `bloque-XX.xml` y los
logs de ejecución. Los logs de compilación y navegador están en el directorio padre.
La comprobación conserva un
periodo cancelado sin cargos y un periodo histórico finalizado con un cargo
retirado; este último nunca dio permisos vigentes por sus fechas pasadas.

La revisión humana de otro integrante sigue correspondiendo al equipo antes de
publicar o fusionar. Este avance se guarda en commits locales; no está publicado.
