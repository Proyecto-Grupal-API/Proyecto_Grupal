# Mensajes de coordinación de Campus Digital

Borradores para copiar por separado. Hasta el miércoles estamos trabajando en Finanzas (E2) y Comunidad (E6). Las decisiones técnicas de ambos módulos las coordinamos directamente. Los importes y existencias mencionados corresponden a pruebas con datos desechables. Los cobros siguen desactivados en el alterno.

## E1 · Identidad

Hola, equipo 1. Hasta el miércoles estamos trabajando tanto en Finanzas como en Comunidad. Ya probamos en el merge local que el alumno puede autorizar su propio cargo de Marketplace o Biblioteca mediante una sesión vigente, confirmación reciente de contraseña y aceptación del importe en Finanzas. Planeamos reutilizar ese flujo para el pago de eventos de Comunidad.

¿Nos confirman si Identidad requiere una verificación adicional para esos cargos propios en el piloto? Para las operaciones administrativas, necesitamos saber qué capacidad y ámbito puede acreditar E1 para quien ordena un cargo por daños desde E5 y para quien aprueba una devolución. Con eso podemos definir y aplicar las reglas financieras en E2.

## E3 · Marketplace

Hola, equipo 3. Ya quedó conectado el checkout de wallet en el merge local. Probamos con `BUS-CD-SOUV-001`: dos tazas por $178.00, un solo comprobante y un solo movimiento `SALE` aunque se repitió la confirmación. También probamos que cancelar un pedido pendiente libera la reserva sin cambiar el saldo.

¿Confirman que ese negocio sigue siendo el de la demostración y quién de E3 puede revisar el flujo con nosotros? Para un cargo confirmado cuya reserva se perdió, ¿quién recibe el caso en Marketplace y qué mensaje quieren mostrarle al comprador? Nosotros nos encargamos de preparar en Finanzas el cliente del piloto y el vínculo con la wallet del negocio.

## E4 · Inventarios

Hola, equipo 4. Usamos `SOUV-TAZA-001` en `A1-01`, con los IDs de MongoDB del producto y la ubicación. El `PAID` firmado correspondió al mismo pedido. Confirmar dos veces dejó un solo `SALE` y el inventario pasó de 80 a 78. En otra reserva probamos la liberación tras cancelar el cobro.

Para repetirlo en el entorno compartido, ¿nos confirman la URL base que recibirá `POST /api/equipo4/webhooks/payment-status` y con quién verificamos el acuse y el movimiento de stock? Podemos preparar el emisor del lado de Finanzas. Para E7, ya probamos por HTTP la entrega de un evento de vencimiento con deduplicación. ¿Qué otros eventos de E4 quieren incluir en el piloto?

## E5 · Servicios al Estudiante

Hola, equipo 5. La multa de Biblioteca de $15.00 funcionó en la prueba del merge local. El alumno autorizó el cargo, volvió a Biblioteca y pudo recuperar el comprobante para levantar el bloqueo. Si la cancelación o condonación coincide con un cargo confirmado, Biblioteca conserva el pago.

¿Confirman el nombre y el identificador que usan para **Servicios al Estudiante**, y si una sola cuenta institucional debe recibir las multas y los cargos por daños? Con ese dato podemos preparar el vínculo financiero. Para las garantías, confirmen qué operador registra el daño, quién puede ordenar el cargo y si su alcance es por sede o por servicio. Retomaremos el caso de $500.00 retenidos, $150.00 de cargo y $350.00 liberados para validar el flujo.

## E7 · Plataforma y Auditoría

Hola, equipo 7. En el merge local probamos por HTTP la recepción de una auditoría de E5 y un evento de vencimiento de E4. Repetir ambos envíos dejó un solo registro por evento. Para la auditoría de E5, el piloto propone condonaciones, cancelaciones de multas y cargos por daños. El sobre comparte IDs, acción, entidad y fecha. Los detalles sensibles se conservan en E5.

¿Confirman si el entorno compartido usará `POST /api/v1/plataforma/auditoria` con firma HMAC y el dominio `services`? También necesitamos acordar qué acciones recibir primero, desde qué fecha y quién valida los acuses con nosotros. Con E4 coordinaremos aparte el catálogo de eventos de Inventarios.

## E2 · Traspaso del miércoles

Hola, equipo de Finanzas. Como acordamos, hasta el miércoles estamos avanzando en E2 y E6. En el merge local ya quedaron probados el checkout de Marketplace y la recuperación de pagos de multas de Biblioteca. Les dejaremos las referencias de commits, los contratos, las pruebas y los pasos de configuración de lo que alcancemos a cerrar.

La prioridad conjunta E2–E6 es preparar el cobro de eventos con reserva de 15 minutos, autorización del alumno y recuperación por referencia, además de definir el vínculo entre organizaciones y cuentas receptoras. Para becas documentaremos el presupuesto o fuente de fondos y la autoridad institucional que debe acreditarlo. En el traspaso distinguiremos lo probado en laboratorio, lo habilitado y lo pendiente.
