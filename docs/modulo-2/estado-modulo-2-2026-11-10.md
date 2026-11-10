# Informe de avance del Equipo 2

**Campus Digital · Finanzas, wallet y caja**

Corte del **10 de noviembre de 2026** sobre `MERGE_COMPLETO`, commit de código `6607850`.

El Equipo 2 alcanza **90,5 % de cobertura técnica estimada**, frente a **87,0 %** en el checklist del 8 de noviembre. El avance más importante está en **2.5 Bonos**. Finanzas ya emite una BECA respaldada por presupuesto tras validar el dictamen de Comunidad, el aval de Tesorería y la designación del emisor. Comunidad recupera el comprobante y registra la entrega. La operación se acreditó con identidades, clientes OAuth y bases de laboratorio. [Panel y prueba HTTP de BECA](2.5-panel-beca-consejo.md)

## Avance contra el diseño

| Apartado | Peso | 8 nov. | 10 nov. | Cambio |
| --- | ---: | ---: | ---: | ---: |
| 2.1 Cuentas wallet | 5 | 85 % | **85 %** | 0 pp |
| 2.2 Ledger de movimientos | 5 | 95 % | **95 %** | 0 pp |
| 2.3 Recargas de dinero digital | 4 | 85 % | **90 %** | +5 pp |
| 2.4 Retiros en asociaciones | 5 | 85 % | **90 %** | +5 pp |
| 2.5 Bonos y saldos restringidos | 4 | 80 % | **90 %** | +10 pp |
| 2.6 Transferencias y regalos de saldo | 4 | 85 % | **90 %** | +5 pp |
| 2.7 Retenciones, devoluciones y reversos | 4 | 90 % | **95 %** | +5 pp |
| 2.8 Caja de asociaciones y turnos | 4 | 85 % | **90 %** | +5 pp |
| 2.9 Tickets y comprobantes financieros | 3 | 90 % | **90 %** | 0 pp |
| 2.10 Límites, alertas y conciliación | 4 | 90 % | **90 %** | 0 pp |
| **Total ponderado** | **42** | **87,0 %** | **90,5 %** | **+3,5 pp** |

