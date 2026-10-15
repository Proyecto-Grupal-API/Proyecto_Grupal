# Reevaluación del equipo 2 — 9 de octubre de 2026

Revisión de las ramas públicas y los dos ZIP enviados por Daniel. Comparación de archivos por contenido, lectura de rutas, servicios, bindings, migraciones, vistas y pruebas. No se modificaron los proyectos ni se integró cartera.

## Versión revisada

- Rama con backend financiero actual: `feat/modulo-2-equipo-2`.
- Commit: `57d6817ef4e59d75e408930ae30c7b90bc4418be`, 9 de octubre de 2026, 09:01:11 America/Mexico_City.
- La rama `Equipo-2` continúa en `6d1eaf4ce4e807bf85988951673a7a0d3a1b275e`, 23 de septiembre; contiene el prototipo anterior.

## Comparación con los ZIP

Se omitieron dependencias, archivos de entorno y artefactos generados. La raíz doble del ZIP 2.8 se normalizó antes de comparar. Los conteos son de archivos de proyecto, no de funcionalidades.

| ZIP | Idénticos | Modificados en GitHub | Nuevos en GitHub | Solo en ZIP |
|---|---:|---:|---:|---:|
| Proyecto_Grupal-modulo-2-con-2.10.zip | 244 | 38 | 61 | 2 |
| Proyecto_Grupal-feat-modulo-2---2.8.zip | 214 | 16 | 113 | 11 |

El avance actual incorpora y ajusta la base 2.10, además de devoluciones simples/mixtas, reversos administrativos, retenciones, políticas versionadas e historial. No es una copia idéntica de los ZIP. Tampoco es la unión completa: los 11 archivos exclusivos del ZIP 2.8 corresponden a caja/turnos (modelos, enums, servicio, migraciones y prueba).

## Estimación por alcance

El PDF de diseño original asigna 42 puntos de esfuerzo a las diez funciones. Las cifras siguientes estiman implementación de lógica de backend por evidencia de código; no significan aceptación funcional ni pruebas ejecutadas. Considerar un margen de al menos cinco puntos porcentuales.

| Función | Backend estimado | Evidencia y pendiente principal |
|---|---:|---|
| 2.1 Cuentas wallet | 90% | Modelo SQL Server, saldos disponible/retenido y consulta; falta alta/resolución por propietario vía API y operación completa en UI. |
| 2.2 Ledger de movimientos | 85% | Créditos/débitos/transferencias, idempotencia, locks y reversos; faltan verificación funcional independiente y cierre de pagos con contrapartida comercial. |
| 2.3 Recargas | 80% | Servicios y API de solicitud/confirmación; pendiente integrar identidad autorizada, caja y comprobantes. |
| 2.4 Retiros | 65% | Solicitud y ejecución en servicio; HTTP ofrece solicitud/consulta, falta completar autorización, aprobación/entrega y caja. |
| 2.5 Bonos | 75% | Reglas, vigencia, consumo parcial/múltiple y devoluciones; no hay POST de emisión ni binding real de BonusAuthorizationProvider. |
| 2.6 Transferencias y regalos | 50% | Transferencia interna en LedgerService; faltan API pública, validación de participantes y flujo diferenciado de regalos/solicitudes. |
| 2.7 Retenciones, devoluciones y reversos | 90% | Retención/liberación/captura/vencimiento, política versionada y APIs de devoluciones/reversos. Capturar no acredita automáticamente al negocio. |
| 2.8 Caja y turnos | 0% | Ausente del HEAD revisado. El ZIP de 2.8 contiene CashRegister/CashShift/CashMovement y CashShiftService, todavía no incorporados en esta rama. |
| 2.9 Tickets y comprobantes | 15% | Folios y referencias parciales; sin módulo consolidado de recibos y verificación QR. |
| 2.10 Límites, alertas y conciliación | 80% | Backend y APIs incorporados; límites por rol dependen del proveedor pendiente y caja declara NO_INTEGRADA. |

La media de backend ponderada por los puntos de esfuerzo del PDF es 65.4% (redondeo orientativo: 65%). La interfaz financiera revisada solo consulta wallet e historial; no permite ejecutar las nuevas operaciones. Estimación de UI: 20%. Integración con los demás módulos: 35%, por APIs y autorización aún incompletas. Con un criterio de 60% backend, 20% UI y 20% integración, la cifra orientativa global ronda 50%; no es una medición oficial del curso ni una garantía de ejecución.

## Bloqueos para Comunidad y Servicios

1. Emisión de bonos/becas: solo GET /bonuses/{id}; BonusAuthorizationProvider no tiene implementación registrada de autorización institucional.
2. Cobros: no hay API HTTP de pago/transferencia y resolución de wallet por usuario; los servicios internos no cumplen por sí solos el requisito de consumir otra área por API. PurchasePaymentService debita al comprador, sin contrapartida acreditada al negocio en ese flujo.
3. Roles e identidad: PendingFinancialRoleProvider rechaza evaluar roles pendientes; Module1IdentityAdapter usa IdentityService local y User::find, en lugar de consumir la API final de E1. No conviene reemplazar nuestra identidad ya integrada por la copia anterior que incluye esta rama.
4. Caja: PendingCashReconciliationSource declara que caja no está integrada. Hay que recuperar y adaptar el avance 2.8 del ZIP o pedir su consolidación al equipo 2.
5. Comprobantes y UI operativa: faltan recibos/verificación y pantallas para las funciones nuevas.

Las APIs actuales usan OAuth de servicios y scopes separados para lectura, control, retenciones, reversos y devoluciones. Eso todavía no sustituye la autorización del usuario humano que inicia un cobro o una beca.

## Verificación

Se encontraron 24 archivos de pruebas financieras con 277 casos declarados sin multiplicar datasets. Se verificó sintaxis con PHP 8.5 del entorno alterno, pasando el texto por stdin sin ejecutar la aplicación financiera. La suite funcional no se ejecutó: requiere su MongoDB y SQL Server de pruebas (`campus_digital_financial_testing`), separados de nuestras bases. Los resultados de ejecución que se mencionan en documentos del equipo no se presentan como una validación realizada aquí.

## Recomendación

El avance sí justifica preparar una integración selectiva posterior al conjunto 1–5–6. Antes de activar dinero real o de prueba como pago confirmado, cerrar los contratos HTTP de cobro/bonos, autorización institucional y contrapartida comercial; consolidar caja 2.8; y ejecutar regresiones en SQL Server aislado. Mantener por ahora los pagos pendientes en Comunidad y Servicios.

## Fuentes verificadas

- [Commit financiero revisado](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/57d6817ef4e59d75e408930ae30c7b90bc4418be).
- [Rutas API](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/57d6817ef4e59d75e408930ae30c7b90bc4418be/routes/api.php).
- [Informe de integración del equipo 2](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/57d6817ef4e59d75e408930ae30c7b90bc4418be/docs/modulo-2/integracion-2.10-2026-10-09.md).
- [Políticas de retención](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/57d6817ef4e59d75e408930ae30c7b90bc4418be/docs/modulo-2/2.7-politicas-retencion.md).
- PDF local: `/home/daniel/campus_digital/docs/Campus_Digital_Diseno_Modulos.pdf`, página 8.
- Los dos ZIP originales permanecen en `/mnt/c/Users/bambi/Downloads`.
