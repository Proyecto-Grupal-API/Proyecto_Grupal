# Revisión de solicitud del Equipo 2 a Comunidad

**Fecha:** 12 de octubre de 2026, America/Mexico_City.\
**Documento recibido:** [Solicitud_Equipo2_a_Equipo6_Formato.docx](/mnt/c/Users/bambi/Downloads/Solicitud_Equipo2_a_Equipo6_Formato.docx).\
**Código revisado:** checkout alterno `/home/daniel/campus_digital_integracion_e1`, incluyendo sus cambios locales de integración 1–5–6.\
**Finanzas contrastado:** `feat/modulo-2-equipo-2`, commit `39de155c98469c8a1972814a3e9d508f1bd70410`; el HEAD remoto se volvió a verificar.

## 1. Qué significa la solicitud

Equipo 2 pide que preparemos **nuestro lado de la conexión** para cobros de eventos, beneficios monetarios de becas y consulta de caja. Comunidad conserva eventos, inscripciones, cupo, convocatorias, dictámenes y cargos de organizaciones. Finanzas conserva fondos, saldo, ledger, cobros, cancelaciones/reembolsos financieros y caja.

Es coherente con nuestra [solicitud original REQ-M6-E2-001](/home/daniel/campus_digital/docs/solicitudes/Solicitud_Equipo_6_a_Equipo_2_Wallet_Pagos_Bonos.docx). Ambas son propuestas para acuerdo, con responsables/fechas pendientes. **Las rutas propuestas no se consideran implementadas ni aprobadas.**

No se nos solicita construir otra wallet ni editar saldos. El documento propone preparar adaptadores, contexto autorizado, estados, recuperación y pruebas. La alternativa de compartir sesión puede servir para obtener al actor, pero mantenemos la regla acordada del proyecto: las consultas entre dominios se hacen por HTTP/API, sin acceder a datos ajenos.

## 2. Lo que ya tenemos y cambia el diagnóstico del documento

La solicitud se basa en un ZIP de Comunidad y en su contrato antiguo. En nuestra copia local más reciente:

- La aprobación genera una asignación separada con clave estable `beca:{solicitud_id}`, monto en centavos, moneda MXN y fin de vigencia exclusivo. [Contrato local](/home/daniel/campus_digital_integracion_e1/app/Services/BeneficiosBeca.php:12)
- Dictamen aprobado y entrega están separados: el controlador registra la decisión y prepara la asignación. [Dictamen](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/BecaController.php:238)
- Ya hay entrega por API de **lockers e impresiones al Equipo 5**, con recibo, estado `asignada`, reintentos y próximo intento. Esta conexión **no entrega dinero ni bonos de E2**. [Entrega E5](/home/daniel/campus_digital_integracion_e1/app/Services/EntregaBeneficiosServicios.php:9)
- Los eventos reservan cupo con pago pendiente; la lista de espera existe. El QR/check-in exige reserva confirmada y pago `pagado` o `exento`. [Inscripción](/home/daniel/campus_digital_integracion_e1/app/Services/InscripcionesEvento.php:16), [acceso](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/EventoController.php:188)
- Existe un resumen de organizaciones por API, pero contiene solo ID, nombre, tipo y estado; no da vínculo financiero ni permisos de caja. [Resumen](/home/daniel/campus_digital_integracion_e1/app/Http/Controllers/Api/Community/OrganizationSummaryController.php:12)

Por tanto, parte de la infraestructura se puede aprovechar, sin dar por implementada la integración financiera.

## 3. Revisión de los seis requerimientos

