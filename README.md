# Campus Digital — Integración de equipos 1, 3, 4, 5 y 6

Rama actual: `MERGE_COMPLETO` (antecedente de integración: `MERGE-1-3-4-5-6`). Una aplicación Laravel con Identidad,
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
el alcance de la integración previa se registra en `ESTADO_1_3_4_5_6.md`.
La publicación posterior de E1 se detalla abajo; no modifica el estado atribuido
a los demás equipos en esos antecedentes.

## Entrega integral E1 integrada y publicada

La integración de E1 se publicó en `MERGE_COMPLETO` mediante
[`59681872462ebae68352869c02b3ae3f72c5be37`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/59681872462ebae68352869c02b3ae3f72c5be37),
sobre `373ce0c5079e421f1c01ce81ae87ed8716272517`: 86 archivos, 53 nuevos y
33 modificados. La entrega de la rama E1 está publicada en
[`660101af6896466138e8a262149478c7fd043090`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/660101af6896466138e8a262149478c7fd043090).

E1 está terminado y entregado técnicamente. La incorporación añade catálogos de
autorización y separación financiera/comercial, política y consulta financiera
HTTP de identidad, directorio D1–D4 con cuotas, privacidad y auditoría, índices
aditivos de `user_sessions` y enriquecimiento de eventos de credenciales.
Conserva las capacidades de identidad, perfiles, autenticación/2FA, NFC/QR,
condición académica, consentimientos, OAuth y outbox presentes en la aplicación.
No sustituye providers, modelos User/StudentProfile, consumidores ni conexiones
de los demás dominios. La migración de sesiones no mueve documentos ni elimina
la colección histórica `sessions`.

La validación focal integrada registró **210 pruebas y 1.037 aserciones**, sin
fallos ni errores funcionales y sin pruebas Risky; hubo 210 advertencias por la
ausencia deliberada de `.env` en la vista privada. Índices de sesiones, auditoría
de destinos y build frontend fueron satisfactorios. D1–D4 integrado dispone
además de una ejecución previa de **31 pruebas y 172 aserciones** satisfactorias.
Estas evidencias corresponden al alcance E1 validado, no certifican todos los
módulos E2–E7 ni un despliegue productivo.

### Activaciones y dependencias externas

- El directorio permanece cerrado por defecto. Publicar su código no habilita
  clientes ni acredita consumo por E6: requiere configuración y acreditación
  de matrícula y cuotas en cada despliegue.
- No se vincula una autoridad financiera productiva ni se crean concesiones
  automáticas. Los efectos financieros siguen siendo responsabilidad de E2;
  el escritor financiero E1 continúa restringido a testing.
- El enriquecimiento de eventos es aditivo y conserva eventos históricos.
  El outbox no demuestra recepción externa: destino, autenticación, operación
  del scheduler y deduplicación requieren acuerdo y verificación con E7.
- Department y la autoridad otorgante institucional siguen pendientes; no se
  habilita una API institucional externa ni se reemplazan autorizaciones E7.
- Se conservan las responsabilidades de comercio/inventario E3–E4, servicios
  y elegibilidad E5 y membresías/cargos E6. Esta actualización no certifica ni
  cambia su estado funcional.

Las pruebas persistentes E1 requieren un perfil privado explícitamente autorizado
y el MongoDB sintético exclusivo acreditado; no deben ejecutarse contra datos
de desarrollo o integración. El perfil privado y sus credenciales no se entregan.

Véanse el [contrato E1](docs/integration/TEAM-1-INTEGRATION.md) y el
[alcance de incorporación](docs/integration/E1-INCORPORACION-MERGE-COMPLETO.md).
Las declaraciones locales/no publicadas de guías anteriores son antecedentes;
los commits enlazados arriba acreditan la publicación técnica de E1, no la
activación de integraciones externas pendientes.