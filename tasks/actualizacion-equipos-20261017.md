# Actualización de equipos del 17 de octubre de 2026

Base local `be5f7a7`, rama `MERGE-1-3-4-5-6`, entorno alterno.
Git fetch completado: E1 `8611154`, E2 `39de155`, E3 `b9534b9`, E5 `ea7f0dc`
y E7 `71d49b9` coinciden con los cortes ya revisados/importados. E6 conserva sus
once commits locales posteriores a la publicación. Novedad: E4 `250c558`,
dos commits desde `1dec1fc` (31 archivos). El avance no se mide por cantidad de commits.

- [x] Comparar fuentes y conservar respaldo del código anterior sin secretos/datos.
- [x] Incorporar correcciones de fechas BSON y fechas ISO, conservar aislamiento de negocio.
- [x] Adaptar auditoría automática del dominio; sin consultar almacenamiento de otros equipos.
- [x] Preparar recepción E2 v2 con identidad/versiones e idempotencia, devolución recuperable.
- [x] Adaptar transporte de outbox E7 con reintentos y activación explícita; sin receptor real configurado.
- [ ] Regresión, verificación de ejecución, documentación y commits locales.

No sobrescribir E1, sesión real, actor, permisos, puente multinegocio E3/E4 o
reservas/periodos de E6. Mantener pagos y salida E7 deshabilitados por defecto.
No sembrar alertas/conteos ficticios para aparentar funciones. Todas las pruebas
usan únicamente `campus_virtual_testing`, en serie, como UID/GID 1000:1000.
No publicar a GitHub ni ejecutar migraciones destructivas.
