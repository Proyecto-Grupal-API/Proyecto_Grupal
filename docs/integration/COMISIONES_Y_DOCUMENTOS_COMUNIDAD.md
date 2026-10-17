# Comisiones y documentos de Comunidad

Incremento del 16 de octubre de 2026 en el entorno alterno E1–E3–E4–E5–E6.
Corresponde a las comisiones del Consejo (6.2) y al expediente documental de las
organizaciones (6.1), páginas 11–12 de `Campus_Digital_Diseno_Modulos.pdf`.
El informe y PDF del 15 de octubre conservan su carácter histórico.

## Uso y responsabilidades

En **Comunidad → Organizaciones**:

- La presidencia del Consejo encuentra **Comisiones del Consejo**: crear, definir
  propósito y periodo, asignar integrantes/coordinación y cerrar con motivo.
- Cada comisión admite una coordinación. Sus integrantes deben ser miembros
  activos del Consejo. Coordinar o participar no concede permisos adicionales.
- El periodo usa fechas de Ciudad de México, con último día inclusivo. Fuera de
  vigencia no se pueden añadir participaciones. El cierre conserva el historial.
- Las bajas de membresía o cuenta retiran participaciones; reingresar no las
  reactiva. Las comisiones son independientes de las delegaciones de capacidades.
- Tanto asociaciones como Consejo tienen **Documentos de la organización**.
  Presidencia carga PDF/JPG/PNG de hasta 5 MB, con título, categoría, descripción
  opcional y fecha. La visibilidad inicial es **Solo presidencia**.
- Se puede compartir la versión actual con integrantes activos. El historial de
  archivos anteriores siempre corresponde a presidencia, aunque luego se publique
  la versión actual. No existen enlaces públicos a los archivos.
- **Cargar nueva versión** conserva el archivo anterior, exige motivo y mantiene
  la visibilidad del documento. Editar metadatos no crea otra versión de archivo.
- **Retirar documento** exige motivo, impide cambios posteriores y lo oculta a
  integrantes; los archivos e historial permanecen disponibles para presidencia.

E1 conserva identidad, roles y autoridad institucional. E6 usa `User._id` como
identificador y solo consulta sus propias colecciones para estas funcionalidades.
Las vistas consumen la API. Este incremento no cambia contratos entre equipos,
allowlists, clientes OAuth ni autoridad financiera.

## API de sesión

Todas las rutas usan autenticación, CSRF y selección de organización existentes.
Las escrituras de la vista envían `X-Organization-Id` para detectar cambios de
organización en otra pestaña. Los listados están paginados.

| Ruta bajo `/api/organizaciones` | Función |
|---|---|
| `GET/POST comisiones` | Consultar o crear comisiones del Consejo |
| `GET/PUT comisiones/{id}` | Integrantes/historial o edición con revisión |
| `POST comisiones/{id}/cerrar` | Cierre con motivo |
| `POST comisiones/{id}/integrantes` | Asignar integrante o coordinación |
| `POST comisiones/{id}/integrantes/{asignacion}/retirar` | Retiro histórico |
| `GET/POST documentos` | Consultar o cargar documentos |
| `PUT documentos/{id}` | Editar metadatos y visibilidad |
| `POST documentos/{id}/retirar` | Retirar sin borrar archivos |
| `GET documentos/{id}/descargar` | Descargar versión actual autorizada |
| `GET/POST documentos/{id}/versiones` | Consultar historial o cargar versión |
| `GET documentos/{id}/versiones/{version}/descargar` | Descargar versión autorizada |

Las altas/asignaciones/cargas llevan UUID `clave_solicitud`: un reintento idéntico
no duplica registros ni archivos. La clave se vincula al actor y contenido; otro
contenido con esa clave produce conflicto. Las ediciones, nuevas versiones y
retiros documentales exigen `version` de revisión; cambios desactualizados reciben
409. `version_actual.numero` identifica el archivo y es independiente de esa revisión.
Reintentar un retiro o cierre ya aplicado no repite el evento de auditoría.

## Persistencia y recuperación

Colecciones propias: `comisiones_consejo`, `integrantes_comision`,
`documentos_organizacion`, `versiones_documentos_organizacion` y auditoría E6.
Se reutilizan el bloqueo de responsabilidades y transacciones de MongoDB.
Las migraciones crean índices de unicidad para claves, versiones y asignaciones.

