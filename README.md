# Campus Digital — Integración de equipos 1, 3, 4, 5 y 6

Rama de integración: `MERGE-1-3-4-5-6`. Una aplicación Laravel con Identidad,
Marketplace, Inventarios, Servicios al estudiante y Comunidad. Finanzas (E2)
y Recompensas/Auditoría (E7) todavía no están incorporados.

- [Estado de la entrega, alcance y preparación](docs/integration/ESTADO_1_3_4_5_6.md).
- [Integración Marketplace, APIs y validación](docs/integration/INTEGRACION_1_3_4_5_6.md).
- [Informe funcional de los 74 submódulos](docs/integration/AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.md).
- [Informe en PDF](docs/integration/AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.pdf).
- [Contratos oficiales de Identidad](docs/integration/TEAM-1-INTEGRATION.md).

El entorno alterno usa `compose.integracion.yaml` y el puerto local `8086`.
Conserva los datos propios de cada dominio y consume las APIs de sus equipos.
Pagos, bonos, reembolsos y puntos permanecen pendientes de sus proveedores;
un pedido o beneficio aprobado no representa una operación financiera ejecutada.

Comunidad todavía conserva algunas consultas heredadas a Identidad, documentadas
como deuda pendiente de sus contratos. La entrega no certifica cumplimiento total
de acceso entre dominios exclusivamente por API.

Los archivos `.env`, credenciales, datos de MongoDB, respaldos y dependencias
no forman parte de la entrega. Las guías históricas documentan cada etapa;
el estado vigente de publicación se registra en `ESTADO_1_3_4_5_6.md`.
