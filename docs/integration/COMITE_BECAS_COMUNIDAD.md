# Comité de becas por convocatoria

Incremento local del 17 de octubre de 2026, base `86f92e1`, alterno integrado.
El usuario aprobó revisores que recomiendan y un responsable que dicta la resolución
final. Corresponde al expediente y dictamen del apartado 6.4 del documento general.

## Responsabilidades y privacidad

Presidencia designa, con motivo, un responsable y de uno a diez revisores distintos,
todos integrantes activos de su organización. La asignación concede únicamente
funciones sobre esa convocatoria. No crea roles globales, clientes OAuth ni
autoridad institucional o financiera. Una delegación de gestión de convocatorias
no permite designarse como responsable: configurar el comité exige presidencia.

Revisores y responsable consultan solo expedientes enviados. El revisor registra
una recomendación favorable/desfavorable con observaciones; consulta su propia
recomendación y no las notas de otros revisores. Responsable y presidencia
consultan las recomendaciones. Presidencia conserva supervisión, pero cuando
existe comité solo el responsable designado puede dictaminar.

Nadie recomienda ni dicta su propia solicitud. Para resolver se exige al menos
una recomendación independiente y todas las recomendaciones de los revisores
que no sean el solicitante. Una recomendación desfavorable no es una resolución:
el responsable emite su propio motivo y decisión. El solicitante recibe únicamente
su resolución, nunca notas internas ni la composición privada del comité.
La revisión requiere recepción cerrada; los borradores ajenos permanecen privados.

Una aprobación sigue comprobando cupo, cuenta, vigencia y permisos, y prepara
la asignación local pendiente. Las entregas E5 y las dependencias financieras E2
conservan sus contratos y controles; esta funcionalidad no ejecuta pagos.
Las comprobaciones heredadas de identidad y la proyección de nombres siguen
documentadas en `Dependencias_Equipo_1_Final.md`; no se añaden búsquedas directas
al directorio E1. Las vistas reutilizan la API de integrantes existente.

## Versiones, bajas e historial

Configurar o reemplazar el comité incrementa su versión y conserva el antes/después
en auditoría. Las recomendaciones de la versión anterior permanecen, pero no
satisfacen dictámenes futuros. Se permite reemplazar durante borrador, recepción
y revisión, con motivo; convocatorias canceladas/finalizadas no admiten cambios.

Cada recomendación tiene su revisión: se puede corregir mientras la solicitud
no tenga resolución. Un cambio con revisión desactualizada recibe 409; repetir
el mismo contenido devuelve la recomendación actual sin duplicar la auditoría.
La recomendación se vincula al actor de sesión, solicitud y versión del comité.

La resolución congela la versión de comité y los IDs/revisiones de recomendaciones
utilizadas, en un campo privado de la solicitud y en auditoría. Reemplazos posteriores
no atribuyen la resolución anterior al nuevo comité; el responsable conserva
la consulta de las recomendaciones de la versión utilizada. Los expedientes
resueltos rechazan nuevas recomendaciones.

La baja de un integrante o cuenta retira su acceso y marca el comité para reemplazo.
Reingresar/restaurar la cuenta no reactiva esa designación. Suspender organización
o perder membresía activa también impide consultar/recomendar/resolver. Mientras
haya integrantes retirados/inactivos se requiere una nueva designación explícita.

## API y persistencia

Rutas de sesión bajo autenticación/CSRF/contexto actuales:

| Ruta | Operación |
|---|---|
| `GET /api/becas/{id}/comite` | Composición actual; presidencia recibe los diez últimos movimientos |
| `PUT /api/becas/{id}/comite` | Presidencia: `version`, `responsable_id`, `revisor_ids`, `motivo` |
| `PUT /api/becas/solicitudes/{id}/recomendacion` | Revisor: `version_comite`, `revision`, `recomendacion`, `motivo` |
| Rutas existentes de expedientes/documentos/dictamen | Revalidan el alcance del comité |

Una configuración desactualizada recibe 409: recargar antes de volver a confirmar.
No hay clave de idempotencia para repetir ciegamente esa designación. El comité
se guarda en la convocatoria; recomendaciones en `recomendaciones_becas`, con
índice único por solicitud, versión de comité y actor. Las escrituras nuevas
comparten transacción MongoDB con la auditoría. Reutilizan bloqueo de
responsabilidades y bloqueo por convocatoria normalizado, siempre en ese orden.
No se realizan llamadas HTTP dentro de esas transacciones.

Las convocatorias sin comité conservan el flujo anterior de revisión. Configurar
un comité es explícito; no se convierte ni se reasigna el historial previo.
La selección de organización se revalida y las nuevas vistas envían
`X-Organization-Id` en las escrituras. La API oculta notas/composición a solicitantes.

## Operación y límites

Se requiere la migración aditiva en la réplica del alterno, sin reseed:

```bash
php artisan migrate --path=database/migrations/2026_10_17_000300_create_recomendaciones_becas.php --force
```

No ejecutar rollback destructivo para pausar: las recomendaciones son evidencia.
Tampoco usar controladores anteriores mientras existan comités configurados,
porque desconocen sus restricciones de acceso. Conservar convocatorias,
solicitudes, recomendaciones y auditoría del mismo respaldo.

## Validación en curso

- RED observado: ausencia de rutas del comité, cuatro casos fallidos.
- Primer GREEN: cuatro pruebas y 36 aserciones aprobadas, 9.86 segundos.
- Regresión inicial: 54 pruebas y 579 aserciones, 138.21 segundos.
- Historial/evidencia y permisos del comité: once pruebas y 93 aserciones,
  31.55 segundos. Comprueban privacidad, versiones, cambios, auditoría fallida,
  dictamen propio, recomendaciones completas, bajas/reingreso y baja de cuenta.
- Compilación inicial de vistas correcta, 8.22 segundos.
- Migración real, navegador y cierre de regresión pendientes de registro.

Evidencia local ignorada por Git: `storage/logs/comite-becas-20261017/`.
La copia principal y la publicación a GitHub quedan fuera de este incremento.
