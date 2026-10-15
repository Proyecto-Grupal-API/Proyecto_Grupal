# Permisos operativos de Comunidad

Incremento del 15 de octubre de 2026, aplicado al entorno alterno E1–E3–E4–E5–E6.
Completa parte de los pendientes 6.1, 6.2, 6.4 y 6.7 del informe funcional.

## Responsabilidades

E1 conserva identidad y autorización institucional. E6 administra cargos de una
organización y delegaciones operativas internas. El identificador es `User._id`.
Esta entrega no introduce roles institucionales ni autoridad financiera.

| Cargo vigente | Capacidades de este incremento |
|---|---|
| Presidencia | Administración de la organización, campañas, convocatorias y dictámenes; otorgar/revocar delegaciones |
| Comunicación | Gestionar campañas de su organización |
| Responsable de becas | Consultar expedientes enviados y dictaminar; no modificar convocatorias |
| Otros cargos / integrante | Sin capacidades adicionales automáticas; pueden recibir una delegación explícita |

Las capacidades delegables son `comunicacion.gestionar`, `becas.gestionar` y
`becas.revisar`. Administrar convocatorias y revisar expedientes son capacidades
independientes. Ningún revisor puede dictaminar su propia solicitud ni consultar
borradores ajenos. El cargo de responsable de becas no representa un comité formal.

## Delegaciones

La presidencia vigente puede otorgarlas a otro integrante activo de la misma
organización. Debe indicar capacidades, motivo, inicio y fin; no se permite
redelegar ni conceder administración general, cargos o movimientos financieros.
Los horarios del formulario se interpretan en `America/Mexico_City`; el inicio
es inclusivo y el fin exclusivo. No pueden exceder la vigencia de la presidencia.

El permiso se comprueba en cada petición y en cada lote de campañas. Deja de ser
efectivo al vencer, revocarse, cambiar el cargo de origen, perder la membresía o
suspenderse la organización. Transferir y restaurar una presidencia o dar de baja
y reingresar a un miembro no debe reactivar delegaciones anteriores.

Los registros se conservan con actor, motivo y fechas, junto con auditoría. Una
clave UUID permite reintentar una creación sin duplicarla; reutilizarla con otros
datos produce conflicto. El historial completo corresponde a presidencia; cada
integrante consulta únicamente las delegaciones que recibió.

## API de sesión

Las vistas consumen las rutas existentes de Comunicación y becas y estas rutas
adicionales, bajo la sesión, CSRF y selección de organización actuales:

- `GET /api/organizaciones/delegaciones`: historial paginado y catálogo de capacidades.
- `POST /api/organizaciones/delegaciones`: destinatario, capacidades, inicio, fin,
  motivo y clave de idempotencia.
- `POST /api/organizaciones/delegaciones/{id}/revocar`: motivo de revocación.

Las APIs entre equipos no cambian. Ser delegado no concede representación para
solicitar una tienda de organización ni modifica el contrato E3/E6.

## Pendientes que siguen separados

Comisiones/comité formal, documentos y periodos organizacionales, segmentación
académica, autoridad institucional acordada con E1 y permisos financieros E1/E2.
Los apoyos financieros conservan su estado pendiente y las entregas E5 su contrato.

## Validación

Trabajo pausado por solicitud del usuario el 15 de octubre de 2026. No es una
entrega terminada. Ver [punto de reanudación](PAUSA_COMUNIDAD_2026-10-15.md).
