# Equipo 5 → Equipo 7: emisor de eventos

Implementa los **eventos analíticos** de **REQ-M5-E7-001** sobre `MERGE_COMPLETO`
(`POST /api/v1/plataforma/eventos`). La **auditoría** del Equipo 5 la publica el
piloto `servicios:publicar-auditoria` (`PlatformAuditDelivery`); este emisor no la
toca, para no mandar dos versiones del mismo `event_id`.

## Cómo funciona

1. **Se registra el hecho, no se envía.** Un observador de modelos
   (`ServicePlatformObserver`) convierte altas y cambios de estado en eventos y
   los guarda en `service_platform_events`. Si guardar el evento falla, se
   reporta y la operación del alumno sigue: **nunca depende del Equipo 7**.
2. **El scheduler envía.** `servicios:publicar-eventos` corre cada minuto solo
   con `PLATFORM_SERVICES_ANALYTICS_ENABLED=true` y manda hasta 100 eventos por
   pasada. Es una bandera propia, apagada por defecto: encender la auditoría
   (`PLATFORM_SERVICES_DELIVERY_ENABLED`) no inicia el envío de eventos.
3. **Reintentos con la misma identidad.** El sobre se guarda completo; un
   reintento manda exactamente el mismo cuerpo con el mismo `event_id`.

| Respuesta del receptor | Qué hace el Equipo 5 |
|---|---|
| 202 aceptado / 200 duplicado, con el mismo `event_id` | Entregado (`sent`) |
| 422, 409, 413 | Rechazado (`rejected`); no se reintenta, hay que revisar el contenido |
| 401, 403, 429, 5xx, sin conexión, acuse con otro `event_id` | Reintento: 1 min, 2, 4… hasta 1 h |

## Transporte y configuración

Mismo transporte y configuración que el piloto de auditoría: firma HMAC-SHA256 por dominio.

```
X-Platform-Domain: services
X-Platform-Timestamp: <unix, tolerancia 5 min>
X-Platform-Signature: hex(hmac_sha256("<timestamp>|POST|<ruta>|<sha256(cuerpo)>", PLATFORM_SERVICES_SECRET))
```

Variables (`.env`, nunca en el repositorio ni en el chat):

| Variable | Valor |
|---|---|
| `PLATFORM_SERVICES_SECRET` | Secreto compartido con el receptor, mínimo 20 caracteres |
| `PLATFORM_SERVICES_DELIVERY_ENABLED` | `true` para que el scheduler envíe la auditoría del piloto |
| `PLATFORM_SERVICES_ANALYTICS_ENABLED` | `true` para que el scheduler envíe los eventos analíticos (apagada por defecto) |
| `PLATFORM_SERVICES_API_URL` | URL base del receptor (`http://app` dentro del contenedor; `https://…` fuera) |
| `PLATFORM_INGEST_ENABLED` | `true` en la app que recibe (receptor del Equipo 7) |

Comandos:

```bash
php artisan migrate --force                       # índices de la salida
php artisan servicios:publicar-eventos             # envío manual (lo mismo que hace el scheduler)
```

## Catálogo de eventos (`source_domain = services`)

Todos son `services.<entidad>.<accion>.v1`. El payload lleva solo ids y estados
(`student_id` es el `User._id`); nombres, correos, documentos y similares se eliminan
antes de guardar.

| Evento | Cuándo | Payload principal |
|---|---|---|
| `services.loan.created.v1` | Préstamo registrado | student_id, loan_id, copy_id, due_at |
| `services.loan.renewed.v1` | Renovación | loan_id, due_at, renewal_count |
| `services.loan.overdue.v1` | Préstamo vencido | loan_id, due_at |
| `services.loan.returned.v1` | Devolución | loan_id, returned_at, on_time |
| `services.loan.returned_on_time.v1` | **P3**: devuelto a tiempo | student_id, loan_id |
| `services.fine.created.v1` | Multa generada | fine_id, loan_id, type, amount_cents |
| `services.fine.paid.v1` / `.waived.v1` / `.cancelled.v1` | Cambio de estado de la multa | fine_id, amount_cents |
| `services.reservation.created.v1` | Reserva de instalación o zona de descanso | reservation_id, resource_type (`facility`/`rest_space`), resource_id, start_at, end_at, status |
| `services.reservation.promoted.v1` | Sale de lista de espera | ídem |
| `services.reservation.attended.v1` | **P3**: check-in (se presentó) | ídem |
| `services.reservation.no_show.v1` | No se presentó | ídem |
| `services.reservation.completed.v1` / `.cancelled.v1` | Cierre | ídem |
| `services.rental.created.v1` | Renta de equipo | rental_id, asset_id, due_at, deposit_cents |
| `services.rental.picked_up.v1` | Entrega en mostrador | rental_id, asset_id |
| `services.rental.returned.v1` | Devolución | rental_id, condition, on_time, damage_charge_cents |
| `services.rental.returned_ok.v1` | **P3**: a tiempo y en buen estado | student_id, rental_id |
| `services.rental.cancelled.v1` / `.overdue.v1` | Cancelación / vencimiento | rental_id, asset_id |
| `services.order.created.v1` | Orden de impresión u otro servicio | order_id, service_type, quantity |
| `services.order.paid.v1` / `.ready.v1` / `.delivered.v1` / `.cancelled.v1` | Avance de la orden | order_id, payment_status |
| `services.ticket.created.v1` | Ticket de soporte | ticket_id, category, priority |
| `services.ticket.status_changed.v1` | Cambio de estado | ticket_id, from, to |
| `services.ticket.sla_escalated.v1` | SLA vencido y escalado (`servicios:revisar-sla`) | ticket_id, from_priority, to_priority |
| `services.locker.assigned.v1` | Locker asignado (pagado, becado o renovado) | assignment_id, locker_id, period_id, source, renewed_from_id |
| `services.locker.released.v1` | Locker liberado | assignment_id, locker_id, period_id |
| `services.benefit.assigned.v1` / `.cancelled.v1` | Beca de locker o impresiones (API de E6) | benefit_assignment_id, benefit, quantity / released_quantity |
| `services.access.validated.v1` / `.denied.v1` | Validación en mostrador (5.11) | checkin_id, service, operation, method (`student_id=unidentified` si la credencial no se reconoció) |

Los eventos **P3** (puntos) se envían solo cuando la condición ya se cumplió;
el Equipo 7 decide cuántos puntos dan. Por ahora son solo analítica.


## Límites conocidos

- Las actualizaciones masivas de estado no pasan por el observador. Se cubren
  de forma explícita la liberación de lockers y la cancelación de saldo de
  impresiones; la expiración masiva de listas de espera no genera evento.
- El evento se guarda después de la operación, no en la misma transacción: si
  el proceso muere justo entre ambos, ese evento se pierde (la auditoría no).
- Los rechazos (`rejected`) quedan para revisión manual en `service_platform_events`
  (`last_error`).

## Pruebas

`tests/Feature/StudentServices/ServicePlatformEmitterTest.php` cubre los eventos
de cada servicio, la privacidad, que una falla de la salida no rompe la
operación, firma, reintentos y rechazos, y una prueba de punta a punta contra
el **receptor real** de esta rama (aceptado, duplicado tras respuesta perdida
y atribución a `services`).
