# Revisión de archivos privados de Comunidad

Incremento local del 17 de octubre de 2026, base `fe354eb`, en el alterno
E1–E3–E4–E5–E6. Atiende el diagnóstico pendiente de los expedientes
organizacionales (6.1) y documentos de solicitudes de becas (6.4).

## Uso

Desde el entorno alterno ya preparado:

```bash
docker compose -f compose.integracion.yaml exec -T --user 1000:1000 app php artisan comunidad:revisar-archivos
docker compose -f compose.integracion.yaml exec -T --user 1000:1000 app php artisan comunidad:revisar-archivos --json
```

La primera forma muestra totales por disco; JSON incluye identificadores de E6
y rutas relativas de los casos observados, sin nombres originales ni rutas
absolutas del servidor. Mantener ese informe en almacenamiento privado: sus
identificadores y rutas no son un catálogo público. No se crea una ruta web.

El comando contrasta todas las referencias de `SolicitudBeca.documentos` y de
`VersionDocumentoOrganizacion.ruta` con los discos privados `becas` y
`organizacion_documentos`. Incluye solicitudes retiradas/canceladas, versiones
históricas y documentos retirados. No confunde historial con contenido sobrante.

Por cada disco informa:

- Cantidad de referencias y archivos observados.
- Referencias cuyo archivo no se encontró.
- Referencias con ruta inválida; no intenta abrir rutas absolutas, con retrocesos,
  separadores Windows ni caracteres de control. Omite la ruta inválida del informe.
- Archivos sin referencia observada: candidatos para revisión, no autorización
  para eliminarlos.

## Estados, límites y recuperación

`sin_observaciones` significa que no aparecieron esos casos durante el intervalo
de lectura. `con_observaciones` informa casos para revisar. Ambos retornan código
0 porque la inspección se completó; un script debe leer el estado y las listas
para distinguirlos. Ante fallo de almacenamiento, metadatos no inspeccionables
o error de serialización, emite `incompleta`, código 1 y ningún resultado parcial
que pueda confundirse con un almacén vacío correcto. El error técnico queda
en el log privado, sin copiarlo al JSON.

Es diagnóstico de solo lectura de los expedientes: no borra, restaura, reasigna
o modifica archivos, referencias, estados ni auditorías. No requiere migraciones,
reseeds, clientes OAuth ni permisos nuevos y no consulta colecciones de E1.

La lectura de MongoDB y archivos no es una instantánea coordinada: una carga o
retiro concurrente puede cambiar lo observado. Los tiempos de inicio/fin delimitan
el informe. Repetir en un periodo sin escrituras, revisar la operación incierta
y su auditoría y conservar un respaldo conjunto antes de decidir una corrección.
No existe borrado ni reparación automáticos. No determina a qué solicitud
pertenece un archivo sin referencia ni reconstruye relaciones por su nombre.
Tampoco compara el contenido con el hash documental, valida PDF/MIME ni prueba
que un archivo existente sea íntegro. Otros almacenes de módulos quedan fuera.

## Verificación

- Pruebas de MongoDB y discos aislados: vacío, referencia perdida, candidato,
  historial retirado, ruta inválida, caída del listado, metadatos no inspeccionables
  y error de codificación; comprueban la preservación de datos y archivos.
- Revisión real del alterno: becas 0 referencias/0 archivos; organizaciones
  2 referencias/2 archivos. Ningún faltante, ruta inválida o candidato observado.
- Hashes antes/después de solicitudes, documentos, versiones, auditoría y
  contenidos: sin cambios. Esta comparación demuestra preservación durante
  la inspección; no certifica la integridad original de los documentos.
- Salida legible y JSON comprobados. Siete pruebas nuevas aprobadas,
  48 aserciones, 13.49 segundos. Regresión de becas y documentos:
  34 casos y 408 aserciones aprobados en la ejecución inicial.
- Cotejo entre ambos XML: **41 casos únicos y 456 aserciones**
  validados, sin omisiones en la selección final. La primera regresión incluía
  un fallo del fixture por comparar el orden de campos previo a persistir.
  Se leyó el estado ya persistido antes del comando, manteniendo `assertSame`,
  y se repitieron solamente los siete casos nuevos. Se conserva el XML inicial
  con su fallo, sin presentarlo como una ejecución completa exitosa.
- También se corrigió la clave única del fixture histórico. Un error de JSON
  reproducido durante la revisión ahora devuelve estado incompleto.
- Formato PHP y `git diff --check` correctos. No cambiaron vistas ni contratos
  HTTP; no se repiten la compilación ni la suite completa histórica.

Evidencia local ignorada por Git: `storage/logs/revision-archivos-comunidad-20261017/`.
`diagnostico-real.json` se conserva con modo 0600. No se publica automáticamente
a GitHub ni se modifica la copia principal.
