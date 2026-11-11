# Cierre funcional de Comunidad — 11 de noviembre de 2026

Entorno entregable: `/home/daniel/campus_digital_integracion_e1`, rama `MERGE_COMPLETO`, aplicación `http://127.0.0.1:8086`. Los laboratorios de validación son temporales y no constituyen otra copia del producto. La copia original de Campus Digital se conserva.

## Cambios que ya funcionan

- **Pagos de eventos por organización:** piloto habilitado solamente para Sistemas. El backend comprueba organización, receptor financiero, importe y vigencia; la reserva conserva sus 15 minutos. El comando `comunidad:preparar-pagos-eventos {slug}` revisa sin modificar y `--activar` prepara la conexión local sin crear saldo ni roles. Quitar una organización impide nuevas preparaciones, pero permite recuperar pagos previos mientras la integración siga habilitada.
- **Cobro y acceso:** la prueba HTTP con usuarios nuevos ejecutó dos cobros de $25.00; el primer boleto habilitó QR y una entrada, rechazando la segunda. El segundo pago perdió su lugar: quedó en `devolucion_pendiente`, con QR bloqueado y la persona de espera promovida. Revisor y ejecutor distintos completaron segundo factor reciente real. La devolución fue de $25.00 desde la cuenta receptora; repetirla con la misma clave y contenido no movió dinero de nuevo. Cambiar el motivo produjo 409. E6 concilió el recibo como `devuelto` y conservó el lugar promovido.
- **Beneficios E5:** E6 consulta primero por clave, también en el primer intento, para recuperar una respuesta perdida antes de volver a enviar. Solo confirma recibos que coincidan con solicitud, beneficiario, organización, convocatoria, folio, cantidad, recurso y vigencia. La prueba con HTTP/OAuth reales E6→E5→E1 entregó 100 impresiones y un locker; reintentar conservó dos asignaciones y un solo locker.
- **Avisos financieros privados:** pagos confirmados, devoluciones ejecutadas y BECA realmente emitida pueden alimentar la bandeja existente. Solo el cliente financiero configurado puede usar esos tipos. Se construyen enlaces fijos a comprobantes o bonos, se valida al destinatario con E1 y se mantiene el aislamiento por cliente y usuario. El emisor escanea hechos persistidos; su outbox SQL tiene unicidad, payload inmutable, recuperación por GET, lease, reintentos y corte temporal obligatorio. No se hacen peticiones HTTP dentro de la transacción que mueve dinero.
- **Reporte de eventos:** incorpora número de cobros, bruto, devoluciones con recibo y neto; las mismas métricas aparecen en CSV. Usa la fecha E2 cuando existe y declara la cohorte de preparación para datos heredados. El corte, la organización, los UUID y los centavos se verifican. No representa saldo disponible ni reconstrucción histórica de la fecha de devolución.

## Verificación de esta entrega

| Alcance | Resultado |
|---|---|
| Feature: bandeja financiera, integración E5 y recibos | 68 casos, 474 aserciones, sin fallos ni errores |
| Feature: métricas, reportes, configuración de pagos, avisos previos, becas y reservas | 107 casos, 1,673 aserciones, sin fallos ni errores |
| Unit: evidencia y entrega de avisos financieros | 15 casos, 108 aserciones, aprobados |
| Recorrido HTTP de eventos y devolución | 91 comprobaciones aprobadas |
| Avisos HTTP/OAuth con SQL Server y MongoDB | 17 comprobaciones aprobadas; tres avisos, cero duplicados al repetir |
| Beneficios HTTP/OAuth reales | Dos asignaciones y un locker real; reintento sin duplicado |
| BECA hasta la dependencia faltante | 57 comprobaciones aprobadas; emisión y consumo no realizados |
| Edge automatizado | Bandeja, mensaje con comprobante y eventos; sin errores de JavaScript ni respuestas 500 |

Las 175 Feature emitieron advertencias por la ausencia deliberada de `.env` en el perfil protegido de E1. No se ocultaron ni se presentan como una ejecución sin advertencias. Estas pruebas no equivalen a la suite integral de los siete equipos. La primera versión del cliente de prueba necesitó corregir la negociación de versión de Inertia, respetar el límite HTTP, esperar códigos TOTP no reutilizados y repetir exactamente el motivo; esos ajustes no debilitaron controles del producto.

