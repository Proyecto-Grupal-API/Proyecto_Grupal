# Piloto de garantías de renta entre E5 y E2

La API de laboratorio exige `APP_ENV=testing`, una base SQL Server llamada
`campus_digital_financial_testing`, `FINANCIAL_RENTAL_DEPOSIT_PILOT_ENABLED=true`
y un cliente OAuth de prueba registrado en `FINANCIAL_RENTAL_DEPOSIT_PILOT_CLIENT_ID`.
Se requiere un entorno aislado con datos desechables. La cuenta receptora se
configura con las variables de multas de Biblioteca y debe ser una wallet
institucional activa en MXN cuyo titular sea `services`.

Las rutas parten de `/api/v1/financial/student-services/rentals/{rentalId}/deposit`.
Los importes son enteros en centavos y la renta y el alumno usan IDs de MongoDB.

| Operación | Ruta | Scope OAuth | Idempotency-Key |
| --- | --- | --- | --- |
| Retener | POST a la ruta base | `financial:rental-deposit:hold` | `rental:{rentalId}:deposit-hold` |
| Liquidar | POST a la ruta base más `/{holdId}/settle` | `financial:rental-deposit:settle` | `rental:{rentalId}:deposit-settle` |

Retener recibe `student_id`, `amount_cents` y `expires_at` en UTC con
precisión de segundos. E2 exige una política activa de `RENTAL_DEPOSIT`.
Repetir conserva el mismo vencimiento y devuelve la misma referencia.
Liquidar recibe `condition` y `charged_cents`. Buen estado (`good`) libera
todo, daños (`damaged`) cobran un importe positivo hasta el total y pérdida
(`lost`) cobra toda la garantía. La respuesta de liquidación conserva
`hold_id`, `transaction_id`, `charged_cents` y `released_cents`.

El cliente HTTP de E5 puede usar `SERVICIOS_FINANZAS_API_URL`,
`SERVICIOS_FINANZAS_GARANTIAS_CLIENT_ID` y
`SERVICIOS_FINANZAS_GARANTIAS_CLIENT_SECRET` en el laboratorio. Sus reintentos
deben repetir los mismos argumentos. El contrato rechaza la liquidación de una
retención asociada a otra renta o cliente. La cuenta receptora se resuelve del
lado de Finanzas.

Antes de conectar la pantalla de rentas, quedan pendientes la verificación
del operador vigente de E5 por E1, el consentimiento para la retención, la
duración autorizada. El flujo de liquidación del mostrador sigue usando
el gateway anterior hasta completar esos acuerdos.

## Recuperación de una respuesta perdida

`GET` a la ruta base consulta la retención de esa renta con el alcance
`financial:rental-deposit:hold`. Devuelve la misma referencia, titular,
importe, vencimiento, estado y el comprobante de cierre cuando existe.
Un 404 conserva el estado pendiente, ya que no confirma una liberación.

El alta de renta puede ejercitar el piloto con
`SERVICIOS_FINANZAS_GARANTIAS_PILOT_TTL_SECONDS` mayor que cero, únicamente
en `APP_ENV=testing` con MongoDB en replica set. El importe y el vencimiento
se guardan junto con la renta antes de solicitar la retención. La pantalla
permite recuperarla y mantiene bloqueadas la entrega y cancelación mientras
Finanzas no confirme el estado. Después del vencimiento, la recuperación
consulta la evidencia sin solicitar otra retención. Una liberación o
expiración confirmada cancela la renta pendiente y devuelve el equipo al
catálogo. El mostrador sigue pendiente de su integración de autorización.

## Confirmación funcional para E1

La acción humana de E5 es registrar la devolución y ordenar el cargo en el
mostrador (5.11) para una renta de equipo (5.7). Su alcance acordado con E5
abarca todas las sedes. El backend de E5 enviará la solicitud de liquidación
con la renta, la retención, la condición y el importe. La credencial OAuth
identifica al servicio E5. Para habilitar cargos reales queda acordar con E1
cómo se acredita al operador humano y se verifica la capacidad vigente en
esa operación.

Para revisión de devoluciones, E2 propone usar el UUID de la wallet
receptora original (`Wallet.public_id`) como identificador de ámbito.
E2 es dueño de la relación wallet–titular (`owner_type`, `owner_id`), y
obtiene el UUID a partir del cobro original y su evidencia contable.
E1 conserva las asignaciones del revisor y verifica su vigencia.
Los cobros históricos sin receptor demostrable requieren revisión separada.
E2 verifica que el solicitante y el revisor sean distintos y separa la
capacidad de ejecución. La propuesta requiere acordar con E1 el nombre
del ámbito y el contrato técnico.

## Conexión pendiente con el mostrador

En 5.11 el operador autenticado registra condición e importe. La decisión
de E1 se debe comprobar antes de fijar el cierre en MongoDB. La auditoría
vinculará al operador, la renta, la retención, la condición y el importe.
El cargo positivo requiere 2FA reciente en la misma sesión. Una liberación
sin cargo requiere la autorización vigente del mostrador.

Las retenciones creadas por el piloto quedan bloqueadas antes de
guardar una decisión de cierre en el gateway anterior. La recuperación
de retenciones y las rentas con depósitos del gateway anterior conservan
sus flujos. Para conectar la liquidación al piloto quedan por definir el
consentimiento y la vigencia de la garantía con E5 y la comprobación
interna de permisos con E1.