El cálculo conserva los pesos del diseño y las estimaciones del checklist del 8 de noviembre para los apartados sin evidencia nueva suficiente. Se divide la suma de peso por porcentaje entre 42. La cobertura técnica es orientativa y cuenta la evidencia de laboratorio. La aceptación en el entorno compartido se registra por separado. [Diseño funcional, página 8](../Campus_Digital_Diseno_Modulos.pdf#page=8) · [Base del corte anterior](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/42496fa)

## Evidencia y pendientes por apartado

### 2.1 Cuentas wallet · 85 %

En laboratorio se usaron wallets de alumnos, negocios y organizaciones en MXN. Los cobros registraron saldo disponible y, cuando correspondía, saldo retenido. Falta acreditar las wallets receptoras, sus titulares y la autoridad de alta u operación en el entorno elegido. **Responsables E2 y E1**, con E3, E5 o E6 según el receptor. [Evaluación de Finanzas en SQL Server](../integration/EVALUACION_E2_SQL_2026-10-26.md)

### 2.2 Ledger de movimientos · 95 %

Marketplace, eventos y multas dejaron débito, abono y comprobante. Marketplace y eventos conservaron una sola liquidación al repetir la confirmación. Falta conciliar en el entorno de presentación cada cobro y devolución con su contrapartida, incluidos resultados inciertos. **Responsable E2**, con el equipo que origina la operación. [Pago de eventos](../integration/CONTRATO_PAGO_EVENTOS_E2_E6.md) · [Pago de multas](../integration/API_E2_MULTAS_BIBLIOTECA.md)

### 2.3 Recargas de dinero digital · 90 %

Se probó recarga consentida por el alumno, folio y arqueo. Desde el corte anterior, caja usa la autoridad de Comunidad sobre la organización habilitada. Falta probar la recarga en navegador con un agente delegado y una caja de la asociación o del Consejo. **Responsables E2 y E6**. [Autoridad de caja](../integration/AUTORIZACIONES_FINANZAS_COMUNIDAD.md)

### 2.4 Retiros en asociaciones · 90 %

El retiro de laboratorio registró confirmación del alumno, comprobante y conciliación de turno. El código separa la operación, la revisión y la administración de caja. Falta configurar el umbral y probar un retiro que requiera supervisión con otro operador en el ámbito correcto. **Responsables E2 y E6**. [Autoridad y políticas de caja](../integration/AUTORIZACIONES_FINANZAS_COMUNIDAD.md)

### 2.5 Bonos y saldos restringidos · 90 %

Presidencia confirma el dictamen, Tesorería avala el presupuesto exacto y el emisor designado confirma su 2FA. La prueba HTTP/OAuth emitió un bono de **$30 MXN**, comprometió ese importe en SQL Server y recuperó un único comprobante al repetir. El worker de Comunidad registró la entrega después de revocar al emisor. Falta recorrer la demo en navegador, preparar las designaciones operativas de E1 y acreditar por separado la administración, liberación y liquidación presupuestarias. **Responsables E1, E2 y E6**. [Evidencia BECA](2.5-panel-beca-consejo.md) · [Liberación de BECA](2.5-liberacion-beca.md)

### 2.6 Transferencias y regalos de saldo · 90 %

E2 conectó la autorización personal con la sesión y la elegibilidad de ambas cuentas de Identidad. La operación aplica la política SQL, los límites, el consentimiento y la clave de idempotencia. Falta habilitar y recorrer el piloto con cuentas de prueba, y validar las atribuciones sobre la política y la delegación para consumidores externos. **Responsables E2 y E1**. [Transferencias personales](../integration/AUTORIZACIONES_FINANZAS_COMUNIDAD.md)

### 2.7 Retenciones, devoluciones y reversos · 95 %

E2 liquida garantías con cargo parcial, liberación o pérdida total. El laboratorio retuvo **$500 MXN**. Con daño cobró **$150** y liberó **$350**, con devolución en buen estado liberó **$500** y con pérdida cobró **$500**. Los reintentos conservaron los comprobantes. En eventos, E6 puede conciliar el reembolso confirmado sin alterar el acceso ni el lugar asignado a otra persona. Falta conectar el consentimiento y la vigencia de renta en E5, y acreditar revisores y ejecutores de devoluciones con E1. **Responsables E1, E2, E5 y E6**. [Piloto de garantías](../integration/PILOTO_GARANTIAS_RENTA_E5_E2.md) · [Conciliación de eventos](../integration/CONTRATO_PAGO_EVENTOS_E2_E6.md)

### 2.8 Caja de asociaciones y turnos · 90 %

El turno de laboratorio registró recarga, retiro, folios y arqueo. E6 conecta las delegaciones de Presidencia con la caja de la misma organización. E2 mantiene las wallets, el control del turno y la conciliación. Falta vincular una caja y sus contrapartes aprobadas y repetir apertura, operación, revisión y cierre con los perfiles previstos. **Responsables E2 y E6**. [Caja de asociaciones y Consejo](../integration/AUTORIZACIONES_FINANZAS_COMUNIDAD.md)

### 2.9 Tickets y comprobantes financieros · 90 %

La cuenta titular puede consultar folio y referencia y descargar el PDF. El comprobante de compra institucional distingue la cobertura BECA y la devolución al bono o al presupuesto. Falta verificar la consulta por personal auditor y la delegación al consumidor autorizado en el entorno elegido. **Responsables E2 y E1**. [Comprobante institucional](2.5-comprobante-compra-institucional.md)

### 2.10 Límites, alertas y conciliación · 90 %

E2 programa el cierre con cola y bloqueo en SQL Server. Se probaron diferencias de caja y devoluciones, y la conciliación BECA de E6 recuperó el comprobante por HTTP. Falta ejecutar el ciclo diario con alertas, revisión de diferencias, entrega de eventos y auditoría del entorno compartido. **Responsables E2, E4, E6 y E7**. [Cierre financiero](../integration/EVALUACION_E2_SQL_2026-10-26.md)

## Coordinación con los equipos

| Equipo | Evidencia al corte | Acción pendiente |
| --- | --- | --- |
| E1 · Identidad | La consulta por Consejo, el TOTP reciente y la revocación funcionaron en el piloto BECA. | Preparar el cliente OAuth y las designaciones del piloto. Completar las atribuciones de administración presupuestaria, devoluciones y comprobantes que correspondan. |
| E3 y E4 · Marketplace e Inventarios | Dos tazas por $178 MXN dejaron un solo comprobante y un movimiento SALE tras repetir la confirmación. | Repetir la prueba conjunta de respuesta perdida y cancelación o vencimiento frente a un cargo confirmado. Conservar el pago en revisión de devolución si se pierde la reserva. |
| E5 · Servicios al Estudiante | La multa de $15 MXN y las variantes de garantía pasaron en laboratorio. El PR #8 sigue abierto sobre `9e1230b`. | E5 corrige la cancelación cuando ya se solicitó el cobro y separa la bandera de analítica. E2 valida la wallet `services`. E1 registra el cliente de lockers e impresiones cuando se prepare su piloto. |
| E6 · Comunidad | Se probó el cobro de un evento de $25 MXN y su QR conciliado. El dictamen, el aval y el comprobante BECA se conectaron con Finanzas. | Preparar el Consejo, el presupuesto y los perfiles de demo. Repetir la emisión, la conciliación y la consulta del alumno en navegador. Validar caja y transferencias en ese entorno. |
| E7 · Recompensas y auditoría | Hay evidencia de recepción de auditoría firmada y deduplicación en el merge. | Verificar el efecto del pago mixto sobre recompensas y su conciliación en el entorno acordado. |

[Prueba de Marketplace e Inventarios](../integration/INCORPORACION_E4_a3ea96d_2026-10-30.md) · [PR #8 de E5](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/pull/8) · [Integración con Plataforma](../integration/INTEGRACION_1_2_3_4_5_6_7.md)

## Entregas y alcance de las pruebas

- `d194239` conectó los pilotos de devoluciones, garantías y autorización de operaciones. `0641a56` incorporó la autoridad de caja de Comunidad y las transferencias personales.
- `7c87ffa` incorporó juntos los cambios de E1 y E2 para autorizar BECA por Consejo y presupuesto. `888f2d2` conectó el panel y TOTP. `6607850` registró la prueba de transporte y conciliación.
- El último commit de la rama oficial de E2 consultado fue `d082973`. Su diagnóstico de PowerShell se adaptó para WSL en `73760e8`. La cobertura BECA y devoluciones en el comprobante ya estaba incorporada en el merge.
- La revisión del panel incluyó **24 pruebas unitarias**, **13 casos en MongoDB** con avisos por el `.env` ausente en el laboratorio y **4 pruebas de Vue**, sin fallos. Vite compiló. La prueba de transporte agregó **13 comprobaciones** con OAuth, MongoDB y SQL Server desechables. El recorrido del operador en navegador sigue pendiente en el entorno de presentación.

[Comparación de código desde el 8 de noviembre](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/compare/42496fa...6607850) · [Rama de E2](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/tree/feat/modulo-2-equipo-2) · [Revisión histórica de E2](estado-modulo-2-cb8e243.md)
