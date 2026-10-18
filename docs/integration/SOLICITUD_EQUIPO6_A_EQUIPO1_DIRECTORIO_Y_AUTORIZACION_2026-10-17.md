# Solicitud E6 → E1: directorio y autorización institucional por API


**Proyecto:** Campus Digital

**Emisor:** Equipo 6 — Comunidad

**Destinatario:** Líder y agente de desarrollo de Equipo 1 — Identidad

**Fecha:** 17 de octubre de 2026 · America/Mexico_City

**Estado:** Solicitud para acuerdo; no constituye un contrato aprobado.

**Prioridad:** P1 para directorio; P2 para ámbitos y autorización institucional.

## Objetivo

Completar la integración sin que Comunidad consulte directamente las colecciones
propiedad de Identidad, conforme a la indicación del profesor. Nos adaptaremos
al contrato que publique E1. Si ya existe una capacidad aplicable fuera de la
rama revisada, indíquennos su documentación y commit antes de desarrollar otra.

Referencia contrastada con GitHub: rama `feat/modulo-1-equipo-1`, commit
`86111548a7659ffa3dab4fd6ee441bb70160b3d9`. Su documento `TEAM-6.md` y las
rutas publicadas son nuestra referencia. Esta solicitud concreta las capacidades
adicionales necesarias para migrar consumidores de Comunidad; no presupone
que E1 haya prometido entregarlas ni cuestiona el alcance de su entrega actual.

## Lo que ya consumimos correctamente

- `GET /api/v1/students/{User._id}/status`, mediante OAuth `students:read`.
- La validación NFC de eventos usa la API publicada y su permiso específico.
- `User._id` es el identificador externo canónico; matrícula y
  `StudentProfile._id` no lo sustituyen.
- El estado académico no determina por sí solo elegibilidad para becas.
  `restrictions` y `benefits_eligible` permanecen nulos según el contrato.
- Un 404 de perfil académico no demuestra que la cuenta no exista.

## Capacidades solicitadas

Solicitamos responder con los identificadores D1–D4 e I1–I3. Los nombres de
rutas, scopes, campos y mecanismos de autorización los propone E1; aquí
precisamos la necesidad funcional, sin dar por aprobado un diseño técnico.

| ID / prioridad | Necesidad | Uso concreto en Comunidad |
|---|---|---|
| **D1 · P1** | Resolver una matrícula exacta y devolver el `User._id` canónico y una proyección mínima autorizada. Distinguir ausencia, coincidencia no utilizable y falta de autorización sin habilitar enumeración indiscriminada. | Alta de integrantes y selección de titulares; eliminar la dependencia de `users.matricula`, que es compatibilidad heredada de nuestra demo. |
| **D2 · P1** | Consultar por IDs conocidos el nombre y, cuando corresponda y esté permitido, la matrícula. Acordar resolución por lotes o mecanismo acotado para listas. | Integrantes, cargos, comités de becas, participantes de eventos y actores de historial. También necesitamos verificar referencias de campañas y padrones, sin una llamada HTTP por cada una de miles de personas. |
| **D3 · P1** | Consultar por contrato oficial la existencia y condición operativa relevante de una cuenta, distinguiéndola del perfil/estado académico. E1 debe precisar estados, ausencia, baja y restricciones de uso aplicables; no inferiremos esas decisiones desde NFC o desde el estado académico. | Validación de referencias de usuarios y nuevas acciones que requieran una cuenta utilizable. Las decisiones de membresía, cargo, cupo y beca siguen siendo de E6. |
| **D4 · P1** | Definir qué proyección de D1–D3 puede consumir Comunidad, con qué actor/finalidad/ámbito y cómo trata cuentas sin perfil estudiantil o dadas de baja. | Mostrar integrantes y conservar trazabilidad sin exigir que toda cuenta sea estudiante ni exponer correo, teléfono, documentos u otros datos personales innecesarios. |
| **I1 · P2** | Exponer por un contrato autorizado los IDs canónicos de campus/programa que necesitemos y su relación vigente. Acordar cómo obtiene E6 la referencia de ámbito al registrar una organización. | Relacionar organizaciones con su ámbito y preparar segmentación académica. Las etiquetas actuales de campus/programa no se convertirán en IDs. E6 no creará catálogos institucionales paralelos. |
| **I2 · P2** | Definir el consumo externo de autorización institucional para `organization_manager` y `career_coordinator`, con sujeto, capacidad, ámbito exacto y vigencia. Acordar autoridad otorgante/revocante con la institución y responsabilidad de cada equipo. | Sustituir la autorización institucional provisional del registro/gestión de organizaciones. La API business existente no se usará para resolver ámbitos campus/programa. |
| **I3 · P2** | Precisar la fuente canónica y autoridad del ámbito departamento, o mantener explícitamente su estado diferido mientras no exista ese acuerdo. | Integrar posteriormente al Jefe de departamento sin inventar IDs, duplicar un catálogo ni sustituir departamento por campus/carrera. |

