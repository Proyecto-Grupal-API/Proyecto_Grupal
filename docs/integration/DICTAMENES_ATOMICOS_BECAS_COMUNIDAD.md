# Consistencia del dictamen de becas

Incremento local del 17 de octubre de 2026, entorno alterno E1–E3–E4–E5–E6.
Base `adc202e`. Atiende la persistencia del dictamen y de la asignación propia
de los apartados 6.4–6.5 del documento general, página 12.

## Problema corregido

Antes, una excepción al guardar la auditoría podía dejar la solicitud aprobada,
rechazada o en revisión, aunque la petición terminara con error. Si fallaba
la preparación del beneficio, la aprobación y su auditoría podían permanecer
sin la asignación correspondiente, consumiendo cupo.

El dictamen ahora ejecuta en una única transacción de la conexión MongoDB:

1. Las comprobaciones de estado, cupo y vigencia del beneficio.
2. La actualización de solicitud, motivo y responsable del dictamen.
3. El registro de auditoría y, al aprobar, la asignación local pendiente.

Se conserva el bloqueo existente por convocatoria, la autorización de revisión,
la prohibición de dictamen propio y los contratos/mensajes de la API. Una
excepción aborta estas escrituras y permite reintentar sin consumir otro lugar.
Repetir un dictamen final exitoso conserva su idempotencia y su única asignación.
La recuperación heredada de una aprobación sin asignación también se conserva.

## Alcance y operación

El alterno ya dispone de MongoDB en réplica. No requiere migraciones, reseed,
indicadores nuevos, clientes OAuth ni nuevos permisos. Se aplica desde el
controlador existente; las vistas y su compilación no cambian.

La preparación del beneficio escribe únicamente en E6. Su estado continúa
siendo `pendiente_integracion`; la entrega HTTP a E5 pertenece a otro proceso.
No hay llamadas externas dentro de esta transacción ni efectos monetarios.
No se promete atomicidad distribuida con E2/E5. Ante una respuesta perdida se
puede repetir el mismo dictamen; no se debe interpretar un error de red como
confirmación ni como revocación de una entrega.

Este corte no implementa comité colegiado ni cambia quién puede dictaminar.
Sus reglas de composición y decisión siguen pendientes de acuerdo. Tampoco
abarca las cargas/retiros de archivos, las demás escrituras de convocatorias,
la revocación de beneficios ya entregados ni las dependencias de Identidad
documentadas en `Dependencias_Equipo_1_Final.md`. No se considera resuelto todo
el expediente ni toda la integración financiera.

## Verificación

- RED real: cuatro casos fallaron porque el estado de la solicitud persistía
  después de la excepción (tres estados de dictamen y fallo de asignación).
- Primer GREEN: cuatro pruebas y 65 aserciones aprobadas, 9.85 segundos.
- La regresión también inyecta el fallo *después* de insertar la asignación,
  para comprobar que se revierten solicitud, asignación y auditoría ya escrita.
- Se comprueban reintentos y una sola asignación pendiente; las pruebas usan
  exclusivamente `campus_virtual_testing`, en serie y como UID/GID 1000:1000.
- Regresión final: **61 pruebas aprobadas, 571 aserciones**, 140.02 segundos;
  cinco archivos de becas, permisos, delegaciones, integración E6/E5 y API de
  beneficios E5. Reporte XML cotejado: 61 casos únicos, sin fallos, errores
  ni omisiones. Formato PHP y `git diff --check` correctos.
- No se repitieron la compilación de vistas ni la suite completa de 1,202 pruebas
  del incremento NFC: esa evidencia corresponde al código anterior. La validación
  de este arreglo comprende la regresión específica indicada arriba.

Evidencia local ignorada por Git: `storage/logs/dictamenes-becas-20261017/`.
El avance queda local; la instalación principal y GitHub no se modifican.

Continuidad posterior del 17 de octubre: [archivos privados de becas](ARCHIVOS_PRIVADOS_BECAS_COMUNIDAD.md).
Las cifras de este corte se conservan como evidencia del dictamen.
