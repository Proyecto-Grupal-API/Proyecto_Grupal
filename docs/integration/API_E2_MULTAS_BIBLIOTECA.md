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

El adaptador E5 debe usar los datos de la multa pendiente y conciliar por referencia. Antes de cancelar o condonar una multa, E5 debe obtener `CANCELLED` de E2 y después actualizar su registro en MongoDB. Si E2 devuelve `CONFIRMED`, E5 debe recuperar el pago y tratar cualquier devolución por separado. E5 debe poder reanudar la transición si se interrumpe después de cancelar en E2. Quedan pendientes el enlace desde la pantalla de E5, este manejo de cancelación y condonación, la conciliación en E5 y la aprobación de la cuenta institucional. El piloto usa wallet en MXN y el mecanismo de confirmación de contraseña de E1.
