# Becas: revisión de contratos

Corte: 11 de noviembre de 2026. Solo `BecaController` del portal; OAuth y otros controladores quedan fuera. Fragmento guardado por incrementos, sin reconstruir el catálogo global.

- **22/22 operaciones inventariadas** conservan path, método y `operationId` originales, cotejados con `runtime-routes.json` y `routes/comunidad.php`.
- **16 reviewed / 6 partial (72.7% revisadas)**. Todas tienen request aplicable y respuesta concreta; `partial` conserva límites de detalle, no respuestas de relleno.
- **43 esquemas propios**, todos con prefijo `Beca`. `Error`, `ValidationError`, `Message`, `Accepted`, `MongoId` y `sessionCookie` se resuelven desde el padre, sin redefinirlos.
- OpenAPI 3.1: nulos con `anyOf` o arreglo de tipos. Campos de `.only()` opcionales y anulables por documentos legacy. Entradas abiertas a extras; arrays Laravel admiten listas/mapas. Restricciones temporales, de identidad y comparación con datos vigentes se describen como validación del servidor.

Prefijo de las rutas de la tabla: `/api/v1/comunidad/portal/becas`. Las respuestas de mensaje referencian `Message` del padre.

| Método y sufijo | Entrada | Respuesta de éxito | Estado |
|---|---|---|---|
| GET `/` | gestion, buscar, page | 200 BecaIndiceRespuesta | partial |
| POST `/` | BecaConvocatoriaEntrada | 201 BecaCreadaRespuesta | partial |
| GET `/tesoreria` | page | 200 BecaTesoreriaRespuesta | reviewed |
| GET `/mis-solicitudes` | page | 200 BecaMisSolicitudesRespuesta | reviewed |
| GET `/solicitudes/{id}` | — | 200 BecaSolicitudDetalleRespuesta | partial |
| PUT `/solicitudes/{id}` | BecaSolicitudEntrada | 200 Message | reviewed |
| POST `/solicitudes/{id}/retirar` | No exige cuerpo | 200 Message | reviewed |
| POST `/solicitudes/{id}/documentos` | BecaDocumentoEntrada, multipart | 201 Message | reviewed |
| GET `/solicitudes/{id}/documentos/{documento}` | — | 200 binario, Content-Type persistido | reviewed |
| DELETE `/solicitudes/{id}/documentos/{documento}` | No exige cuerpo | 200 Message | reviewed |
| POST `/solicitudes/{id}/dictamen` | BecaDictamenEntrada | 200 Message | reviewed |
| POST `/solicitudes/{id}/revocacion-beneficio` | BecaRevocacionEntrada | 200/202 BecaRevocacionRespuesta, estado según código | reviewed |
| POST `/solicitudes/{id}/confirmacion-presidencia` | No exige cuerpo | 200 Message | reviewed |
| POST `/solicitudes/{id}/aval-tesoreria` | BecaAvalEntrada | 200 Message | reviewed |
| PUT `/solicitudes/{id}/recomendacion` | BecaRecomendacionEntrada | 200 Message | reviewed |
| GET `/solicitudes/{id}/contrato` | — | 200 BecaContratoRespuesta | partial |
| PUT `/{id}` | BecaConvocatoriaEntrada, cuerpo completo | 200 Message | reviewed |
| POST `/{id}/estado` | BecaEstadoEntrada | 200 Message | reviewed |
| GET `/{id}/solicitudes` | estado, page | 200 BecaSolicitudesRespuesta | partial |
| GET `/{id}/comite` | — | 200 BecaComiteRespuesta | partial |
| PUT `/{id}/comite` | BecaComiteEntrada | 200 BecaVersionRespuesta | reviewed |
| POST `/{id}/solicitud` | No exige cuerpo | 200 BecaSolicitudDisponibleRespuesta, incluso creación | reviewed |

Los errores están por operación: 401/403/404, 419 en escrituras, 409 según bloqueo/contexto/versión/estado y 429 solo donde existe throttle explícito. 422 usa `ValidationError`; comité/recomendación admiten también `Error` por `abort(422)`. `ResponsabilidadesComunidad` puede producir `errors.password`. Se documenta 500 saneado y 302 de sesión revocada al login, que el middleware devuelve directamente. Descarga y lecturas privadas conservan sus cabeceras; no se inventa un cuerpo JSON para archivos.

## Detalle pendiente concreto

- `index`, `store`, `solicitudes`: `convocatoria()` expande `toArray()` y `index` devuelve `TipoBeneficio::get()`. Se describen atributos conocidos y siete calculados, pero no hay proyección de extras persistidos ni cast/validación de lectura de `beneficio`/`requisitos_documentos`. `store` tiene entrada y 201 descritos; sigue parcial por esa representación compartida abierta, sin afirmar tipos de atributos legacy adicionales.
- `showSolicitud`: documentos usan `except('ruta')`, por lo que metadatos extra legacy sobreviven. Recibos E5 se conservan literalmente; `revocacion.rechazo_e5` guarda JSON remoto 409 comprobando solo `codigo`. Se describen núcleo y variantes verificables, sin inventar el resto del cuerpo remoto.
- `contrato`: `firstOrCreate` conserva contratos anteriores y GET no revalida sus snapshots ni evidencia/extensiones del recibo E5. Sí se detalla el contrato generado actualmente y el recibo E2 normalizado (reference, bonus_id, operation_id; fingerprint no se almacena).
- `comite`: `historial` entrega modelos de auditoría completos y snapshots `antes`/`despues` sin revalidarlos. Configuración actual conocida; metadatos y snapshots históricos adicionales permanecen abiertos.

Fuentes principales: `app/Http/Controllers/BecaController.php`, `GuardarConvocatoriaRequest`, modelos de becas/`Documento`, `ComiteBecas`, `BeneficiosBeca`, `EntregaBeneficiosServicios`, `RevocacionBeneficiosServicios`, `BecaFinanceContract`, `ReconcileScholarshipBonuses`, `ServiceBenefitService::present`, middleware de sesión/contexto y renderizador del portal. Cada operación lleva `x-contract-evidence` y motivo de estado.

## Verificación offline

- Referencias, esquemas Draft 2020-12, parámetros, unicidad de IDs y coincidencia exacta con las 22 rutas: comprobados sin arrancar Laravel.
- Validador existente sobre copia aislada en `/tmp`: **22 operaciones, 16 reviewed, 6 partial, 6 respuestas históricas y 4 requests comprobados, cero errores**. No se ejecutó el constructor ni se modificaron scripts/catálogo.
- Ensamblado temporal con el catálogo disponible durante la revisión: **120 rutas, 51 respuestas históricas y 19 requests comprobados, cero errores**. Sus totales globales incluyen trabajo concurrente ajeno; no se atribuyen a becas ni implican que el catálogo real haya sido actualizado aquí.
- Comprobación adicional de `beca-http.json` y evidencia de revocación: **18 requests compatibles; 21/23 respuestas compatibles**. Los dos GET históricos de expediente en `beca-http.json` omiten `puede_revocar_beneficio`, que `showSolicitud()` actual sí incluye incondicionalmente. El schema mantiene el campo requerido; se registra divergencia temporal, sin ensancharlo para ocultarla.

Sin acceso a DB, HTTP nuevo, pruebas Laravel, cambios de aplicación ni operaciones Git. `reviewed` significa revisión documental estática; no habilita permisos, fondos ni integraciones.
