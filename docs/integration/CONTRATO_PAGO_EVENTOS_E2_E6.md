# Contrato del piloto de pago de eventos E2–E6

Corte del 8 de noviembre de 2026. La primera entrega cubre el pago en MXN con la wallet del alumno en un entorno de pruebas. El flujo está implementado y probado con cuentas desechables. La habilitación del alterno se controla por separado.

## Responsabilidades y datos

- Comunidad asigna lugares, conserva la generación de la inscripción y determina el precio en centavos, la vigencia y el acceso. El navegador envía la generación que observó y el servidor obtiene el titular de la sesión.
- Finanzas administra el cargo, su comprobante, el estado financiero y la cuenta receptora. Resuelve el receptor a partir de un vínculo explícito con la organización. El piloto usa una wallet institucional de prueba por organización.
- La aplicación integrada reutiliza la sesión vigente y el flujo de confirmación de contraseña de Identidad. El alumno debe aceptar el importe y el concepto en la pantalla de Finanzas. OAuth acredita al servicio E6 para preparar, consultar o cerrar la solicitud.
- E2 y E6 se consultan por API. Cada módulo guarda y modifica sus propios datos. Los contactos, documentos y tokens QR permanecen en su dominio.

## Reserva y vigencia

E6 congela el titular, evento, organización, importe, moneda y vigencia al preparar el primer intento de pago. La reserva dura hasta el menor de **15 minutos desde la asignación** y el inicio del evento. La lista de espera no consume plazo y empieza su propio plazo al ser promovida, aunque ya haya cerrado la inscripción general. La hora límite es exclusiva y viaja en UTC con precisión de milisegundos. Reintentar conserva el plazo original.

La identidad estable del pago es `event-reservation:{registro_id}:{generacion}`. Una reinscripción tiene una generación y una referencia nuevas. E6 guarda cada intento financiero por separado de la inscripción actual para poder conciliar respuestas de generaciones anteriores. El identificador canónico del alumno es `User._id`.

## API del piloto

La API usa un recurso por inscripción y generación. La ruta base es `/api/v1/financial/community/event-reservations/{registroId}/generations/{generation}/payment`.

| Operación | Alcance OAuth | Resultado |
| --- | --- | --- |
| `POST` a la ruta base | `financial:event-payment:prepare` | Prepara la solicitud. Devuelve 201 al crearla y 200 en un reintento idéntico. |
| `GET` a la ruta base | `financial:event-payment:read` | Devuelve el estado y la evidencia guardada. Devuelve 404 si la referencia es desconocida para ese cliente. |
| `POST` a la ruta base más `/cancel` | `financial:event-payment:cancel` | Resuelve la cancelación frente al cargo concurrente. Devuelve el estado definitivo que ganó. |

La preparación envía la referencia en `Idempotency-Key`. E6 deriva del registro los datos del ejemplo.

```json
{
  "student_id": "507f1f77bcf86cd799439011",
  "event_id": "507f1f77bcf86cd799439012",
  "organization_id": "507f1f77bcf86cd799439013",
  "amount_cents": 2500,
  "currency": "MXN",
  "reservation_expires_at": "2026-11-08T11:15:00.000Z"
}
```

La respuesta incluye `source_reference`, `request_id`, `status` y los datos congelados. `PENDING` incluye `confirmation_path` relativo a Finanzas. `CONFIRMED` incluye `payment_id` y `confirmed_at`. Si se pidió cerrar antes de crear, la respuesta `CANCELLED` contiene la referencia y el estado, con los datos del cargo vacíos. E6 verifica cliente, referencia y datos congelados antes de cambiar el acceso. Un reintento con otro titular, importe, evento, organización o vencimiento devuelve 409. Entradas inválidas devuelven 422. La falta de permiso se reporta con 401 o 403, según corresponda.

## Confirmación, cierre y recuperación

1. E6 registra el intento local bajo el bloqueo del evento antes de llamar a E2. La llamada HTTP ocurre fuera de la transacción MongoDB. Una respuesta perdida se recupera por la misma referencia y conserva la intención original.
2. E2 confirma el cargo bajo bloqueo SQL, con la cuenta del alumno, vigencia, consentimiento y receptor verificados. El débito, el abono y el comprobante se guardan juntos. Repetir la confirmación devuelve la misma evidencia.
3. E6 aplica `CONFIRMED` bajo el bloqueo del evento si la generación coincide, el evento sigue habilitado y el lugar sigue vigente. Solo entonces marca la inscripción pagada y permite que se muestre su QR. El acceso de Staff conserva su ventana y demás reglas.
4. Para cancelar un intento iniciado, E6 guarda la intención de cierre y pide a E2 que resuelva el estado. E2 serializa el cierre con el cobro. Si la solicitud aún no existe, persiste un cierre para esa referencia que impide crearla más tarde. Una respuesta ambigua deja la cancelación pendiente de conciliación. `CANCELLED` o `EXPIRED` definitivos permiten cerrar el intento. Si ganó el pago y el lugar sigue vigente, E6 registra el comprobante.
5. Al vencer la reserva, E6 libera el cupo y promueve la espera según sus reglas. Si E2 confirma un cargo y la reserva ya venció, se canceló, cambió de generación o perdió el lugar, E6 conserva el comprobante y registra `devolucion_pendiente`. La devolución requiere su procedimiento autorizado. Este caso mantiene bloqueado el QR aunque quede un lugar libre más tarde.