Los archivos se guardan con nombre generado en el disco privado
`organizacion_documentos`, bajo `storage/app/private/organizaciones`.
Las respuestas omiten rutas internas. Las descargas verifican organización,
membresía, visibilidad y versión; se sirven como adjunto con `nosniff`, sin caché,
y registran auditoría.

Si falla la transacción de persistencia, se elimina el archivo recién almacenado
y se conserva la versión anterior. Ante una desconexión de MongoDB o commit con
resultado incierto se conserva el archivo privado: requiere conciliación con sus
metadatos, porque eliminarlo podría perder un archivo cuyo commit sí ocurrió.
No hay una tarea automática de conciliación en este incremento.

Para respaldar o mover el entorno se necesitan **MongoDB y el directorio privado**
del mismo corte. Revertir código no exige borrar documentos; no se debe ejecutar
un rollback destructivo para una pausa. Las migraciones nuevas se pueden aplicar
individualmente en una copia preparada del entorno, sin reiniciar la base:

```bash
php artisan migrate --path=database/migrations/2026_10_16_000000_create_comisiones_consejo.php --force
php artisan migrate --path=database/migrations/2026_10_16_000100_create_documentos_organizacion.php --force
```

## Verificación del incremento

- Comisiones: siete pruebas, 66 aserciones. Regresión previa de organizaciones,
  continuidad, Comunidad y delegaciones: 51 pruebas, 441 aserciones.
- Regresión conjunta: **59 pruebas aprobadas, 524 aserciones**, 181.89 segundos.
- Documentos: ocho pruebas; tipos/extensiones/tamaño, privacidad por defecto, aislamiento,
  reintentos, historial privado, cambios desactualizados, retiro, baja de miembro
  y fallos de persistencia tanto en alta como en nueva versión.
- Edge con sesiones demo aisladas: alta, coordinación, cierre e historial de
  comisión; carga privada, nueva versión, compartir, descarga y retiro documental.
  Integrantes no pueden administrar ni consultar versiones documentales anteriores.
- Sin errores JavaScript observados; sin desbordamiento horizontal a 320, 768,
  1024 y 1440 píxeles. Compilación de producción comprobada.
- La comisión de verificación quedó cerrada y el documento de verificación
  retirado; ambos conservan historial. No se cambiaron roles institucionales.

Cierre validado el **16 de octubre de 2026** sobre el código `907c0d0`:

- Inventario completo: **1146 pruebas aprobadas, 8189 aserciones**, cero errores,
  cero fallos y ninguna prueba omitida.
- Se ejecutaron los 81 archivos de pruebas en **14 bloques seriales** del mismo
  código, usando únicamente `campus_virtual_testing`. Los reportes JUnit se
  contrastaron con el inventario: 1146 casos únicos y ninguna clase faltante.
- Tiempo acumulado de los bloques completos: **1487.08 segundos**. Los intentos
  interrumpidos no se incluyen en los totales; los bloques completos se conservaron
  para reanudar la validación después de los reinicios.
- `npm run build`: correcto en **14.60 segundos**. Formato PHP aplicado a los
  seis archivos nuevos del expediente documental.
- Navegador después del reinicio: vista móvil y navegación por teclado correctas,
  descarga de las versiones 1 y 2 conservadas; sin errores JavaScript, advertencias
  de consola ni errores HTTP en las rutas documentales observadas.

Evidencia local, excluida de Git:
`storage/logs/comunidad-comisiones-documentos-20261016/`. Incluye `manifest.xml`,
`progress.json`, los 14 reportes `bloque-XX.xml`, los logs de cada bloque,
compilación y comprobaciones del navegador.

Las cifras específicas son subconjuntos y no deben sumarse a la suite completa.
La revisión humana por otro integrante, exigida por el documento general, sigue
siendo una actividad del equipo antes de publicar o fusionar.

## Trabajo que permanece separado

Este incremento no termina todos los apartados 6.1–6.3: siguen pendientes el
periodo organizacional como entidad propia, la segmentación académica y la
autoridad institucional acordada con E1. Una comisión tampoco equivale todavía
a un comité de becas con reglas colegiadas de dictamen. Las integraciones
financieras y demás dependencias mantienen sus estados anteriores.
