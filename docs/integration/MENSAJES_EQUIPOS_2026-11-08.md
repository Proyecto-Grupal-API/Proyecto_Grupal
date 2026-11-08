# Mensajes para coordinar la integración de Campus Digital

Borradores del Equipo 6 para copiar por separado. Los resultados corresponden a pruebas con datos desechables en el merge local. Los cambios de checkout y multas están en los commits locales `251e48e` y `9d34ba0`.

## E1 · Identidad

Hola, equipo 1. Ya probamos dos flujos de autorización de un cargo propio, la compra de Marketplace y el pago de multas de Biblioteca. El alumno inicia sesión, confirma su contraseña y acepta el cargo específico dentro de Finanzas.

¿Les parece suficiente la sesión vigente y la confirmación reciente de contraseña para el piloto de esos dos cargos, o requieren algún paso adicional?

También necesitamos su propuesta para acreditar dos autoridades diferentes: el operador de E5 que ordena un cargo por daños sobre una garantía, y quien aprueba una devolución financiera. ¿Qué rol o capacidad, ámbito y evidencia puede verificar Finanzas en cada caso? E5 registra el daño y el importe. Finanzas ejecuta y registra el movimiento.

## E2 · Finanzas

Hola, equipo 2. Probamos de punta a punta la compra por $178.00 de dos tazas y el pago de una multa de Biblioteca por $15.00. En la compra hubo un solo cargo, un abono y un movimiento de venta. En Biblioteca recuperamos el comprobante por API después de salir de la pantalla de pago y se levantó el bloqueo de la multa. Los cobros están desactivados en el alterno.

Para preparar el piloto necesitamos acordar con ustedes:

- **Marketplace.** El cliente de E3, la wallet receptora autorizada de `BUS-CD-SOUV-001` y quién solicita, aprueba y ejecuta una devolución si el cargo se completó y la reserva se perdió.
- **Biblioteca.** El cliente de E5 y el identificador de la cuenta institucional de Servicios al Estudiante. Para garantías de renta, también necesitamos cerrar el contrato HTTP y quién acredita al operador que ordena el cargo por daños.
- **Comunidad.** El mecanismo para registrar y verificar el receptor financiero de cada organización, incluido el Consejo Estudiantil. Para becas, ¿qué referencia de financiamiento o presupuesto esperan y quién puede autorizar su uso?

¿Nos pueden confirmar un responsable técnico y qué puntos ya podemos dar por acordados? Los identificadores se pueden compartir en el grupo. Las credenciales se coordinan por privado.

## E3 · Marketplace

Hola, equipo 3. Ya conectamos Mis pedidos con el checkout de wallet de Finanzas en el merge local. Probamos dos tazas por $178.00 con `BUS-CD-SOUV-001`. Repetir la confirmación devolvió el mismo comprobante y E4 registró un solo `SALE`. Un segundo pedido se canceló sin cargo.

¿Nos confirman quién puede validar el piloto con nosotros y si `BUS-CD-SOUV-001` sigue siendo el negocio para la demostración? También queremos acordar qué verá el comprador y quién tomará el caso cuando Finanzas confirme un cargo y la reserva ya no esté disponible. Ese caso queda registrado para revisión de devolución.

## E4 · Inventarios

Hola, equipo 4. Gracias por los datos de la taza. Probamos `SOUV-TAZA-001` en `A1-01` con los IDs de MongoDB. Finanzas envió el `PAID` firmado para el mismo pedido, confirmar dos veces dejó un solo `SALE` y el inventario pasó de 80 a 78. La segunda reserva se canceló y liberó correctamente.

¿Pueden confirmar la URL del entorno compartido que recibirá `/api/equipo4/webhooks/payment-status` y quién de su equipo puede revisar la recepción y el `SALE` cuando hagamos el piloto? Para auditorías hacia E7, también necesitamos confirmar qué eventos quieren validar primero.

## E5 · Servicios al Estudiante

Hola, equipo 5. Ya conectamos Biblioteca con Finanzas en el merge local. El alumno puede abrir el cobro desde su multa y volver a consultar el pago. Con una multa de prueba de $15.00 recuperamos el comprobante y se levantó el bloqueo. Si el pago se confirma al mismo tiempo que una cancelación o condonación, Biblioteca conserva el estado pagado.

¿Nos confirman si mantenemos la propuesta de una sola cuenta institucional de Servicios al Estudiante y quién la validará con E2? Para garantías, necesitamos confirmar quién registra el daño, quién puede ordenar el cargo y qué alcance institucional tiene ese operador. Podemos usar su caso de $500.00 de garantía, $150.00 de daño y $350.00 de liberación para la siguiente prueba.

## E7 · Plataforma y Auditoría

Hola, equipo 7. Queremos coordinar la recepción de auditorías de Servicios al Estudiante y una prueba separada de eventos de Inventarios. En el merge local, la auditoría se recibe por `POST /api/v1/plataforma/auditoria`, con firma HMAC, origen autenticado y deduplicación por `event_id`. Para E5 proponemos comenzar con condonación y cancelación de multas y con cargos por daños sobre garantías. Compartiríamos únicamente IDs, acción, tipo de entidad y fecha, conservando los detalles sensibles en E5.

¿Nos confirman si ese contrato y el dominio `services` sirven para su entorno compartido? También queremos acordar cuáles acciones de E5 les interesa recibir primero, si el piloto debe incluir registros históricos y quién puede revisar la deduplicación de una auditoría repetida. Con E4 coordinaremos por separado el catálogo de eventos de Inventarios.
