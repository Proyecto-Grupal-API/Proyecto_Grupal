# Revisión e integración del PR #8 — Equipo 5

Corte: 11 de noviembre de 2026. Base integrada `1b648b7`; entrega E5 `4ffb39c`; PR #8 fusionado en `MERGE_COMPLETO` como `0963365`. Se comparó el árbol remoto fusionado con el candidato probado: coincide exactamente. Se conservaron los borradores locales de OpenAPI.

## Cambios incorporados

- Cobros de lockers e impresiones: consentimiento personal en Finanzas y preparación/consulta/cancelación desde E5.
- Una solicitud enviada a Finanzas permanece pendiente si la integración se apaga: no se cancela localmente sin conocer su resultado.
- No se permite superponer pago manual o beca a una solicitud ya enviada a Finanzas.
- Analítica E5→E7 separada de auditoría, con `PLATFORM_SERVICES_ANALYTICS_ENABLED=false` por defecto.
- Emisor local de eventos, recibos, reintentos y escalamiento de SLA.

## Evidencia

161 pruebas Feature, 1305 aserciones, cero fallos/errores. Incluye las 27 de pagos de Servicios, emisor E7 y soporte, más 134 de regresión de integración, revocación, recibos y auditoría. Emitieron advertencias de dotenv por la ausencia deliberada de `.env` en el candidato protegido. Los transportes de esas Feature utilizan dobles cuando lo declara cada suite; no se anuncian como flujo distribuido productivo.

Se ejecutaron además 25 comprobaciones de dominio sobre SQL Server 2022 real y Mongo aislados: configuración y privilegios, cliente autorizado, solicitud, replay, contenido conflictivo, cobro de 25000 centavos, transferencia de contrapartida, repetición sin otro movimiento, cancelación anterior/posterior, saldo insuficiente, contraseña vencida y revocación del cliente. Apertura explícita de fixture: 100000 centavos; saldo final del alumno 75000 y receptor 25000. No es una recarga real ni saldo del entorno integrado.

El ensayo SQL usa servicios de dominio y fixtures de sesión reales en un runtime propio con verificaciones de destino; no es navegador ni transporte HTTP completo. La clase `FinancialModule/StudentServiceCheckoutTest.php` no se ejecutó bajo el TestCase de E1, cuyo perfil integrado es exclusivamente Mongo. No se cambiaron esas guardas para admitir SQL.

El harness necesitó normalizar la comparación de UUID privados entre entidades recién creadas y recargadas: SQL Server puede devolverlos en mayúsculas. También se corrigió la construcción de su Request sintético y se probó la revocación con saldo suficiente, para no confundirla con el rechazo previo por falta de fondos. Estos ajustes no alteraron el código productivo ni debilitaron sus comprobaciones.

Vite compiló correctamente en el candidato con dependencias de solo lectura y caché temporal. No se ejecutó una activación financiera, migración de la base de presentación o concesión de permisos por el merge. Las banderas nuevas permanecen apagadas. Los contenedores de la aplicación ya estaban detenidos antes del trabajo y se conservó ese estado.

## Estado operativo pendiente

La configuración privada revisada no tiene todavía cliente/receptor y banderas de cobros de Servicios, ni secreto/corte de auditoría E5. Git no traslada esas credenciales. Antes de mostrar cobros en la demo faltan preparación autorizada, migración focal de SQL y un ensayo web/HTTP de usuario en ese entorno. El merge de código y las pruebas aisladas no equivalen a esa activación.

La compatibilidad declarada por E5 para revocaciones de apoyos se conserva; garantías de renta, consentimiento y vigencia se documentan por separado en `equipo5/GARANTIAS-DEFINICION-E5.md`. No se da por cerrada esa aceptación con estos resultados.

Evidencias: `evidence/e5-2026-11-11/`. Los servicios de la copia original y las bases persistentes no se iniciaron ni modificaron para estas pruebas.
