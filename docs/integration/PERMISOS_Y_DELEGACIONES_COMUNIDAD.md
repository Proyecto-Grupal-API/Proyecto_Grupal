# Permisos operativos de Comunidad

Incremento iniciado el 15 de octubre de 2026, retomado el 16 de octubre en el
entorno alterno E1–E3–E4–E5–E6.
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

Al cierre de este incremento quedaban comisiones/comité formal, documentos y
periodos organizacionales, segmentación académica, autoridad institucional
acordada con E1 y permisos financieros E1/E2. Las comisiones y los documentos se
atienden en el [incremento posterior del 16 de octubre](COMISIONES_Y_DOCUMENTOS_COMUNIDAD.md).
Los apoyos financieros conservan su estado pendiente y las entregas E5 su contrato.

## Uso en las vistas

1. Entrar como presidencia y abrir **Comunidad → Organizaciones**. La mesa
   directiva permite asignar los cargos de Comunicación y Responsable de becas
   a integrantes activos, con las fechas de vigencia del cargo.
2. Para una suplencia, usar **Delegaciones temporales → Otorgar una delegación**,
   seleccionar integrante y funciones e indicar periodo y motivo. Las fechas y
   horas corresponden a Ciudad de México.
3. El integrante obtiene las funciones al iniciar sesión o recargar la vista
   durante el periodo autorizado. La API comprueba el permiso en cada operación.
4. Presidencia puede revocar una delegación con motivo desde el historial. El
   registro permanece visible, con fecha de revocación; no se borra la evidencia.

En becas, **Gestión de becas** aparece tanto para quien administra convocatorias
como para quien revisa solicitudes. **Nueva convocatoria**, edición y cambios
de estado requieren gestión; **Revisar solicitudes**, expedientes y dictámenes
requieren revisión. Tener una capacidad no concede la otra.

## Validación

Reanudada el 16 de octubre de 2026. La pausa anterior se conserva como evidencia
histórica en el [punto de reanudación](PAUSA_COMUNIDAD_2026-10-15.md).

- Regresión de Delegaciones, Continuidad, Gestión de organizaciones y Comunidad:
  **44 pruebas aprobadas, 375 aserciones**; 144.88 segundos.
- Navegador Edge con dos sesiones aisladas, en el alterno: creación y revocación
  de una delegación; receptor con acceso a Comunicación y revisión de becas,
  sin creación de convocatorias; pérdida del acceso al recargar tras revocar.
- Sin errores de JavaScript en el flujo observado. Comprobación de ausencia de
  desbordamiento horizontal a 320, 768, 1024 y 1440 píxeles.
- La delegación de demostración creada en la prueba quedó revocada y conserva
  su historial. No se cambiaron cargos ni roles institucionales de las cuentas.
- Revisión del incremento: pertenencia y cargo de origen vigentes, fin exclusivo,
  denegación de redelegación/administración general, aislamiento por organización,
  expedientes privados, prohibición de dictamen propio y revocación en bajas.

Validación cerrada el **16 de octubre de 2026**, en el entorno alterno:

- Suite completa: **1,131 pruebas aprobadas, 8040 aserciones**,
  cero fallos; 1398.45 segundos. Esta cifra incluye las pruebas específicas;
  no se suma al resultado de 44 pruebas para calcular cobertura.
- Compilación de producción actual: `npm run build`, correcta en 15.68 segundos.
- Evidencia local en `storage/logs/comunidad-permisos-20261016-*` (ignorada por Git).

El incremento queda local. El informe/PDF fechado del 15 de octubre se conserva
como corte histórico y no cambia sus porcentajes por esta entrega parcial.
Las comisiones, la segmentación académica y las integraciones pendientes señaladas
arriba siguen fuera del alcance de este incremento.

Actualización posterior del 17 de octubre: el [comité por convocatoria](COMITE_BECAS_COMUNIDAD.md)
añade designaciones de revisión y resolución acotadas al expediente, sin conceder
capacidades globales. Las convocatorias sin comité conservan el modelo anterior.
