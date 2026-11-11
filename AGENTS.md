# CAMPUS DIGITAL - INSTRUCCIONES PARA AGENTES IA

## Proyecto
Campus Digital, Modulo 2, Equipo 2.
Rama oficial: feat/modulo-2-equipo-2.

## Arquitectura
- Laravel 13 y PHP 8.5.
- Vue 3 e Inertia.
- SQL Server y MongoDB existentes.
- Respetar las estructuras y servicios actuales.
- No migrar a PostgreSQL.

## Trabajo
- Investigar el codigo existente antes de modificarlo.
- Realizar cambios pequenos y enfocados.
- Trabajar solamente en el alcance asignado.
- Preservar idempotencia y consistencia financiera.
- Agregar pruebas pertinentes.
- No cambiar APIs compartidas sin justificarlo.

## Restricciones
- No implementar ni modificar contratos de roles.
- No inventar permisos administrativos.
- No modificar LedgerService sin autorizacion.
- No modificar tests/TestCase.php sin autorizacion.
- No modificar archivos .env ni credenciales.
- No ejecutar migraciones destructivas.
- No ejecutar php artisan migrate, db:seed ni
  pruebas con limpieza de bases compartidas
  sin autorizacion expresa.
- No publicar cambios mediante git push.
- No crear ramas ni realizar merges.
- No ejecutar git reset --hard ni git clean -fd.
- No modificar el worktree principal.

## Entregables
Al terminar cada tarea:
1. Explicar lo encontrado.
2. Indicar archivos modificados.
3. Resumir cambios implementados.
4. Reportar pruebas ejecutadas y pendientes.
5. Identificar riesgos o dependencias.
6. Dejar los cambios disponibles para revision.

No crear commits sin recibir una instruccion
explicita del coordinador.
