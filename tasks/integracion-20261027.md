# Integración de equipos 2 y 7 — 27 de octubre

Autorización: incorporar los equipos faltantes en el alterno para presentación.
Origen E2 `7460d47`; E7 `e48031e`; respaldo previo en `/tmp/campus-integrar-20261027/antes-integracion.tar`.

- [x] Importar dominios propios sin reemplazar Identidad ni los módulos 3–6.
- [x] Adaptar rutas de Finanzas y páginas de E7 al acceso existente.
- [x] SQL Server exclusivo, migraciones y acceso de mínimo ámbito.
- [x] Corregir idempotencia/origen del receptor E7 y probar autenticación.
- [x] Preparar y probar entrega Comunidad→E7 mediante API sin enviar expedientes. Activado con autorización: 71 auditorías, duplicado 200, conflicto 409, sin cambios de puntos/saldos.
- [x] Verificar páginas, operaciones propias y regresión de módulos existentes.
- [x] Documentar activación, límites, datos de presentación y reversión.

Los contratos institucionales/financieros pendientes no se consideran aprobados.

Punto de recuperación inicial: `52edb36`. Batería dirigida inicial: 109/498. Navegador: diez páginas del estudiante, sin errores y sin desbordamiento horizontal a 390 px. La regresión completa terminó con los resultados indicados abajo.

Cierre: regresión completa 1,368/9,634; dos casos adicionales y batería afectada 111/511, todos aprobados. Recepción y envío E7 activos sólo en el alterno; checkout, autorización de bonos y webhooks financieros E4 siguen apagados.