| Requerimiento | Estado actual | Trabajo de Comunidad |
|---|---|---|
| **E6 01 · Adaptador financiero** | Pendiente. El envío existente es hacia E5, no E2. | Cliente independiente para las APIs aprobadas de pagos, beneficios, consulta por clave, cancelación, reembolso y caja. Referencias por operación, sin consultas a SQL Server desde E6. |
| **E6 02 · Recuperación e idempotencia** | Parcial: clave/recibo/reintentos de becas y E5. Falta seguimiento financiero. | Registro recuperable de operaciones, contenido inmutable, intento/último error/próximo intento, resultado desconocido y consulta por clave antes de reenviar. Generación nueva al reinscribir. |
| **E6 03 · Contexto verificable** | Parcial: IDs de origen, precios calculados en backend y cargos locales. Faltan APIs de contexto financiero y acuerdo de autoridad. | Contexto de evento/inscripción/organización, identidad canónica, actor validado, propósito y versión. Resolver permisos con E1/E2 y la vigencia del cargo propio de E6. |
| **E6 04 · Cupo y pago** | Parcial: cupo/lista de espera y acceso bloqueado por pago pendiente. No hay vencimiento financiero ni coordinación de pago tardío/reembolso. | Reserva con plazo y versión, habilitación de cobro solo con lugar autorizado, promoción de espera, vencimiento y conciliación de cancelación/pago tardío. |
| **E6 05 · Beneficio financiero versionado** | Parcial: versión 1, centavos, fin exclusivo y texto informativo. No hay restricciones estructuradas ni fuente financiera autorizada. | Nuevo esquema acordado con tipo de entrega, restricciones y referencia de financiamiento. Preservar texto informativo y no enviar expedientes. |
| **E6 06 · Entrega, revocación y caja** | Parcial: dictamen/entrega separados y resultado de E5. Cancelación de beneficio emitido y vínculo de caja pendientes. | Estados financieros confirmados/rechazados/pendientes, revocación coordinada según uso, relación organización↔asociación financiera y consulta autorizada de caja. |

### Dos cambios importantes del modelo actual

**Reinscripción:** `InscripcionesEvento` reutiliza el registro de la misma pareja evento/usuario después de cancelarlo. Renovar el QR no basta para distinguir operaciones financieras. Añadir generación e historial; cada nueva generación debe tener su propia clave de cobro, sin perder el pago/cancelación anteriores. Una forma de clave por acordar sería `evento:{registro_id}:g{generacion}:cobro`; no es un contrato aprobado. [Servicio](/home/daniel/campus_digital_integracion_e1/app/Services/InscripcionesEvento.php:16), [modelo](/home/daniel/campus_digital_integracion_e1/app/Models/RegistroEvento.php:15)

**Importes:** el registro ya conserva `monto_centavos`, pero se calcula desde un costo `float` del evento. Para el intercambio financiero conviene fijar precio canónico en centavos y un snapshot por generación; no tomar del navegador ni recalcular un precio distinto durante el reintento. [Inscripción](/home/daniel/campus_digital_integracion_e1/app/Services/InscripcionesEvento.php:16), [costo](/home/daniel/campus_digital_integracion_e1/app/Models/Evento.php:35)

## 4. Qué construir de nuestro lado

### A. Operación externa recuperable

Conservar por operación: origen, organización, evento/solicitud, generación cuando aplique, tipo, clave estable, versión y huella del contenido, actor/contexto autorizado, referencia financiera, resultado/evidencia y seguimiento de intentos.

El proceso debe diferenciar envío pendiente, resultado desconocido, confirmación, rechazo y cancelación/reembolso pendientes o resueltos. Los nombres definitivos y transiciones se acuerdan con E2; son conceptos de implementación, no un enum financiero aprobado.

Ante timeout, consultar al dueño por clave antes de repetir. Una misma clave con datos diferentes debe producir conflicto. Una respuesta antigua debe guardarse en la operación correspondiente, sin habilitar una inscripción de otra generación. No perder evidencia de un cobro real aunque el resultado llegue tarde.

### B. Reservas y boletos

Mantener estado del lugar separado del pago. `confirmada` hoy representa cupo reservado, no un cobro financiero. No enviar un cargo por una inscripción en espera, vencida o cancelada sin lugar autorizado.