Una consulta que infiere el vencimiento por reloj sirve para bloquear la pantalla, pero el estado financiero final debe resolverse bajo el mismo bloqueo del cobro. El proceso de conciliación debe poder continuar aunque el alumno cierre la pestaña.

## Matriz de aceptación

Los casos de Comunidad se ejecutan hoy sobre su base de prueba. Los casos E2–E6 definen la aceptación del piloto con SQL Server y MongoDB desechables.

| Caso | Evidencia esperada | Alcance |
| --- | --- | --- |
| C01 · Reserva inicial y reintento | Plazo de 15 minutos, mismo vencimiento al repetir y QR bloqueado con pago pendiente. | Comunidad |
| C02 · Espera y promoción | Sin plazo en espera. Al vencer el primer lugar, la siguiente persona recibe su propio plazo, limitado por el inicio del evento. | Comunidad |
| C03 · Reinscripción y solicitud antigua | Vence la generación 1, se crea la 2 y un reintento de la anterior conserva la reserva nueva. | Comunidad |
| C04 · Cancelación con pago registrado | Con una confirmación sembrada como dato de prueba, cancelar conserva el lugar pagado y la lista de espera. | Comunidad |
| P01 · Cargo de $25.00 MXN | E2 guarda un débito de 2500 y un abono de 2500, con un solo comprobante. E6 registra ese ID y habilita un boleto para el titular. | E2–E6 |
| P02 · Respuesta perdida | Repetir la preparación, la confirmación y la consulta conserva un solo cargo y comprobante. Se prueba también al cerrar la pestaña. | E2–E6 |
| P03 · Límite exacto | Probar un milisegundo antes, al alcanzar el límite y un milisegundo después. Al llegar a la hora límite ya no se permite un nuevo cargo. | E2–E6 |
| P04 · Cancelación concurrente | Ejecutar ambos órdenes. Si gana el cierre, saldo intacto. Si gana el cargo, recuperar el comprobante y aplicar la regla de lugar vigente. Incluir cancelación previa a la creación y reintento tardío. | E2–E6 |
| P05 · Confirmación sin lugar vigente | Con lugar vencido o reasignado, registrar `devolucion_pendiente` con el comprobante. La persona promovida conserva su lugar y la inscripción anterior sigue sin QR. | E2–E6 |
| P06 · Respuesta de la generación anterior | Conciliar el pago antiguo por su propia referencia. La nueva inscripción conserva su plazo, estado y generación. | E2–E6 |
| P07 · Controles | Rechazar titular ajeno, alcance insuficiente, evidencia alterada, saldo insuficiente y receptor de otra organización. Una solicitud en espera o exenta no abre un cobro. Verificar saldos y QR tras cada rechazo. | E2–E6 |

Para activar con cuentas reales queda acreditar la cuenta de cada organización, el cliente OAuth de E6 y su habilitación en E2, además de la autoridad para aprobar devoluciones.

## Habilitación y evidencia

- E2 usa `FINANCIAL_EVENT_PAYMENTS_ENABLED`, `FINANCIAL_EVENT_PAYMENTS_CLIENT_ID` y `FINANCIAL_EVENT_RECIPIENTS_JSON`. El mapa de receptores usa el ID de organización como clave y contiene `wallet_id` y `name`. El receptor es una wallet de organización activa en MXN cuyo titular coincide con la organización.
- E6 usa `COMUNIDAD_EVENTOS_PAGOS_ENABLED`, `COMUNIDAD_FINANZAS_URL`, `COMUNIDAD_FINANZAS_CLIENT_ID` y `COMUNIDAD_FINANZAS_CLIENT_SECRET`. Las dos banderas son falsas por defecto. El reconciliador `comunidad:conciliar-pagos-eventos` recupera estados una vez por minuto cuando se habilita E6.
- Desde **Mis boletos**, el titular puede usar **Pagar con wallet** o **Consultar pago**. El acceso conserva las condiciones de Staff. Si la reserva se pierde, el registro financiero de esa generación queda en `devolucion_pendiente`, con su comprobante.
- La prueba HTTP desechable autorizó **$25.00 MXN**, obtuvo un comprobante y habilitó el QR tras la conciliación. Repetir la confirmación conservó un cargo y dos movimientos que se balancean. Una segunda reserva se canceló antes del pago y se liberó con el saldo intacto.
- Pasaron **101 pruebas de Finanzas, Eventos y Staff, con 1,640 aserciones**. Las pruebas enfocadas cubren vencimiento exacto, cierre previo a preparar, evidencia alterada y pago de una generación anterior. La resolución automática de devoluciones sigue fuera del piloto.

## Conciliación de una devolución

La consulta por inscripción y generación conserva `status: CONFIRMED`
para el cobro original. Cuando E2 tiene una devolución completa ejecutada
en una sola operación, devuelve `refund` con `refund_id`,
`original_payment_id`, `amount_cents` y `currency`. Una devolución pendiente,
rechazada o parcial conserva `refund: null`.

Comunidad aplica el comprobante solo al intento en `devolucion_pendiente`
que coincide con el pago, importe y moneda. Guarda
`comprobante_devolucion`, registra una auditoría y cambia el estado
financiero a `devuelto`. La conciliación no reasigna cupos ni cambia
la generación vigente o el estado de acceso. La autorización y la
ejecución de la devolución permanecen en E2.
