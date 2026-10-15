# Punto de reanudación — Comunidad

Pausa solicitada por el usuario el **15 de octubre de 2026**. Detener trabajo;
reanudar únicamente cuando el usuario lo pida.

## Ubicación y alcance

- Checkout: `/home/daniel/campus_digital_integracion_e1`.
- Rama: `MERGE-1-3-4-5-6`.
- Base publicada antes de este incremento: `84133a7`.
- El checkout original `/home/daniel/campus_digital` no se editó.
- El incremento actual queda local, sin push y pendiente de validación final.

## Implementado, pendiente de cerrar

- Catálogo de tres capacidades operativas de E6: campañas, gestión de
  convocatorias y revisión de becas. Presidencia conserva administración general.
- Cargo `comunicacion` para campañas y `responsable_becas` para expedientes y
  dictámenes; revisión y administración de convocatorias son independientes.
- Modelo, migración, servicio y API de delegaciones con periodo obligatorio,
  idempotencia, auditoría, revocación y aislamiento por organización.
- Revocación al cambiar/recuperar presidencia, retirar miembros/cargos, eliminar
  cuentas o suspender la organización. Uso de transacciones MongoDB.
- Sección de delegaciones en Asociación y controles de Comunicación/becas
  adaptados a capacidades. El worker comprueba permiso de comunicación por lote.
- La migración nueva ya se ejecutó en la base de demostración del alterno.
  No se sembraron usuarios/cargos ni se otorgaron delegaciones de demostración.

## Comprobaciones realizadas

- RED previo a los cambios: fallos esperados en los nuevos permisos (403) y en
  ruta de delegaciones todavía inexistente (404).
- `PermisosComunidadTest`, `ComunicacionTest` y `BecasTest`: **35 aprobadas,
  369 aserciones**, después de recuperar Docker Desktop.
- Primera ejecución de delegaciones: seis aprobadas y una fallida por orden
  canónico de cabecera Cache-Control (`no-store, private`). Se corrigió la
  expectativa conservando las dos directivas.
- Se agregaron pruebas de suspensión/reactivación y separación gestión/revisión.
  Se interrumpió voluntariamente la regresión de Delegaciones, Continuidad,
  Gestión de organizaciones y Comunidad al solicitar la pausa; no hay resultado
  final de esa ejecución. No ejecutar suites paralelas contra la base compartida.
- `npm run build`: correcto (10.73 s).
- Pint en los siete archivos PHP nuevos: correcto.
- `git diff --check`: correcto antes de guardar el punto de pausa.
- Navegador aislado Edge: no llegó a iniciar sesión porque el selector
  `button[type=submit]` no encontró el botón del login actual; el navegador cerró.
  No creó ninguna delegación ni modificó datos de Comunidad. La validación visual
  y de flujos en navegador sigue pendiente; corregir selector al reanudar.

## Al reanudar

1. Revisar el diff local y este documento. No publicar el incremento como terminado.
2. Ejecutar regresión serial en `campus_virtual_testing`, revisar nuevos permisos,
   reversión de bajas y transacciones; ampliar pruebas si hay casos sin cubrir.
3. Revisar la interfaz real (sesión aislada) y completar prueba de crear/revocar
   delegación, receptor, diseño adaptable y ausencia de errores JS.
4. Revisar implementación y seguridad, ejecutar verificaciones finales, actualizar
   evidencia y dejar commit final. No subir nada sin autorización correspondiente.

PHP/npm siempre como usuario `1000:1000` dentro de `campus_integracion_e1-app-1`.
No ejecutar pruebas contra la base de demostración. No alterar E1/E2, roles
institucionales ni autoridad financiera. Los demás faltantes de E6 siguen en el
informe funcional; este incremento no completa comisiones ni segmentación.

## Complementos

Agent Skills aplicado a implementación incremental, pruebas, permisos e interfaz.
Ponytail activado para reutilizar controles y evitar dependencias adicionales.
Graphify se consultó, pero `list_repositories` y `list_workspaces` devolvieron
`no_workspace`/403; no se utilizó su grafo para inferir dependencias.

## Evidencia local auxiliar

Logs guardados bajo `storage/logs/pausa-comunidad-20261015-*` (ignorados por Git).
Script y captura del navegador en la carpeta de visualizaciones de esta conversación.