Definir el plazo de reserva y su relación con la inscripción, la espera y el pago. Al vencimiento/cancelación, bloquear acceso según el estado del dominio. Un pago tardío no habilita automáticamente un boleto sin cupo: se concilia y se aplica la política acordada, incluyendo reembolso cuando corresponda. No fijar un plazo arbitrario ni descartar el resultado financiero tardío.

Las cancelaciones pagadas hoy se bloquean pidiendo gestionar devolución. Debe añadirse el flujo coordinado de solicitud/consulta y evidencia, conservando esa protección durante la adaptación. [Cancelación actual](/home/daniel/campus_digital_integracion_e1/app/Services/InscripcionesEvento.php:37)

### C. Beneficios de becas

Definir una versión nueva para el intercambio financiero: tipo de entrega (dinero digital o bono), importe/vigencia, restricciones de uso, posibilidad de uso parcial/combinación si aplica y referencia de fondos autorizada.

El texto `reglas` se conserva como información; no se interpreta como política ejecutable. En E2 el catálogo visible de restricciones contempla `NEGOCIO` y `CATEGORIA`; los IDs, combinación y reglas exactas deben acordarse. Un bono no se convierte automáticamente en dinero retirable.

No sobrescribir un contenido ya enviado bajo la misma clave de beca. Versionar nuevas asignaciones y adaptar pendientes solo cuando se pueda demostrar que no se emitieron. Preservar los recibos existentes de E5 y su contrato actual; un cambio para Finanzas no debe invalidarlos.

Cancelar/revocar una beca aprobada debe consultar si el beneficio se emitió y si se consumió. E2 determina cómo cancelarlo o revertirlo; E6 conserva decisión, motivo e historial. No borrar el registro ni modificar saldo desde Comunidad.

### D. Organización y autoridad

Añadir el contexto mínimo y el mapeo de IDs para que E2 identifique organización, asociación financiera/caja y responsable. Acordar tratamiento del Consejo y sus fuentes, no asumir que toda organización tiene caja.

Los cargos y sus periodos pertenecen a Comunidad. Identidad valida la cuenta/contexto del actor y conserva sus autorizaciones institucionales. Finanzas aplica sus permisos específicos. El nombre `tesoreria` o `presidencia` no concede por sí solo capacidad de mover dinero; la consulta y la operación de caja requieren permisos distintos.

Revisar la vigencia en cada acción; acordar el significado temporal de los periodos. No convertir los roles business actuales de E1 en permisos financieros de organizaciones. La deuda de lecturas heredadas de Identidad debe tratarse al construir esta integración. [Cargos](/home/daniel/campus_digital_integracion_e1/app/Models/RolOrganizacion.php:9), [dependencias](/home/daniel/campus_digital_integracion_e1/docs/integration/Dependencias_Equipo_1_Final.md:9)

## 5. Acuerdos que necesitamos cerrar antes de habilitar operaciones

| Acuerdo | Quién interviene | Decisión necesaria |
|---|---|---|
| **Fuente de fondos** | E2, E6 y autoridad académica/institucional correspondiente | De dónde se financia cada apoyo, quién autoriza y qué referencia verificable emite el dominio financiero. |
| **Actor y permisos** | E1, E2 y E6 | Contexto válido del usuario; qué cargo/autoridad puede solicitar cada acción; alcance y vigencia; prueba o mecanismo aprobado de delegación si aplica. |
| **Reservas/pago tardío** | E6 y E2 | Plazos, estado que permite cobrar, regla de espera, cancelación concurrente y resolución de pago fuera de vigencia. |
| **Restricciones y tipos** | E2 y E6; E3 cuando se usen IDs de negocios/categorías | Catálogo estructurado, IDs canónicos y significado de uso parcial/combinación/vigencia. |
| **Identificadores** | E1/E2/E6 | `User._id` como referencia de cuenta y mapeo explícito organización↔asociación financiera. No usar matrícula como clave financiera ni reconstruir IDs por nombres. |
| **Contrato de APIs** | E2 y E6 | Rutas finales, autenticación, estados, consulta por clave, errores/conflictos, evidencia mínima, cancelación y devolución. |

