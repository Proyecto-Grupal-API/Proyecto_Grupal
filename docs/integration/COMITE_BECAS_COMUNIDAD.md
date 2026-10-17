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

## Uso en las vistas

1. Como presidencia, abrir **Becas y apoyos → Gestión de becas → Comité**
   en la tarjeta de la convocatoria. Seleccionar responsable, revisores y motivo.
2. Publicar la convocatoria y recibir solicitudes con el flujo habitual.
3. Cerrar recepción. Los integrantes designados encuentran la convocatoria
   en **Gestión de becas → Revisar solicitudes** al iniciar sesión o recargar.
4. El revisor abre un expediente enviado, registra su recomendación y observa
   la confirmación. Puede corregirla antes de la resolución; conserva consulta
   de su propia recomendación después, en modo lectura.
5. El responsable consulta las recomendaciones y registra la resolución con
   motivo. Aprobar/rechazar permanecen deshabilitados mientras falten revisiones.
6. El solicitante consulta su resolución; las notas internas no aparecen.
7. Presidencia consulta el historial del comité. Escape cierra el diálogo y
   devuelve el foco al botón que lo abrió. Los cambios desactualizados exigen recargar.

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

El comité comprueba membresía E6 y sesión; no certifica condición académica ni
consume bajas externas por un transporte de eventos aún no acordado.

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

## Validación

- RED observado: ausencia de rutas del comité, cuatro casos fallidos.
- Primer GREEN: cuatro pruebas y 36 aserciones aprobadas, 9.86 segundos.
- Regresión inicial: 54 pruebas y 579 aserciones, 138.21 segundos.
- Historial/evidencia y permisos del comité: once pruebas y 93 aserciones,
  31.55 segundos. Comprueban privacidad, versiones, cambios, auditoría fallida,
  dictamen propio, recomendaciones completas, bajas/reingreso y baja de cuenta.
- Compilación inicial de vistas correcta, 8.22 segundos.
- Migración aditiva aplicada al alterno, sin reseed. Compilación final de vistas:
  correcta, 9.94 segundos. Código backend en `ad62e48`.
- Edge con tres sesiones aisladas: designación, solicitud enviada, cierre,
  recomendación, resolución bloqueada antes de la revisión y resolución final.
  El solicitante solo recibe el motivo final; no ve notas internas.
- La demostración quedó con convocatoria cancelada y solicitud rechazada,
  sin asignaciones de beneficios. Se conservan comité y evidencia históricos:
  convocatoria `6ad3d0f58439417f8301c26a`, solicitud `6ad3d0f92765ee007308e14c`.
- Comprobación posterior sin escrituras: historial del comité cerrado, Escape
  y restauración del foco, recomendación propia en modo lectura tras resolver
  y privacidad del solicitante. Sin errores JS/consola y sin desbordamiento
  a 320/768/1024/1440 px, tanto en expediente como en diálogo.
- Regresión final: **115 pruebas aprobadas, 1,049 aserciones**, 328.32 segundos,
  once archivos en ejecución serial. Incluye comité, becas, permisos/delegaciones,
  continuidad, organizaciones, comisiones, documentos, periodos e integración E1/E5.
  XML cotejado: 115 casos únicos, once clases, cero errores/fallos/omisiones.
  PHP verificado: `ad62e48`; vistas verificadas: `e07b395`. No hubo cambios de
  PHP, pruebas, rutas ni migración durante esta ejecución.
- Formato PHP y `git diff --check` correctos. No se repitió la suite completa
  histórica de todos los dominios; estas cifras corresponden a la regresión indicada.

Evidencia local ignorada por Git: `storage/logs/comite-becas-20261017/`.
La copia principal y la publicación a GitHub quedan fuera de este incremento.
