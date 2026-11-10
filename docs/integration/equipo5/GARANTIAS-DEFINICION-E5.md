# Garantías de renta: definiciones del Equipo 5 para el piloto E5–E2

Responde a lo que [PILOTO_GARANTIAS_RENTA_E5_E2.md](../PILOTO_GARANTIAS_RENTA_E5_E2.md) deja pendiente
"con E5": el consentimiento y la vigencia de la garantía. No cambia el código del piloto; fija las reglas
para que E2 y E5 lo conecten igual.

## 1. Consentimiento

- La garantía se retiene **solo con la autorización del alumno**, con el mismo flujo que las multas y los
  pagos de lockers e impresiones: sesión de E1 vigente, contraseña confirmada en los últimos cinco minutos y
  consentimiento explícito ligado al importe y a la vigencia.
- El texto que ve el alumno: "Autorizo retener $X de mi wallet como garantía hasta el {fecha}. Se libera al
  devolver el equipo; solo se cobra un daño registrado en el mostrador."
- Fuera del laboratorio, E5 **no** crea retenciones servidor a servidor. Propuesta técnica: la pantalla de
  Finanzas crea la retención con la misma clave del piloto (`rental:{rentalId}:deposit-hold`), el mismo
  actor y el mismo motivo; E5 solo la consulta con el `GET` del piloto. Así el `POST` del piloto queda
  únicamente como repetición idempotente. El Equipo 5 tiene una implementación de referencia de esta
  pantalla (respaldo `pagos-finanzas-con-garantias`) por si sirve.

## 2. Vigencia

- La retención vence al **final del día de devolución más 7 días** de margen (propuesta: hacerlo
  configurable con `SERVICIOS_FINANZAS_GARANTIA_DIAS_EXTRA`). Sustituye el TTL fijo del laboratorio.
- La política `RENTAL_DEPOSIT` de E2 debe permitir al menos la renta más larga que ofrece Servicios más el
  margen. Propuesta: **30 días** de duración máxima, y que Servicios limite las rentas a 23 días al
  conectar el piloto.
- Si la garantía vence antes de la devolución, E2 la libera sola y ya **no** se puede cobrar un daño desde
  ella. El mostrador lo registra y el cargo se trata aparte (no forma parte del piloto).
- La vigencia no se extiende al renovar: una renta renovada pide una garantía nueva.

## 3. Momentos de la renta

| Momento | Regla |
| --- | --- |
| Alta de la renta | Garantía pendiente; el equipo queda apartado. |
| Antes de recoger | El alumno autoriza en Finanzas. El mostrador no entrega mientras la garantía no esté retenida (ya lo hace el piloto). |
| Cancelación antes de recoger | Se cancela la solicitud en Finanzas; si ya estaba retenida, se libera completa. |
| Devolución en buen estado | Liberación completa; basta la autorización del mostrador (personal de Servicios). |
| Daño o pérdida | Cargo positivo: rol `operador_servicios` con permiso `student_services.rentals.damage.charge` y 2FA reciente (ampliación de E1 ya integrada en el piloto). Daño ≤ garantía; pérdida = garantía completa. |

## 4. Alcance y cuenta

- Alcance del operador: **por servicio** (renta de equipo, 5.7), válido para todas las sedes.
- Cuenta receptora: la cuenta institucional única de Servicios al Estudiante (`services`), la misma de
  multas, lockers e impresiones.
