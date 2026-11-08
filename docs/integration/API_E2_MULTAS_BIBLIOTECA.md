# Pago de multas de biblioteca con la wallet

E5 administra la multa en MongoDB. E2 administra la solicitud de pago, el saldo y el comprobante en SQL Server. El alumno autoriza el cargo en la aplicación web integrada. El cliente OAuth de E5 puede solicitar, consultar y cancelar la solicitud de pago.

| Método y ruta bajo `/api/v1/financial/student-services/library-fines` | Alcance OAuth |
| --- | --- |
| `POST /{fine}` | `financial:library-fine:prepare` |
| `GET /{fine}` | `financial:library-fine:read` |
| `POST /{fine}/cancel` | `financial:library-fine:cancel` |

`{fine}` es el ID MongoDB de E5 en hexadecimal minúsculo. E5 envía los datos obtenidos de su registro pendiente.

```json
{"student_id":"507f1f77bcf86cd799439012","amount_cents":1500,"currency":"MXN"}
```

E2 toma la cuenta institucional de su configuración. El ID de la multa es la clave natural de idempotencia. La primera preparación devuelve 201, repetir los mismos datos devuelve 200 y cambiar el alumno o el importe devuelve 409. Consultar una referencia desconocida devuelve 404. Las respuestas contienen `data` con `source_reference`, `student_id`, `amount_cents`, `currency`, `concept=library_fine`, `status`, `payment_id` y `confirmation_path`.

- `PENDING`. E5 puede dirigir al alumno a `confirmation_path`. E2 exige la cuenta titular, sesión de E1 vigente, contraseña confirmada en los últimos cinco minutos y consentimiento explícito vinculado al detalle del cobro. La solicitud permanece pendiente si falta saldo o se supera un límite de pago.
- `CONFIRMED`. E2 debitó al alumno y acreditó a Servicios al Estudiante en la misma transacción. `payment_id` es el ID de esa operación y permite abrir el comprobante existente. Si E5 pierde la respuesta puede consultar la misma referencia y comparar todos los datos antes de registrar la multa como pagada.
- `CANCELLED`. La referencia queda cerrada, incluso si E5 cancela antes de preparar. La cancelación y el cargo se resuelven sobre la misma fila de SQL Server. Si gana el cargo, la cancelación devuelve `CONFIRMED` con el comprobante. Una cancelación previa a preparar tiene `student_id`, `amount_cents` y `confirmation_path` nulos.

## Condiciones de activación

`FINANCIAL_LIBRARY_FINES_ENABLED` es falso por defecto. Para habilitarlo se requiere aprobar el cliente E5 y sus tres alcances, así como la cuenta receptora mediante `FINANCIAL_LIBRARY_FINES_CLIENT_ID`, `FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID` y `FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID`.

E5 habilita el enlace con `SERVICIOS_FINANZAS_MULTAS_ENABLED`, falso por defecto. Su cliente usa `SERVICIOS_FINANZAS_API_URL`, `SERVICIOS_FINANZAS_MULTAS_CLIENT_ID` y `SERVICIOS_FINANZAS_MULTAS_CLIENT_SECRET`. El ID del cliente coincide con el configurado en E2. Las credenciales se mantienen en la configuración privada del entorno.

En **Biblioteca → Multas**, el titular puede usar **Pagar multa**. E5 toma el importe de su registro y guarda el inicio del intento en MongoDB antes de llamar a E2. Después de pagar, el comprobante enlaza de regreso a **Mis multas**. **Consultar pago** recupera el resultado aunque se haya cerrado la pestaña. Biblioteca quita el adeudo cuando Finanzas acredita el pago y coinciden la referencia, el titular, el importe, la moneda y el concepto. Si existen otras multas pendientes, sus bloqueos siguen vigentes.

Una multa sin intento se cierra localmente. Cuando existe un intento, E5 pide a E2 que lo cancele antes de condonar o cancelar. Si Finanzas devuelve `CONFIRMED`, E5 registra el comprobante y conserva el estado pagado. Si Finanzas devuelve `CANCELLED`, E5 puede cerrar la multa localmente. Reintentar el cierre tras un fallo de red o de escritura reutiliza la misma referencia. Si se deshabilita la conexión con intentos pendientes, estos conservan la marca de intento y requieren restablecer la consulta financiera para cerrarse.

El piloto requiere aprobar la cuenta de Servicios al Estudiante y los alcances del cliente antes de habilitar ambos módulos. Las devoluciones se gestionan por separado.

## Verificación en entorno desechable

Se inició sesión, se abrió la solicitud desde Biblioteca, se confirmó la contraseña y se autorizó una multa de $15.00 MXN. Se salió del flujo antes de abrir el comprobante. De regreso a Biblioteca, la consulta por ID registró la referencia y levantó el bloqueo. Repetir la consulta conservó la misma referencia. El débito y el crédito sumaron cero y se pudo abrir el comprobante de la cuenta titular.
