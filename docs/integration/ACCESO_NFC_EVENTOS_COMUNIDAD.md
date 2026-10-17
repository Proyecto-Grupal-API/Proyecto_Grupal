# Acceso NFC a eventos de Comunidad

Incremento local del **17 de octubre de 2026**, apartado 6.6 del documento
general. Base `e83a120`, entorno alterno E1–E3–E4–E5–E6.

## Comportamiento

Staff puede validar un boleto QR/código o una credencial NFC. La credencial se
resuelve exclusivamente mediante la API oficial de E1; Comunidad usa el
`User._id` retornado para buscar la inscripción propia del evento. No consulta
colecciones NFC/perfiles de E1 ni crea otra fuente de identidad.

Identificarse no concede una entrada. Se exige organización activa, permiso
vigente de Staff/presidencia, evento publicado, ventana de acceso, inscripción
confirmada, pago pagado/exento y asistencia todavía pendiente. No se promueve
la espera ni se confirma un pago desde el escaneo. Una segunda lectura no crea
otra asistencia. El lector no modifica cargos ni poderes financieros.

La autorización y el horario se comprueban antes de contactar E1 y se vuelven a
comprobar dentro del bloqueo/transacción de asistencia. La llamada HTTP ocurre
fuera del bloqueo y de la transacción MongoDB. La decisión de E1 corresponde al
momento de esa consulta; no se afirma una transacción distribuida con E1.

Una tarjeta bloqueada/desconocida se rechaza; una caída, falta de scope o respuesta
mal formada impide registrar asistencia. No hay fallback a matrícula o usuario
enviado por el navegador. La auditoría conserva actor, inscripción y método de
acceso, sin guardar UID ni token de boleto. Si falla la auditoría se revierte
la asistencia. El método no crea un historial nuevo de credenciales.

## API

Se conserva `POST /api/eventos/checkin`, sesión, CSRF y límite de solicitudes.
El contrato de boleto anterior sigue aceptando `evento_id` y `token_qr`.
Para NFC se envía exclusivamente:

```json
{"evento_id":"ObjectId del evento","metodo":"nfc","credential_uid":"UID presentado"}
```

No se aceptan las dos credenciales juntas ni un `usuario_id` elegido por el
navegador. La respuesta mantiene `asistente.name`, `asistente.matricula` y
`asistio_en`. Los detalles de E1 y la credencial no se devuelven al cliente.
El listado de Staff añade `nfc_habilitado` para presentar su disponibilidad.

Comunidad solicita el scope `identity:nfc:validate` y consume
`POST /api/v1/identity/nfc-validate` con `credential_uid`. Se valida el contrato:
`ok=true`, resultado válido, credencial activa, coincidencia de `student_id` y
`identity.user_id`, ObjectId y proyección mínima con tipos correctos.
Los errores de credencial/inscripción siguen siendo 422; indisponibilidad E1
es 503; permisos/contexto se conservan. Una respuesta perdida puede reintentarse:
el segundo intento informa que la inscripción ya se utilizó, sin repetir efectos.

El camino QR conserva sus búsquedas heredadas al directorio documentadas en
`Dependencias_Equipo_1_Final.md`; este incremento no afirma haber cerrado toda
esa deuda. La ruta NFC no añade ninguna de esas lecturas.

## Vista y lector

En Comunidad → Staff se selecciona el evento y el método. El modo NFC permite
capturar el UID por teclado o con un lector que lo escriba como teclado; Enter
envía la validación. La cámara y la carga de imagen siguen leyendo boletos QR.
Cambiar método limpia el campo y el resultado para evitar mezclar credenciales.
No se han probado lectores físicos ni se declara soporte de cualquier hardware.

## Activación pendiente de autorización

Al preparar el incremento, el cliente existente `comunidad` está activo y tiene
solo `students:read`. El código queda deshabilitado por defecto mediante
`COMUNIDAD_EVENTOS_NFC_HABILITADO=false`. No se utiliza el cliente de Servicios
para eludir ese límite.

Operación preparada para revisión: agregar **únicamente**
`identity:nfc:validate` al cliente existente `comunidad` del alterno y poner su
indicador local en `true`, conservando `students:read`, ID y secreto existentes.
Se registra el cambio en el dominio de E1. No crea tarjetas, roles, clientes
nuevos, allowlists, permisos financieros ni modifica la instalación principal.

El script preparado `activar-nfc.php` está en la carpeta de visualizaciones de
este chat y debe ejecutarse solamente tras autorización explícita del usuario.
Es una operación de administración local de E1, no una ruta de Comunidad para
otorgarse permisos. No se versiona `.env` ni se muestran credenciales.

Para pausar posteriormente, poner el indicador local en `false`; las asistencias
y su auditoría se conservan. Revocar el scope corresponde a la administración
autorizada de E1. No se necesita revertir migraciones ni modificar pagos.

## Verificación

- RED observado: la ruta exigía `token_qr` y no aceptaba el método NFC.
- Primer corte: 25 pruebas aprobadas, 248 aserciones, 70.09 s (NFC, Staff y reservas).
- API real E6→E1, sin respuestas simuladas: un caso, 11 aserciones, 3.28 s.
  Token OAuth y credencial activa en servidor efímero, luego bloqueo en E1 y rechazo.
  El servidor rechaza bases distintas de `campus_virtual_testing`; no se crean
  tarjetas ni permisos en la demo para fabricar esta evidencia.
- Compilación inicial: correcta, 10.31 s.
- Edge aislado: selector de métodos, NFC deshabilitado, QR inválido rechazado,
  sin errores JavaScript ni consola inesperada. Sin desbordamiento horizontal a
  320/768/1024/1440 px. El evento gratuito de prueba quedó cancelado.
- Regresión final: **61 pruebas aprobadas, 583 aserciones**, 115.24 s.
- Suite completa sobre `ded236c`: **1,202 pruebas aprobadas,
  8,685 aserciones**, sin errores, fallos ni omisiones. **91 archivos,
  16 bloques seriales**, 1310.86 segundos acumulados, únicamente
  en `campus_virtual_testing`, UID/GID 1000:1000. Los XML se cotejaron con el
  inventario del commit: todos los casos, sin duplicados ni faltantes.
- Código en `849c139` y vista/guía en `ded236c`. La activación del permiso y
  del indicador local sigue pendiente de aprobación explícita; no se declara
  que NFC esté habilitado en la demo. La comprobación posterior usará una
  credencial inexistente para verificar el rechazo, sin crear tarjetas.

Evidencia local excluida de Git: `storage/logs/nfc-comunidad-20261017/`.
Capturas y respaldo de código previo en la carpeta de visualizaciones de este chat.
Los informes históricos conservan sus cifras. No se publica a GitHub desde este incremento.
