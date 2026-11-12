# Respuesta preparada para E7 — auditoría y devoluciones

Referencia: `MERGE_COMPLETO`, integración E5 `0963365` del 11 de noviembre de 2026. Texto para que Daniel lo comparta; no enviado automáticamente. La auditoría existente y los eventos analíticos tienen banderas independientes. Los puntos siguientes distinguen confirmaciones de propuestas sin activar.

## Mensaje sobre auditoría E5 → E7

Hola, Equipo 7. Confirmamos que reutilizaremos `POST /api/v1/plataforma/auditoria`, HMAC-SHA256 y el dominio firmado `services`. El piloto existente ya comprueba los acuses 202 aceptado y 200 duplicado con el mismo event_id. La respuesta 409 no acredita entrega: no cambiaremos el contenido para un ID ya utilizado.

Hay una diferencia de nombres que debemos cerrar antes de activar:

| Operación | Acción que actualmente conserva/envía el piloto E5 | Nombre que proponen |
|---|---|---|
| Condonación | `library.fine.waived` | `services.fine.waived` |
| Cancelación de multa | `library.fine.cancelled` | `services.fine.cancelled` |
| Daño cobrado | `rental.deposit.charged` | `services.damage.charged` |

El sobre actual lleva IDs, acción, correlación y fecha; omite snapshots/reasons privados. No debemos afirmar que ya envía before/after de estados. ¿Conservamos las acciones actuales con el dominio services acreditado por firma, o publican una compatibilidad de nombres para los consumidores? Si se acuerda otra forma para envíos nuevos, habrá que congelarla en su outbox y conservar literalmente los reintentos anteriores; no renombrar retroactivamente un mismo event_id.

Proponemos como fecha de inicio el instante UTC que se registre al autorizar y ejecutar la activación, sin seleccionar historial anterior. La configuración actual aún no tiene corte ni secreto de Servicios. Daniel puede coordinar la verificación del integrado; E5 y E7 deben confirmar sus responsables para acreditar también su recepción. Coordinaremos el secreto por el canal seguro que acuerden, no en el repositorio ni en el chat. No se ha activado por enviar este mensaje.

Los ajustes E5 de cancelación segura y bandera propia de analítica se integraron. La revisión tuvo 161 Feature sin fallos, 25 comprobaciones de dominio con SQL Server real y Vite aprobado, con los límites documentados en `REVISION_PR8_E5_2026-11-11.md`.

## Mensaje sobre recompensas, devoluciones y pago mixto

Gracias por separar lo implementado de la propuesta. Conservamos como existente la acreditación de compras y el reverso total sobre el movimiento original; no declaramos operativas devoluciones parciales de puntos ni canje de puntos dentro de un pago mixto.

Para el contrato de devolución proponemos:

1. E3 conserva el `order_id` original. E2 aporta el UUID canónico del comprobante/solicitud de devolución del flujo aplicable (`PurchaseRefundRequest.public_id` para la devolución de una compra mixta; no inventar otro ID ni confundirlo con la transacción original). Debe acordarse el campo externo único `refund_id` o `return_id` antes de emitir eventos.
2. Solo un reembolso **financieramente completado** puede originar un reverso de puntos. Su aprobación/rechazo es auditoría. Una cancelación de un pedido sin puntos acreditados no genera por sí misma un reverso.
3. Debemos distinguir el importe total de la devolución de su parte monetaria elegible. Una devolución compuesta por wallet/bono/puntos no puede utilizar su total para retirar puntos calculados únicamente sobre dinero. E2 conserva sus desgloses contables; E7 calcula las recompensas según el contrato acordado.
4. Validamos como dirección de la propuesta acumular únicamente sobre dinero efectivamente liquidado, excluyendo bonos y puntos. Enumerar wallet/SPEI/Openpay en el contrato no activa esas pasarelas ni demuestra un cobro: cada componente requiere su evidencia real.
5. Para reversos parciales proponemos cálculo acumulado sobre los puntos originales: `objetivo = floor(puntos_originales * dinero_elegible_devuelto_acumulado / dinero_elegible_original)`, y descontar solo `objetivo - puntos_ya_revertidos`. La última devolución monetaria completa deja revertidos todos los puntos originales. Ejemplo: 10 puntos y devoluciones de 33%, 33% y 34% producen 3, 3 y 4 puntos. E7 debe confirmar esta regla y el tratamiento del déficit si esos puntos ya fueron usados.
6. Un mismo refund_id y contenido recupera el mismo resultado; el mismo ID con otro contenido es conflicto. Varias devoluciones del mismo pedido no pueden superar el importe o los puntos originales. La validación del acumulado exige evidencia de E2, no solo el número declarado por un consumidor.
7. El canje de puntos dentro de pago mixto requiere un acuerdo aparte de reserva, confirmación, cancelación, vencimiento y recuperación con E2/E3/E4/E7. No se debe tratar una reserva de stock o puntos como pago completado. Por ahora no activaremos ese camino con el contrato de reverso total.

Confirmen con E3 los IDs y la base monetaria, y con E7 la regla de reverso/rounding y la implementación de parciales. Después podremos versionar el evento y preparar el piloto. No se proponen aquí nuevas rutas, scopes, secretos ni cambios al sobre común.
