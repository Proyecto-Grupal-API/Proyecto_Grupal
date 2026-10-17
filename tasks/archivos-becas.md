# Archivos de solicitudes de beca

Base 3deaf61; alterno E1–E3–E4–E5–E6, apartado 6.4.

- [x] Reproducir cargas y retiros incompletos si falla auditoría.
- [x] Guardar metadatos y auditoría juntos; compensar cargas abortadas.
- [x] Conservar archivos ante resultados inciertos y borrar solo tras commit confirmado.
- [x] Comprobar limpieza fallida sin restaurar acceso ni anunciar borrado físico.
- [x] Regresión, revisión y documentación; commit local.

Sin cambios de permisos, pagos o contratos externos. Usar solo campus_virtual_testing.

Cierre: RED con cuatro fallos; primer GREEN 4/44. Regresión 67 pruebas y
636 aserciones, 172.33s; XML cotejado sin omisiones. Navegador con carga,
descarga idéntica por SHA-256, retiro y limpieza de la demostración; sin errores
JS/consola y sin desbordamiento a cuatro tamaños. Guía:
docs/integration/ARCHIVOS_PRIVADOS_BECAS_COMUNIDAD.md.
