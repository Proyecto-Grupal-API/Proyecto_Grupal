# Lo que todavía falta (73 de 422 requisitos del diseño)

Medido sobre la candidata `6710e59`: 349 de los 422 requisitos del diseño de módulos (`Campus_Digital_Diseno_Modulos.docx`) tienen prueba técnica aprobada **y** paso de interfaz comprobado. Los 73 restantes, por tipo:

- **Desarrollo (37):** la función no existe o no tiene pantalla.
- **Decisión (29):** falta una regla o una autoridad que debe fijar el equipo dueño o la institución (sobre todo Finanzas: quién emite bonos, reversa, retiene o devuelve).
- **Terceros (7):** dependen de un proveedor externo (Openpay, correo real).

## Desarrollo

| ID | Equipo | Módulo | Requisito | Por qué falta |
|---|---|---|---|---|
| 2.6-b | E2 | Transferencias y regalos de saldo | solicitudes | No hay solicitudes de transferencia por interfaz |
| 2.6-c | E2 | Transferencias y regalos de saldo | regalos | No hay regalos de saldo por interfaz |
| 2.9-e | E2 | Tickets y comprobantes financieros | QR/verificación de comprobante | Sin control en pantalla: el comprobante no lleva QR ni código; la verificación existe sólo como parámetro de la dirección (`?folio=` o `?reference=`), con pruebas propias |
| 3.1-d | E3 | Registro y clasificación de negocios | responsables | Tienda no tiene gestión del personal del negocio: esas asignaciones las hace Identidad |
| 3.2-g | E3 | Flujo de autorización de negocio | reglas especiales para negocio de estudiante | No existen: el tipo «estudiante» es sólo una clasificación del negocio; ni el alta ni la operación le aplican reglas propias |
| 3.4-d | E3 | Catálogo de productos y servicios | variantes | Sin variantes en el alta de la tienda |
| 3.4-e | E3 | Catálogo de productos y servicios | imágenes | Sin imágenes de producto |
| 3.4-f | E3 | Catálogo de productos y servicios | atributos | Sin atributos adicionales |
| 3.4-h | E3 | Catálogo de productos y servicios | impuestos/configuración académica | Sin impuestos ni configuración académica |
| 3.6-c | E3 | Checkout y orquestación de pago | puntos | La tienda no cobra con puntos |
| 3.10-e | E3 | Postventa comercial | evidencia | Sin campo en pantalla: el formulario de devolución sólo pide el motivo; la evidencia se envía siempre como lista vacía |
| 4.3-g | E4 | Kardex y movimientos | mermas | Sin pantalla: la merma existe como tipo de movimiento y como filtro del kardex, pero ninguna pantalla ni controlador la registra; el ajuste de existencias no deja elegir  |
| 4.6-c | E4 | Recepción y devolución a proveedor | lotes opcionales | Sin pantalla: la recepción no tiene campo de lote |
| 4.6-d | E4 | Recepción y devolución a proveedor | evidencia | Sin pantalla: la recepción no admite adjuntar evidencia, sólo notas |
| 4.8-c | E4 | Reserva de stock para pedidos/canjes | recompensas para evitar sobreventa | La reserva para canjes llega por la API firmada de Recompensas y no se ejercita por interfaz: las recompensas de la demostración no llevan inventario |
| 4.9-d | E4 | Alertas de reabastecimiento | productos sin movimiento y notificaciones a responsable | Las alertas de existencia baja se generan y se descartan en pantalla; no hay alerta de productos sin movimiento ni notificación al responsable |
| 5.9-g | E5 | Tickets de soporte e incidencias | SLA | No hay ningún paso que mida o muestre el SLA del ticket |
| 6.5-c | E6 | Asignación de beneficios | comidas | No existe un beneficio de comidas operable por interfaz |
| 6.5-e | E6 | Asignación de beneficios | transporte | No existe un beneficio de transporte operable por interfaz |
| 6.7-a | E6 | Mensajería segmentada | Campañas para todos | El producto no tiene este segmento: las campañas se dirigen a integrantes, inscritos de un evento o solicitantes de una beca |
| 6.7-b | E6 | Mensajería segmentada | campus | El producto no tiene este segmento: las campañas se dirigen a integrantes, inscritos de un evento o solicitantes de una beca |
| 6.7-c | E6 | Mensajería segmentada | carrera | El producto no tiene este segmento: las campañas se dirigen a integrantes, inscritos de un evento o solicitantes de una beca |
| 6.7-d | E6 | Mensajería segmentada | semestre | El producto no tiene este segmento: las campañas se dirigen a integrantes, inscritos de un evento o solicitantes de una beca |
| 6.7-e | E6 | Mensajería segmentada | grupo | El producto no tiene este segmento: las campañas se dirigen a integrantes, inscritos de un evento o solicitantes de una beca |
| 7.1-f | E7 | Ledger global de puntos | expiraciones | Ningún proceso expira puntos: las políticas guardan el plazo de expiración pero nada lo aplica |
| 7.2-b | E7 | Adhesión de negocios al programa | aceptación de condiciones | El alta de un negocio no registra la aceptación de condiciones del programa |
| 7.3-b | E7 | Reglas de acumulación | producto | No hay reglas por producto: las reglas son por importe o de puntos fijos |
| 7.3-e | E7 | Reglas de acumulación | acción o categoría | No hay reglas por acción ni por categoría |
| 7.4-c | E7 | Catálogo de recompensas | imágenes | Las recompensas no admiten imagen: se muestran con un icono fijo |
| 7.4-f | E7 | Catálogo de recompensas | condiciones | La recompensa sólo tiene descripción libre; no hay condiciones estructuradas |
| 7.6-a | E7 | Gobierno de puntos | Expiración | La expiración de puntos y el periodo de gracia no se ejecutan |
| 7.7-c | E7 | Administración general | tipos de negocio | Los catálogos maestros son genéricos (código y elementos); no hay un catálogo de tipos de negocio en uso por el programa |
| 7.7-e | E7 | Administración general | estados | No hay un catálogo de estados administrable |
| 7.9-b | E7 | Dashboards y analítica | ventas | El tablero no muestra indicadores de ventas |
| 7.9-c | E7 | Dashboards y analítica | inventarios | El tablero no muestra indicadores de inventarios |
| 7.9-d | E7 | Dashboards y analítica | servicios | El tablero no muestra indicadores de servicios; sólo cuenta los eventos recibidos de Servicios en «Integraciones» |
| 7.9-h | E7 | Dashboards y analítica | uso del campus | No hay un indicador de uso del campus |

