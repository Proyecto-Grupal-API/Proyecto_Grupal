# Periodos organizacionales de Comunidad

Alcance: 6.1–6.3 del PDF, páginas 11–12. Base `c77ef31`, entorno alterno
`/home/daniel/campus_digital_integracion_e1`. El plan anterior de comisiones y
documentos está concluido y conserva sus resultados históricos.

## Decisiones

- Un periodo pertenece a una asociación o Consejo: nombre, descripción e inicio/fin
  inclusivos. Se reutiliza el calendario actual de los cargos (UTC); no se cambia
  la interpretación de las vigencias existentes. La situación se calcula por fecha:
  programado, vigente, finalizado o cancelado.
- Presidencia vigente administra; integrantes consultan periodos y cambios de
  cargos de su organización. No se conceden roles institucionales ni poderes E1/E2.
- No se superponen periodos registrados de una misma organización. Alta con UUID
  vinculada al actor y contenido; edición/cancelación con revisión optimista.
  Las cancelaciones requieren motivo y conservan la evidencia.
- Las fechas de un periodo utilizado por algún cargo, incluso retirado, quedan
  protegidas. Solo se puede cancelar un periodo que nunca tuvo cargos vinculados.
  Nombre/descripción pueden corregirse con auditoría y revisión.
- `periodo_id` es opcional para mantener compatibles los cargos existentes.
  Cuando se vincula, el cargo requiere fin explícito y fechas dentro del periodo.
  La vinculación se conserva en ediciones posteriores: no se reasigna el historial.
- Se conserva el modelo actual de un titular registrado por cargo y organización.
  El relevo automático y la planificación simultánea de varias mesas directivas
  requieren otro incremento y el acuerdo de autoridad institucional con E1.
- Las vigencias efectivas siguen en los cargos; el periodo no crea otra fuente de
  permisos. Transferencias y bajas mantienen su revocación de delegaciones.
  La recuperación por consola conserva el cargo ligado al periodo como histórico
  y crea un mandato sin periodo nuevo cuando corresponda; conserva los mismos
  controles de recuperación y no acepta un titular institucional por otro canal.
- El historial de cargos usa exclusivamente auditoría E6 de cargos, filtrada por
  organización y periodo; no incluye documentos privados ni datos de otros módulos.
- Bloqueo de responsabilidades y transacciones MongoDB actuales; sin dependencias.

## API de sesión

`/api/organizaciones/periodos`: GET paginado y POST. `{id}`: GET de cargos e
historial, PUT de datos y POST `/cancelar`. Contexto, sesión y CSRF actuales.
GET no expone huellas ni claves. Nuevos campos de organización/cargos son aditivos.

## Tareas verificables

- [x] API de periodos: permisos, fechas, solapamientos inclusivos, idempotencia,
  revisión, cancelación conservadora y reversión si falla la auditoría.
- [x] Vista de periodos: alta, lista, edición, cancelación, estados, vacíos/errores;
  compilación y comprobación en navegador; guardar el primer incremento.
- [ ] Vinculación de cargos: límites, organización, periodo no cancelado, vínculo
  estable; cargos previos compatibles y mismos controles de presidencia.
- [ ] Historial y recuperación: consultas filtradas y paginadas; preservación del
  cargo anterior en recuperación de presidencia; sin reactivar delegaciones.
- [ ] Formulario de cargo y mesa directiva: periodo visible y seleccionable;
  comprobar lectura de integrante y denegaciones en API, navegador y regresión.
- [ ] Cierre: inventario completo de pruebas en bloques seriales reanudables,
  guía de uso y contratos, commits locales, árbol limpio. No publicar.

Las pruebas usan únicamente `campus_virtual_testing`, en serie y como UID 1000.
No iniciar Docker Desktop automáticamente. No tocar la copia principal, contratos
de autoridad de E1, clientes OAuth, integraciones financieras ni informes fechados.

## Validación del registro

- RED observado: el alta faltante devolvía 404 antes de implementar la API.
- Siete pruebas del registro: aprobadas, 66 aserciones, 26.66 segundos.
- Regresión de Periodos, Comunidad, Continuidad y Delegaciones: 39 pruebas
  aprobadas, 318 aserciones, 155.18 segundos.
- Compilación correcta en 16.71 segundos. Edge aislado: alta, edición,
  integrante solo lectura y cancelación conservando el registro; sin errores
  JavaScript ni advertencias; sin desbordamiento a 320/768/1024/1440 px.
- El periodo de comprobación quedó cancelado y nunca tuvo cargos asociados.
