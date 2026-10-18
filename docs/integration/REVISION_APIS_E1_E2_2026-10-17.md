# Revisión de APIs E1/E2 para continuar Comunidad

Corte: 17 de octubre de 2026, America/Mexico_City. Revisión posterior al comité
de becas. Se ejecutó `git fetch DaniBG04 --prune`; las ramas siguen en:

| Equipo | Rama | Commit completo |
|---|---|---|
| E1 | `feat/modulo-1-equipo-1` | `86111548a7659ffa3dab4fd6ee441bb70160b3d9` |
| E2 | `feat/modulo-2-equipo-2` | `39de155c98469c8a1972814a3e9d508f1bd70410` |

No aparecieron commits nuevos respecto de la revisión previa. No se ejecutó un
merge de esas aplicaciones ni se sustituyó la identidad final por la copia
anterior incluida en la rama financiera. Se conserva la copia principal.

## Lo disponible y lo que falta

| Necesidad | Evidencia del propietario | Decisión para E6 |
|---|---|---|
| Condición académica por ID conocido | E1: `students:read`, `/api/v1/students/{User._id}/status` | Ya integrada por HTTP; en este incremento se valida la respuesta. |
| Nombre/matrícula de un perfil conocido | El mismo estado incluye `name` y `enrollment` | Útil para la condición académica propia. Un perfil no sustituye un directorio general ni prueba existencia/estado de toda cuenta. |
| Búsqueda por matrícula y directorio general | E1 no publica rutas para ello | Continúan pendientes las lecturas heredadas; no se inventa una API de E1. |
| Segmentación por campus/programa/semestre | E1 ofrece etiquetas y semestre; no IDs de campus/programa en este contrato | Se conservan como información académica. No se reconstruyen IDs institucionales ni se habilitan filtros generales que requieran enumerar perfiles. |
| Autorización institucional | `TEAM-6.md`: motor `PUBLISHED_INTERNAL`, API externa `NOT_AVAILABLE` | No consumir check/read business como autorización institucional. Faltan relación organización-ámbito y acuerdo de autoridad. |
| Preferencias de campañas / cambios de perfil | Preferencias con sesión/Sanctum; transporte externo de outbox no acordado | No usar `students:read` como sustituto ni leer el outbox de E1. |
| Cobro de eventos y entrega financiera de becas | E2 tiene APIs financieras parciales, pero no un contrato operativo de cobro E6 completo | No se activan cobros ni se convierte una reserva en pago confirmado. |
| Autoridad financiera | `AppServiceProvider` de E2 conserva proveedores `Pending*` para roles, bonos, caja y delegación/autorización de transferencias | Resolver contrato y autoridad con E1/E2 antes de activar efectos financieros; no sustituirlos por permisos de demostración. |

Fuentes revisadas: E1 `docs/integration/TEAM-6.md`, `routes/api.php` y
`app/Services/StudentStatusService.php`; E2 `routes/api.php`,
`app/Providers/AppServiceProvider.php`, `PurchasePaymentService.php` y contrato
`docs/modulo-2/2.8-api.md`. No se ejecutó la suite financiera en SQL Server.
Las solicitudes previas siguen en
[Dependencias de E1](Dependencias_Equipo_1_Final.md) y
[acuerdos A1–A8 con E2](RESPUESTA_EQUIPO6_A_EQUIPO2_2026-10-12.md).
Este informe no representa una nueva auditoría de porcentajes por equipo.

## Incremento que sí puede completarse ahora

`IdentidadComunidadApi::estado` exige `student_id` y `user_id` iguales al ID
solicitado, uno de los seis estados académicos publicados y
`restrictions`/`benefits_eligible` explícitamente nulos, como establece E1.
Valida el tipo de los textos y del semestre cuando están presentes. Proyecta
solo los campos del estado académico: no reexpone historial, correo personal,
permisos u otros campos ajenos que un proveedor pudiera agregar.

Una respuesta incompatible produce indisponibilidad; no se muestra el perfil
de otra persona ni se deduce elegibilidad. Un 404 sigue significando ausencia
de perfil académico, sin afirmar que la cuenta no existe. El endpoint propio
continúa limitado a la sesión; un `student_id` del navegador no cambia el sujeto.
El envío E5→E6 comparte este cliente: una respuesta ajena tampoco crea avisos.
No se modifican APIs, scopes, clientes ni roles de E1. No hay cambios de vistas,
esquema, datos de prueba ni configuración financiera.

Este ajuste **no elimina las lecturas heredadas de E1** enumeradas en el registro
de dependencias. Por tanto, no cierra todavía el requisito general de acceso
entre módulos solo por API. Tampoco entrega segmentación académica general.

## Orden siguiente

1. E1: acordar y publicar búsqueda por matrícula/proyección de cuentas, con su
   alcance autorizado, respuestas de ausencia y resolución por IDs conocidos.
2. Adaptar los consumidores E6 del directorio, preservar `User._id` y probar
   perfiles ausentes, cuentas inactivas y fallos del servicio sin fallback a MongoDB.
3. E1/E6: cerrar identificadores de campus/programa y vínculo organizacional;
   después implementar segmentación y autorización institucional en sus ámbitos.
4. E1/E2/E6: cerrar autoridad, receptor/contrapartida, cobro/consulta por clave,
   cancelación y resultado tardío. Mantener las reservas de 15 minutos de E6.

No se enviaron mensajes a equipos ni se publicó este incremento en GitHub.

## Validación del incremento

- RED previo al cambio: 12 fallos y 9 casos aprobados; se reproducían la
  aceptación de un perfil ajeno, respuestas incompatibles y campos adicionales.
- Regresión final: **72 pruebas aprobadas, 547 aserciones, 90.53 s**, siete
  archivos de E1/E5/E6 y Staff NFC, sin fallos, errores ni omisiones. XML cotejado:
  72 casos únicos. Incluye rechazo de un aviso ante identidad inconsistente y el
  flujo NFC por HTTP real en el entorno de pruebas.
- Una primera ejecución intermedia detectó que el fake HTTP general de avisos
  precedía al nuevo fake específico; se aisló el dominio del caso adverso. No se
  cambió código productivo para acomodar esa prueba.
- Consulta HTTP real adicional en el alterno con el cliente existente:
  `student_id`/`user_id` coinciden con el miembro consultado, estado `active`,
  13 campos proyectados, elegibilidad/restricciones nulas y sin historial.
  No se imprimieron nombres, matrículas, credenciales ni tokens.
- Pint sobre servicio y prueba nueva, y `git diff --check`: correctos.
  Sin cambios de frontend; no fue necesario recompilar Vite.

Las pruebas automatizadas se ejecutaron de forma serial y exclusivamente contra
`campus_virtual_testing`, como UID/GID 1000:1000. Esta es una regresión dirigida;
no sustituye ni se suma como suite única a las validaciones históricas completas.
Evidencia local excluida de Git: `storage/logs/identidad-comunidad-20261017/`.