## Decisión

| ID | Equipo | Módulo | Requisito | Por qué falta |
|---|---|---|---|---|
| 1.6-c | E1 | Identidad QR | eventos | Los eventos de Comunidad validan con el QR del boleto, no con la identidad QR de Identidad |
| 2.1-b | E2 | Cuentas wallet | retenido | Ningún paso retiene saldo: las retenciones sólo existen en el piloto de garantías, limitado al entorno de pruebas |
| 2.2-e | E2 | Ledger de movimientos | reversos | Nadie puede reversar: Finanzas no ha publicado qué autoridad lo habilita (botón deshabilitado; responde 403) |
| 2.5-a | E2 | Bonos y saldos restringidos | Bonos por beca | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-b | E2 | Bonos y saldos restringidos | campaña o beneficio | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-c | E2 | Bonos y saldos restringidos | reglas de categoría | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-d | E2 | Bonos y saldos restringidos | negocio | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-e | E2 | Bonos y saldos restringidos | vigencia | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-f | E2 | Bonos y saldos restringidos | monto | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.5-g | E2 | Bonos y saldos restringidos | no retiro | La emisión de bonos está bloqueada: Finanzas no ha publicado qué autoridad la habilita y ninguna cuenta puede emitir; sin bonos emitidos no hay reglas ni consumo que reco |
| 2.7-a | E2 | Retenciones, devoluciones y reversos | Retener saldo durante operaciones | Las retenciones sólo existen en el piloto de garantías de renta, limitado al entorno de pruebas |
| 2.7-b | E2 | Retenciones, devoluciones y reversos | liberar | Nadie administra retenciones: Finanzas no ha publicado la autoridad (responde 403) |
| 2.7-c | E2 | Retenciones, devoluciones y reversos | reembolsar | Nadie aprueba devoluciones: Finanzas no ha publicado la autoridad (responde 403) |
| 2.7-d | E2 | Retenciones, devoluciones y reversos | revertir con referencia a transacción original | Nadie puede reversar: Finanzas no ha publicado qué autoridad lo habilita (botón deshabilitado; responde 403) |
| 3.6-b | E3 | Checkout y orquestación de pago | bono | El pago con bono depende de la emisión de bonos de Finanzas, bloqueada |
| 3.7-c | E3 | Configuración SPEI directa | validación manual o futura integración bancaria | Nadie puede confirmar a mano un pago SPEI; la integración bancaria depende de un tercero |
| 3.10-c | E3 | Postventa comercial | aprobación de reembolso | El reembolso real depende de la aprobación de devoluciones de Finanzas (2.7-c) |
| 4.10-d | E4 | Conteos y ajustes de inventario | autorización | El cierre del conteo aplica los ajustes directamente; no hay un paso de autorización separado |
| 5.5-h | E5 | Reservación de instalaciones | costo | El costo se muestra y no se cobra: el contrato de cobros entre Servicios y Finanzas sólo cubre lockers e impresiones y nadie ha definido la regla para reservas (cuándo se |
| 5.7-a | E5 | Renta de equipos | Laptops | El recorrido renta el equipo sin depósito sembrado para la demostración; este tipo de equipo no se renta porque todos llevan garantía |
| 5.7-b | E5 | Renta de equipos | proyectores | El recorrido renta el equipo sin depósito sembrado para la demostración; este tipo de equipo no se renta porque todos llevan garantía |
| 5.7-c | E5 | Renta de equipos | cámaras | El recorrido renta el equipo sin depósito sembrado para la demostración; este tipo de equipo no se renta porque todos llevan garantía |
| 5.7-d | E5 | Renta de equipos | kits | Los kits sembrados llevan garantía, que no opera fuera del entorno de pruebas |
| 5.7-e | E5 | Renta de equipos | material deportivo | El recorrido renta el equipo sin depósito sembrado para la demostración; este tipo de equipo no se renta porque todos llevan garantía |
| 5.7-g | E5 | Renta de equipos | depósito/garantía | La garantía de renta depende del piloto de Finanzas, que sólo funciona en el entorno de pruebas |
| 5.7-i | E5 | Renta de equipos | daños | El cobro por daños depende del piloto de garantías de Finanzas, limitado al entorno de pruebas |
| 6.5-a | E6 | Asignación de beneficios | Dinero digital | Las becas en dinero digital dependen de la emisión de bonos de beca en Finanzas, que ninguna cuenta puede autorizar hoy |
| 6.5-b | E6 | Asignación de beneficios | bonos | La emisión de bonos está bloqueada en Finanzas (sin autoridad publicada) |
| 6.5-g | E6 | Asignación de beneficios | integración con equipos 2 y 5 | Con Servicios (E5) sí se demuestra la entrega del beneficio; con Finanzas (E2) no, por el bloqueo de bonos de beca |

## Terceros

| ID | Equipo | Módulo | Requisito | Por qué falta |
|---|---|---|---|---|
| 1.2-c | E1 | Autenticación con Fortify | recuperación | La recuperación de la cuenta termina en un correo: hace falta un buzón real |
| 3.6-e | E3 | Checkout y orquestación de pago | Openpay o pago mixto | Openpay: sin credenciales de pruebas y excluido de esta entrega |
| 3.8-a | E3 | Adaptador Openpay por negocio | Abstracción de proveedor | Openpay está excluido de esta misión por instrucción del usuario; no hay credenciales de pruebas y no se ejecutó nada contra el proveedor |
| 3.8-b | E3 | Adaptador Openpay por negocio | perfil/configuración por negocio | Openpay está excluido de esta misión por instrucción del usuario; no hay credenciales de pruebas y no se ejecutó nada contra el proveedor |
| 3.8-c | E3 | Adaptador Openpay por negocio | referencias | Openpay está excluido de esta misión por instrucción del usuario; no hay credenciales de pruebas y no se ejecutó nada contra el proveedor |
| 3.8-d | E3 | Adaptador Openpay por negocio | callbacks/webhooks | Openpay está excluido de esta misión por instrucción del usuario; no hay credenciales de pruebas y no se ejecutó nada contra el proveedor |
| 3.8-e | E3 | Adaptador Openpay por negocio | estados sin mezclar credenciales entre negocios | Openpay está excluido de esta misión por instrucción del usuario; no hay credenciales de pruebas y no se ejecutó nada contra el proveedor |