La solicitud no prueba que E2 ya tenga publicadas todas las APIs que necesitamos. El documento declara que entregará su contrato por separado; la rama contrastada todavía mantiene proveedores de autoridad pendientes. No inventar sus endpoints ni habilitar permisos para compensarlo.

## 6. Alcance de las rutas propuestas

| Propuesta en el Word | Revisión |
|---|---|
| Contexto de evento/inscripción | Necesario para validar cupo, importe, pagador, origen, versión y vencimiento; forma final por acordar. |
| Beneficio de solicitud aprobada | Necesario para transportar la aprobación/snapshot financiero mínimo; sin documentos del solicitante. |
| Contexto de organización | Reutilizar el núcleo del resumen existente y añadir únicamente el contrato autorizado que necesita E2. No romper el contrato de E7. |
| Callback de resultado | **Opcional en P1.** Puede incorporarse después, con deduplicación, autenticación y resultados fuera de orden. |
| Listado de pendientes | Proceso interno del worker; no necesita exponerse como API pública. |

Para P1 recomiendo envío y consulta periódica por clave mediante worker. Se necesita la API de consulta de E2; no leer su base ni su outbox. La entrega al vecino y el registro local deben ser recuperables ante interrupción, no una llamada de red escondida dentro del dictamen o inscripción.

## 7. Orden de trabajo y pruebas

1. Acordar el contrato mínimo E6–E2 y cerrar autoridad/financiamiento con los dueños correspondientes.
2. Definir snapshots, generaciones, estados y operación recuperable; actualizar documentación local que todavía describe solo el contrato antiguo.
3. Construir contextos autorizados y el adaptador financiero con consultas por clave; verificar con dobles controlados antes de conectar las APIs reales.
4. Implementar becas monetarias/bonos, manteniendo intacta la entrega de servicios E5.
5. Conectar cobros de eventos, vencimiento, espera, cancelación/reembolso y acceso con evidencia real.
6. Añadir consulta de caja como **P2**, conforme a nuestra solicitud original. Operar efectivo continúa siendo responsabilidad de E2.
7. Demostrar los flujos conjuntos y entregar salida de pruebas más rama/commit para `Testings`.

Pruebas necesarias: duplicados y contenido cambiado, timeout después de ejecutar, recuperación tras caída del worker, fondos insuficientes, actor/cargo vencido, aislamiento entre organizaciones, espera sin cargo, vencimiento/pago tardío, cancelación concurrente, reinscripción con generación distinta, rechazo de beneficio, consumo parcial al revocar, fin exclusivo y regresión de lockers/impresiones/Staff.

Las pruebas actuales cubren partes locales (dictamen idempotente, QR pendiente, cancelación/reinscripción y entrega a E5); no acreditan aún estas operaciones financieras. No se ejecutó ninguna suite en esta revisión.

## 8. Recomendación

**Sí es viable desarrollar nuestro lado.** El primer entregable debe ser el contrato acordado y el mecanismo recuperable; los movimientos reales se habilitan después de confirmar APIs, fondos y autoridad de E2/E1.

Este trabajo puede prepararse en paralelo a la incorporación de E4. No exige rehacer la unión 1–5–6 ni construir otra wallet. La revisión entrega un diagnóstico y un plan; no implementa todavía los seis requerimientos ni envía una respuesta a otro equipo.

## Referencias locales


Base local: `b2da6a0ecb3cadec801e4e62629d4ee0bb3d091d` más cambios de trabajo. SHA-256 del Word recibido: `6e1367da290a2e8b0fb2edcb609c542b6b9117e8638a9f9ac0767eee3532b8cd`.