D1–D4 son una primera entrega funcional propuesta. No requieren habilitar
operaciones financieras ni esperar a resolver todo el ámbito institucional.
E1 puede proponer otra agrupación si mantiene las necesidades y sus límites.

## Responsabilidades que conservamos

**E1:** identidad, cuentas/perfiles, condición académica, catálogos y
roles/asignaciones institucionales dentro de sus responsabilidades acordadas.

**E6:** organizaciones y su vínculo de ámbito acordado, membresías, cargos
internos, periodos, delegaciones propias, comités, elegibilidad/dictámenes,
inscripciones y audiencia de sus campañas. Presidencia, tesorería, comunicación,
responsable/revisores de becas y Staff no se crearán como roles globales E1.

Una decisión institucional no concede automáticamente permisos internos de todas
las organizaciones ni permite usar fondos. La autorización financiera pertenece
al acuerdo E1–E2–E6 descrito en la solicitud del 12 de octubre; esta solicitud no
lo reemplaza ni habilita cobros, bonos, reembolsos o caja.

## Información necesaria para consumir la entrega

Para cada ID solicitado, indiquen:

1. Estado: disponible por API, publicado solo internamente, pendiente de acuerdo
   o fuera del alcance de E1; en este último caso, señalar el propietario a acordar.
2. Documentación/versionado y rama/commit consumibles. Si ya existe la capacidad,
   indicar su contrato actual; no necesitamos duplicarla.
3. Autenticación y autorización: cliente de servicio, contexto humano si se
   requiere, finalidad y ámbitos permitidos. Un ID enviado por el navegador no
   constituye prueba de identidad ni permiso.
4. Proyección mínima y tratamiento de ausencia, baja, falta de perfil y acceso
   denegado. Para lotes, definir límites y resultados parciales por referencia.
5. Política de vigencia/revalidación, caché y fallos. Las acciones sensibles no
   se resolverán como autorizadas cuando no sea posible comprobarlas.
6. Responsable del acuerdo/implementación y entrega propuesta, sin asumir fechas
   ni autoridades no confirmadas por ustedes o por la institución.

No solicitamos credenciales por chat ni que amplíen una allowlist automáticamente.
La activación de nuevos permisos se revisará después de acordar el contrato.

## Verificación conjunta propuesta

- Una matrícula exacta se resuelve al ID canónico; una referencia ausente no se
  sustituye por un usuario de demostración ni por otra coincidencia.
- Una cuenta sin perfil académico se distingue de una cuenta inexistente o dada
  de baja. Las reglas de uso se aplican según el contrato acordado.
- Una lista autorizada obtiene solo los datos permitidos y respeta los límites
  de lote/paginación. Una referencia fallida no oculta el fallo de las demás.
- El ámbito de una organización no autoriza acciones en otro campus/programa.
  Una asignación vencida o revocada deja de autorizar según la política de E1.
- Comunidad consume HTTP y no consulta `users`, `student_profiles`,
  `role_assignments`, Campus ni AcademicProgram para suplir una API faltante.
- Un fallo del servicio no provoca fallback a las colecciones de E1 ni una
  respuesta de autorización/estado inventada.

## Contexto para el agente de E1

La aplicación integrada es un Laravel modular con sesión compartida, pero la
comunicación entre dominios debe usar APIs. Persisten lecturas heredadas en
controladores de organizaciones, eventos y becas, y en resolución de campañas y
padrones. Están registradas como deuda, no como una excepción aprobada.

E6 adaptará esos consumidores después de recibir el contrato. No se solicita al
agente de E1 modificar Comunidad ni implementar interfaces internas de Finanzas.
Las preferencias/consentimientos de campañas y el transporte externo de eventos
siguen siendo acuerdos posteriores; no bloquean la propuesta inicial D1–D4.

Referencias complementarias en la rama de integración:

- [Dependencias identificadas](Dependencias_Equipo_1_Final.md).
- [Revisión de APIs E1/E2](REVISION_APIS_E1_E2_2026-10-17.md).
- [Solicitud anterior sobre identidad y autorización financiera](SOLICITUD_EQUIPO6_A_EQUIPO1_AUTORIZACION_E2_2026-10-12.md).

Este archivo queda listo para que el líder de E6 lo comparta. Su incorporación
al repositorio no representa envío de un mensaje ni aceptación por E1.