La apertura de $50.00 fue una fixture explícita de cuentas nuevas en bases aisladas. Después de dos pagos y una devolución quedaron $25.00 en cada wallet y siete entradas de ledger. No se probaron contraseñas de cuentas existentes.

## Estado del entorno integrado

Los avisos E2→E6 quedaron activados para hechos posteriores al corte UTC registrado en `evidence/comunidad-2026-11-11/activacion-local.json`. El primer escaneo no seleccionó ni envió histórico. La activación conservó siete wallets, 58,000 centavos disponibles, cero retenidos y diez entradas de ledger del entorno existente. No se asignaron roles humanos. Las credenciales permanecen únicamente en `.env`, fuera de Git; en `.env.example` la entrega sigue apagada.

Para observar sin mover dinero: `php artisan financial:community-notices --limit=30`. El scheduler lo ejecuta cada minuto cuando está habilitado. Para desactivar entregas nuevas basta `FINANCIAL_COMMUNITY_NOTICES_ENABLED=false` y recargar la configuración/procesos que la tengan cacheada; los recibos y mensajes existentes se conservan.

## BECA: bloqueo concreto, sin simular emisión

El laboratorio acreditó convocatoria, solicitud, dictamen de Presidencia, aval de Tesorería por 3,000 centavos contra un UUID exacto, designación oficial de prueba del emisor, OAuth y 2FA reciente. E1 autorizó al emisor y a Tesorería; E2 llegó a consultar el presupuesto.

**No existe una vía habilitada para aprobar el presupuesto inicial.** `InstitutionalBudgetService::approve` exige `budget.approve`; `InstitutionalBudgetAuthorizationService::allows` de E1 solo implementa `budget.commit`. Por eso el intento de aprobación fue rechazado y la revisión de emisión terminó en 404 por presupuesto inexistente. El aval de una solicitud no crea un presupuesto institucional. No se insertó uno como aprobado ni se sustituyó el autorizador para aparentar el recorrido completo. E6 conserva `pendiente_integracion`, sin recibo; se emitieron cero bonos.

Además, `RoleAssignment::assertPilotGrantEnvironment()` limita las designaciones BECA y de devoluciones a `APP_ENV=testing` con `campus_virtual_testing`. La integración entregable es local con `campus_integracion`. Habilitar una bandera no sustituye un mecanismo oficial de designación válido en ese entorno.

## Dependencias que impiden acreditar más de 95%

1. E1: mecanismo autorizado para designar/revocar atribuciones en el entorno entregable, conservando ámbito, vigencia y evidencia. No cambiar el entorno a testing ni quitar su guarda.
2. E1/E2: cerrar la autoridad de aprobación del presupuesto inicial, separada de `budget.commit`, o aportar un presupuesto de prueba previamente aprobado por una autoridad válida. E2 mantiene el financiamiento, importes e integridad; E1 verifica la atribución.
3. E1: habilitación acreditada del directorio; el código actual tiene consumidores vacíos, integridad/cuota sin acreditar y no publica selección académica/global con IDs canónicos. Las lecturas heredadas de identidad siguen pendientes de retiro en esos recorridos; las etiquetas de perfil no equivalen a un ámbito institucional.
4. E7: productor/contrato de avisos de recompensas y agregados analíticos cuando formen parte del alcance. El acuse de auditoría existente no acredita estos efectos.
5. Aceptación: prueba física NFC, catálogo estricto de todos los endpoints del portal y aceptación integral con las autoridades/segmentación anteriores disponibles. El catálogo versionado previo sigue siendo parcial en esquemas; esta entrega sí actualiza el contrato estricto del endpoint de avisos.

La estimación funcional previa de 85.24% y la rúbrica de 94.95/100 pertenecen al corte anterior y miden cosas diferentes. Esta entrega acredita capacidades adicionales con evidencia; **todavía no certifica el objetivo superior a 95% ni un 100% del módulo**. La matriz completa debe reevaluarse con los once apartados y los mismos pesos cuando se cierre el recorrido operativo faltante.

## Evidencias

Los JSON y JUnit de `evidence/comunidad-2026-11-11/` están saneados y proceden solo de las fixtures o de agregados de activación. `resumen.json` distingue comprobaciones de un tramo y un recorrido completo. El contrato actualizado está en `comunidad-notificaciones.openapi.json`.
