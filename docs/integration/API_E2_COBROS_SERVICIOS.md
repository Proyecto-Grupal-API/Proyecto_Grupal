# Pago de lockers e impresiones con la wallet

Complementa [API_E2_MULTAS_BIBLIOTECA.md](API_E2_MULTAS_BIBLIOTECA.md) con el mismo esquema: E5 administra
su registro en MongoDB, E2 administra la solicitud de pago, el saldo y el comprobante en SQL Server y el
alumno autoriza cada cobro en la aplicación web integrada. E5 nunca mueve dinero por su cuenta. Las
garantías de renta van por su propio piloto ([PILOTO_GARANTIAS_RENTA_E5_E2.md](PILOTO_GARANTIAS_RENTA_E5_E2.md)).

| Concepto | Registro de E5 | Referencia contable |
| --- | --- | --- |
| `locker_rental` | Solicitud de locker pagada (5.4) | `LOCKER_RENTAL` |
| `print_order` | Orden de impresión (5.8) | `PRINT_ORDER` |

## API para el cliente OAuth de E5

Rutas bajo `/api/v1/financial/student-services`. `{source}` es el ID MongoDB del registro de E5.

| Método y ruta | Alcance OAuth |
| --- | --- |
| `POST /payments/{concept}/{source}` | `financial:student-service:prepare` |
| `GET /payments/{concept}/{source}` | `financial:student-service:read` |
| `POST /payments/{concept}/{source}/cancel` | `financial:student-service:cancel` |

```json
{"student_id":"507f1f77bcf86cd799439012","amount_cents":15050,"currency":"MXN"}
```

Concepto + origen es la clave natural: 201 la primera vez, 200 al repetir los mismos datos y 409 si cambia
el alumno o el importe, o si ya se canceló. Un concepto distinto da 404. La respuesta `data` trae
`source_reference`, `concept`, `student_id`, `amount_cents`, `currency`, `status`, `payment_id` y
`confirmation_path`.

- `PENDING`: E5 lleva al alumno a `confirmation_path` (`/finanzas/servicios-estudiante/solicitudes/{uuid}`).
  E2 exige cuenta titular, sesión de E1 vigente, contraseña confirmada en los últimos cinco minutos y
  consentimiento explícito ligado al detalle del cobro. Sin saldo o sobre un límite, sigue pendiente.
- `CONFIRMED`: E2 debitó al alumno y acreditó a Servicios al Estudiante en la misma transacción.
  `payment_id` abre el comprobante, que enlaza de regreso a **Mis lockers** o **Mis solicitudes**.
- `CANCELLED`: la referencia queda cerrada, incluso si E5 cancela antes de preparar. Si gana el cobro, la
  cancelación devuelve `CONFIRMED`.

## Flujo en Servicios (E5)

- **Lockers → Solicitudes** e **Impresiones**: el titular usa **Pagar con mi wallet** y, al volver,
  **Consultar pago**. Con el pago confirmado y coincidiendo titular, importe, moneda y concepto, el locker se
  asigna solo y la orden pasa a proceso.
- E5 marca `finance_requested` en MongoDB antes de llamar a E2. Una solicitud sin intento se cancela
  localmente; con intento, E5 cancela primero en E2 y, si E2 ya había cobrado, registra el pago en lugar de
  cancelar.
- El registro manual de referencia (`/pagar`) sigue bloqueado: el único camino es la wallet.

## Activación

E2 (apagado por defecto):

```
FINANCIAL_STUDENT_SERVICES_ENABLED=true
FINANCIAL_STUDENT_SERVICES_CLIENT_ID=<cliente OAuth de E5>
# Opcional: sin definirlas, usa la misma cuenta receptora que las multas de Biblioteca.
FINANCIAL_STUDENT_SERVICES_RECIPIENT_ORGANIZATION_ID=
FINANCIAL_STUDENT_SERVICES_RECIPIENT_WALLET_ID=
```

El cliente de E5 necesita los tres alcances `financial:student-service:prepare|read|cancel`.

E5 (apagado por defecto):

```
SERVICIOS_FINANZAS_SERVICIOS_ENABLED=true
SERVICIOS_FINANZAS_API_URL=http://app
# Sin definirlas, usa el mismo cliente que las multas.
SERVICIOS_FINANZAS_SERVICIOS_CLIENT_ID=
SERVICIOS_FINANZAS_SERVICIOS_CLIENT_SECRET=
```

Las credenciales van solo en la configuración privada del entorno. Correr `php artisan migrate
--database=sqlsrv --path=database/migrations/financial` para crear `student_service_payment_requests`.

## Pruebas

- E2: `tests/Feature/FinancialModule/StudentServiceCheckoutTest.php` (pago de locker de punta a punta,
  permisos, origen inmutable y cancelación).
- E5: `tests/Feature/StudentServices/ServicesFinancePaymentsTest.php` (locker asignado al confirmar,
  dueño únicamente, pago ganado contra cancelación, cancelación sin intento, orden de impresión).

Las pruebas de E2 están escritas para SQL Server como las demás de Finanzas; en el equipo del Equipo 5 se
ejecutaron con la conexión `sqlsrv` apuntando a SQLite. Antes de habilitar en el alterno, correrlas contra
`campus_digital_financial_testing`.
