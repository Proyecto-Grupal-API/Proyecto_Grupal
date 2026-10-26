# Actualización selectiva del 26 de octubre

Base f609bd3, copia alterna, rama MERGE-1-3-4-5-6.
E4 fae5f57; E3 bf9fc2c; E2 bad1bf3; E7 e48031e. E1 8611154 sin cambios.

- [x] Comparar ramas y preservar snapshot de código previo sin secretos/datos.
- [x] Adaptar proveedores/productos surtidos, metadatos de catálogo y compras E4.
- [x] Adaptar lectura de costos históricos y correcciones E4 sin perder referencias/precios.
- [x] Contrastar webhook E4 R1–R6 con controles existentes y comprobar regresión.
- [x] Adaptar mejoras aplicables de E3 sin restaurar mocks ni permisos legacy.
- [x] Evaluar preparación financiera E2 aislada y receptor E7: contratos y bloqueos.
- [x] Probar, revisar ejecución, documentar y guardar commits locales.

Conservar copia principal, sesión/autorización E1 y aislamiento de negocio.
No activar pagos, permisos OAuth, emisores ni puntos. Las pruebas se ejecutan
serialmente solo contra campus_virtual_testing, usuario 1000:1000.
No considerar READY contratos por inspección estática; registrar pruebas reales
y límites pendientes. No publicar cambios automáticamente por este incremento.

## Cierre del incremento

E4: 101 pruebas/816 aserciones; E3 posterior: 34/230. Hay solapamiento:
102 casos únicos, 822 aserciones (último resultado por caso). Vite 9.08 s.
Cinco comprobaciones UI con props controladas, sin errores JS; navegación
E4 autenticada limitada por enrolamiento MFA existente, sin alterar cuentas.
E2 temporal: 54/523 en SQL Server real y Mongo aislados, sin incorporar Finanzas.
E7 temporal: 3/8 publicadas aprobadas; 2/4 de compatibilidad E6 fallan (conflicto
de contenido y origen de auditoría). No se activa ni se instala el receptor.
Guía: docs/integration/ACTUALIZACION_EQUIPOS_2026-10-26.md.
Los checks de evaluación están cerrados; las conexiones operativas E2/E7 y los
acuerdos E1/E2/E6 siguen pendientes, según las limitaciones documentadas.
