# Aceptación con lector NFC físico — Comunidad 6.6

Estado: **preparada, no ejecutada con hardware**. Complementa `ACCESO_NFC_EVENTOS_COMUNIDAD.md`; no sustituye las pruebas existentes de API ni permite declarar acreditada una lectura física. Fecha del protocolo: 11 de noviembre de 2026.

## Recursos y preparación

- Lector que entregue el UID como teclado, como admite la vista actual; anotar marca/modelo y navegador. No se declara compatibilidad con cualquier dispositivo ni se requiere implementar Web NFC.
- Tarjeta activa registrada mediante los mecanismos oficiales de E1, vinculada a una cuenta de prueba autorizada. No insertar una tarjeta directamente en MongoDB ni usar otra persona como fallback.
- Organización activa; evento publicado, dentro de su ventana de acceso, y Staff vigente asignado a ese mismo evento/organización. Preparar el registro mediante la UI y APIs existentes.
- Alumno con inscripción confirmada y pago exento/confirmado. Usar un evento gratuito para aislar la lectura NFC de cualquier cobro. Una espera o pago pendiente no se convierte en entrada por identificar al alumno.
- En el entorno que se probará, revisar la bandera `COMUNIDAD_EVENTOS_NFC_HABILITADO` y el cliente E1 con `identity:nfc:validate`. La configuración/permiso debe estar autorizada; este documento no la activa ni concede atribuciones.

## Recorrido

1. Iniciar sesión con el Staff de prueba y abrir Comunidad → Staff. Seleccionar el evento exacto y el método NFC.
2. Confirmar que el campo de captura tenga el foco. Acercar la tarjeta y comprobar que el lector escriba el UID y envíe Enter o permita pulsar validar.
3. Verificar que se registre una sola asistencia y se muestre al asistente correcto, sin habilitar entradas de otro evento.
4. Leer otra vez la misma tarjeta: debe indicar que la inscripción ya fue utilizada y conservar una sola asistencia.
5. Probar una tarjeta de prueba desconocida o bloqueada desde la gestión oficial de E1: rechazar sin crear asistencia. No bloquear tarjetas ajenas para realizar el ensayo.
6. Probar una cuenta de prueba en espera/sin cupo o pago pendiente: rechazar el acceso aunque E1 reconozca la tarjeta.
7. Con un evento o Staff fuera de su ámbito/vigencia, rechazar la operación. La interfaz no constituye autorización; debe decidir el backend.
8. Si se dispone de un entorno aislado controlable, cortar temporalmente el transporte E1 y comprobar que no se registre una asistencia. No interrumpir un servicio compartido para ensayarlo.
9. Volver a QR y comprobar que el campo/resultado NFC se limpien y que no se mezclen ambas credenciales. No enviar usuario_id ni dos credenciales como alternativa.

API usada por la vista: `POST /api/v1/comunidad/portal/eventos/checkin`, sesión web, CSRF y limitación de solicitudes. Para NFC: `evento_id`, `metodo=nfc`, `credential_uid`. La resolución usa la API oficial de Identidad; la asistencia y su auditoría pertenecen a E6.

## Evidencia que debe completarse

| Dato | Resultado real |
|---|---|
| Fecha/hora y zona de la prueba | Pendiente |
| Entorno y commit | Pendiente |
| Marca/modelo del lector y navegador | Pendiente |
| Evento y cuentas de prueba (IDs de referencia) | Pendiente |
| Primera lectura: una asistencia | Pendiente |
| Segunda lectura: sin duplicado | Pendiente |
| Tarjeta no utilizable: sin asistencia | Pendiente |
| Sin cupo/pago pendiente: acceso bloqueado | Pendiente |
| Staff/evento fuera de ámbito: rechazo | Pendiente |
| Evidencia visual y auditoría sin UID/token expuestos | Pendiente |
| Responsable que ejecutó y validó | Pendiente |

La aceptación se cierra con el resultado observado y sus comprobantes, no rellenando la tabla como aprobada a partir de pruebas automatizadas. Se conserva el estado pendiente si falta un recurso o condición.
