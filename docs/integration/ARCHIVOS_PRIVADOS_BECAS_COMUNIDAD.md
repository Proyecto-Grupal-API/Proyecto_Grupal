# Consistencia de archivos privados de becas

Incremento local del 17 de octubre de 2026, base `3deaf61`, únicamente en el
entorno alterno. Continúa el expediente de solicitudes del apartado 6.4,
página 12 del documento general.

## Comportamiento

La carga mantiene el archivo en el disco privado `becas` y guarda sus metadatos
junto con la auditoría en una transacción MongoDB. Si la transacción falla con
resultado conocido, se revierte la referencia y se elimina únicamente el archivo
recién cargado. Los documentos anteriores se conservan. Una respuesta inválida
del almacenamiento impide guardar una referencia sin archivo.

Una desconexión MongoDB o la etiqueta `UnknownTransactionCommitResult`, incluso
en una excepción encadenada, conserva el archivo privado para conciliación.
Podría existir un commit confirmado en el servidor cuya respuesta se perdió:
no es seguro borrar el archivo en ese caso. No se afirma una transacción entre
MongoDB y el sistema de archivos ni se declara resuelta una caída real de red.

El retiro guarda la eliminación de la referencia y su auditoría en una sola
transacción. Solo después del commit confirmado intenta eliminar el contenido.
Un fallo de auditoría conserva tanto referencia como contenido y permite volver
a descargar o retirar el documento.

Si falla el borrado físico después del retiro confirmado, la respuesta informa:
«Documento retirado del borrador. Su limpieza privada está pendiente». El archivo
queda privado, sin una referencia descargable desde la solicitud. No se restaura
el acceso, no se anuncia un borrado físico completo y el fallo se reporta en el
log del servidor. Si la limpieza de una carga abortada también falla, se conserva
la excepción original de persistencia y se reporta el fallo secundario.

## Contrato y límites

Se conservan las rutas, campos y códigos de sesión actuales; solo añade un mensaje
preciso para el caso de limpieza pendiente. No cambian permisos, requisitos,
fechas, límite de cinco archivos de 5 MB, formatos PDF/JPG/PNG, almacenamiento
privado ni auditoría sin rutas internas. Las vistas ya muestran el mensaje de
la API y no necesitan recompilarse. No requiere migraciones o reseed.

No introduce clientes/permisos OAuth ni toca las APIs de otros equipos. No
autoriza beneficios, pagos o dictámenes. La carga no es idempotente por UUID de
intención: tras una respuesta perdida hay que recargar el expediente para comprobar
su estado antes de volver a subir. Permanecen pendientes la conciliación de archivos
sin referencia, su limpieza administrativa y las demás escrituras del expediente.
No borrar automáticamente archivos privados solo porque una petición terminó con error.
Los respaldos siguen necesitando MongoDB y el directorio privado del mismo corte.

## Verificación

- RED: cuatro fallos reproducidos: carga y retiro persistían pese al fallo de
  auditoría, limpieza fallida anunciaba borrado y carga incierta dejaba referencia
  sin auditoría. Se conservan los logs originales.
- Primer GREEN: cuatro pruebas y 44 aserciones aprobadas, 11.22 segundos.
- Casos adicionales: borrado físico devuelve false o lanza excepción; fallo
  secundario de limpieza no sustituye el error original de la carga.
- El resultado incierto se prueba con una etiqueta inyectada y encadenada; es
  comprobación de la política conservadora, no una desconexión real certificada.
- Regresión final: **67 pruebas aprobadas, 636 aserciones**, 172.33 segundos,
  cinco archivos de becas, permisos, delegaciones e integración/API E5. XML
  cotejado: 67 casos únicos, sin fallos, errores ni omisiones. Formato PHP y
  `git diff --check` correctos. No se repite ni se atribuye a este corte la
  suite completa anterior de NFC.
- Edge aislado: carga real del PDF de diseño, descarga de contenido idéntico
  comprobada por SHA-256 y eliminación desde la vista. Sin errores JS/consola
  ni desbordamiento a 320/768/1024/1440 px. La solicitud de prueba quedó retirada
  y su convocatoria cancelada, sin dictámenes ni asignaciones de beneficios.
  Convocatoria `6ad3c8b12765ee007308e14a`, solicitud `6ad3c8b2e95378c3f809a6a4`.
- Se conserva el primer intento del guion de navegador: intentó llenar cantidad
  antes de seleccionar un beneficio de servicio; no guardó datos. Se corrigió
  únicamente el orden del guion y el recorrido completo posterior pasó.
- Las vistas no cambiaron; se usa la compilación ya verificada del incremento NFC.

Pruebas seriales únicamente contra `campus_virtual_testing`, UID/GID 1000:1000.
Evidencia ignorada por Git: `storage/logs/archivos-becas-20261017/`.

Continuidad: la [revisión de archivos privados](REVISION_ARCHIVOS_PRIVADOS_COMUNIDAD.md)
añade un diagnóstico de solo lectura. La reparación y limpieza administrativas
continúan siendo decisiones separadas; no elimina archivos conservados por incertidumbre.
