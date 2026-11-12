# Segunda revision de reintentos financieros

Alcance: Adjustments.vue, Controls.vue y useIdempotentAttempt.js. Revision estatica de los contratos existentes; ninguna llamada al backend ni a las bases de datos.

## Contratos verificados

| Operacion de interfaz | Evidencia en backend | Politica de la interfaz |
| --- | --- | --- |
| run | ReconciliationWebController::store comprueba actor y trigger INTERFAZ. ReconciliationService::run/findByIdempotencyKey busca la corrida por clave, compara fecha y wallets normalizadas y devuelve la corrida terminal existente. Los locks protegen concurrencia por clave y fecha. | Reintento manual con la misma clave, URL y payload. Un replay devuelve 200; una creacion devuelve 201. Un resultado FALLIDA confirmado tambien es terminal: no se vuelve a ejecutar automaticamente. |
| evaluate | FinancialLimitController::evaluate llama FinancialLimitService::evaluate: consulta sin escrituras, sin reserva y sin deduplicacion por clave. El resultado depende de limites y movimientos actuales. | Consulta repetible con los mismos datos, identificada expresamente como readOnly; NO se presenta como recuperacion del resultado original. La cabecera no es la justificacion. |
| hold: release/capture | FinancialAdjustmentWebController::holdAction llama WalletHoldService::close. Bloquea wallet y retencion. Si esta cerrada, exige transaccion completada con la misma clave, modo, referencia, importe, actor y motivo antes de devolverla. Un cierre nuevo registra closing_transaction_id dentro de la transaccion. | Reintento manual identico. Si vencio despues de una captura ya realizada, la rama de replay precede a la comprobacion de vencimiento. Otro cierre produce conflicto; no se cambia la clave para evitarlo. |
| reverse | FinancialAdjustmentService::reverse busca la transaccion por clave antes de crear el reverso; comprueba operacion, referencia, estado, ejecutor y motivo. La escritura es transaccional y la colision de unicidad produce conflicto. FinancialAdjustmentWebController::run tambien verifica web_actor_id/web_action_reason. | Reintento manual identico. Nunca cambiar motivo ni transaccion de destino con esa clave. |
| refund: complete | FinancialAdjustmentService::completeRefund bloquea original y solicitud; si esta COMPLETADA devuelve su financial_transaction_id solamente con clave, estado y referencia coincidentes. FinancialAdjustmentWebController::run verifica actor y motivo web. | Reintento manual identico. |
| purchase-refund: complete | PurchaseRefundService::complete aplica la comprobacion de solicitud completada, clave y referencia PURCHASE_REFUND. La variante con obligaciones institucionales delega a InstitutionalBecaPurchaseService::completeRefund/refund: busca InstitutionalBudgetOperation por clave y comprueba request_hash, accion, solicitud y transaccion. Integridad y autorizacion siguen aplicando antes del replay y pueden impedir recuperarlo. | Reintento manual identico sin nueva escritura; no garantiza respuesta exitosa. La variante institucional tiene ademas la incompatibilidad de actor descrita abajo; no se acredita exito de extremo a extremo. |
| request-refund | FinancialAdjustmentService::refund busca la transaccion REFUND_REQUEST por clave y comprueba original, monto y registro administrativo; el controlador agrega comprobacion de actor/motivo web. | Reintento manual identico. |
| request-purchase | PurchaseRefundService::request bloquea la compra y busca la solicitud por clave; compara compra, reparto de wallet/bonos y solicitante. La rama de replay no compara motivo; el snapshot frontend conserva tambien ese dato. | Reintento manual identico. |
| policies, limits create/edit, alert status, revisiones de devolucion, recuperacion de retiro y resolve | Los controladores no ofrecen replay por esta cabecera. Algunas acciones tienen comprobaciones de estado o de registro existente, pero no se asume de ellas un contrato general de replay. | Conservador: no reenviar tras resultado incierto. Tener Idempotency-Key en la solicitud no habilita reintentos. |

La politica del helper ahora es denegar replay por defecto. Cada pagina debe declarar explicitamente replay financiero comprobado o consulta sin escrituras. Estas opciones no se envian al servidor.

## Dependencia institucional pendiente (sin modificar)

FinancialAdjustmentWebController::actor devuelve user:<id> y purchaseRefundAction lo pasa a PurchaseRefundService::complete. Al detectar obligaciones institucionales, este delega directamente a InstitutionalBecaPurchaseService::completeRefund. Su metodo actor solo acepta 24 caracteres hexadecimales y lanza AuthorizationException para user:<id>, antes de escribir. No hay adaptador en esta cadena inspeccionada. La rama institucional contiene deduplicacion real en el servicio, pero esta incompatibilidad impide afirmar funcionamiento exitoso de extremo a extremo desde el endpoint web. Corresponde a una revision backend autorizada, no a inventar identidades ni modificar contratos desde el frontend. Mantener los reintentos identicos no evita ese rechazo. Un 403 tampoco libera automaticamente el intento.

## HTTP 409, 422 y 503

- 409 de ajustes: FinancialAdjustmentWebController::run convierte InvalidArgumentException en conflicto; puede indicar clave usada, solicitud incompatible, estado o regla financiera. La variante institucional tambien lanza ConflictHttpException. No prueba que una solicitud anterior no se ejecutara. Conservar clave y datos.
- 409 de limites/revisiones: FinancialLimitWebController::update detecta revision obsoleta; los servicios tambien generan conflictos de negocio. No interpretar un conflicto posterior a una respuesta perdida como permiso para repetir la escritura con otra clave. La interfaz conserva el intento incluso ante un primer 409 (politica conservadora; no hay clasificacion estructurada suficiente para todos los motivos).
- 409 de run con code RECONCILIATION_IN_PROGRESS: puede corresponder al lock por fecha, por clave o a una corrida existente EN_PROCESO. No equivale a cancelacion ni demuestra que sea la misma corrida. Mantener intento; esperar y reintentar manualmente los mismos datos. Otros 409 de run incluyen fecha/alcance o propietario de clave incompatibles; no rotar clave para sortearlos.
- 422: las validaciones de estos controladores preceden a ejecutar los servicios. Un primer 422 libera la solicitud rechazada para corregir datos con clave nueva. Si antes hubo incertidumbre, un 422 posterior no demuestra que el primer envio no se ejecutara: conservar el intento y mostrar errors/message originales.
- 503 de evaluate con code DEPENDENCY_UNAVAILABLE: FinancialLimitController captura FinancialDependencyUnavailableException; FinancialControlResponses::dependencyUnavailable genera esta respuesta. Es un fallo de dependencia de una consulta sin escrituras. Se permite repetir la consulta con iguales datos; se conserva el intento por uniformidad, sin reenvio automatico.
- Otros 503: ni el codigo HTTP solo ni ese texto garantizan rollback de una escritura. No trasladar la semantica de evaluate a run/ajustes. Conservar solicitud; solo repetir donde existe replay comprobado. Los endpoints restantes no tienen aqui una respuesta 503 estructurada que permita declarar un rechazo definitivo.

## Como verificar un resultado incierto

1. Mantener abierta la pestana original. Cerrar el dialogo NO cancela ni descarta el intento; abrir otra accion vuelve al mismo dialogo.
2. Abrir la misma seccion financiera en otra pestana, con la misma cuenta y wallet. Consultar registros existentes sin reenviar formularios.
3. Ajustes: localizar la solicitud de devolucion y su estado; para retenciones, el identificador y su cierre; para reversos, los movimientos correspondientes. Contrastar identificadores, importe, motivo y fechas. Para politicas, revisar la version y el historial existente.
4. Controles: filtrar conciliaciones por fecha, revisar alcance/wallets, ejecutor, estado y diferencias; para limites consultar su historial; para alertas revisar estado e historial; para resolve consultar la diferencia y su nota de resolucion.
5. Una coincidencia parcial o la ausencia de un registro NO certifican el resultado: ajustes limita los listados a 50 elementos, y hay paginacion, filtros y posibles operaciones concurrentes. Si la evidencia es ambigua, pedir al responsable financiero que verifique los registros y su correlacion. No iniciar otra operacion para probar si la anterior tuvo efecto.
6. Donde hay replay real, el boton Confirmar solo recupera/reintenta la solicitud conservada y puede confirmar el resultado sin crear una clave nueva. En consultas evaluate se obtiene una evaluacion actual, no evidencia de una escritura.

No se agrega boton de descartar, desbloqueo por temporizador, nueva clave tras conflicto, ni supuesto endpoint para verificar resultados. No existe en estas pantallas un contrato general de consulta por clave que certifique exito o ausencia definitiva y permita liberar automaticamente un intento incierto. Ese mecanismo, y un desbloqueo ligado a evidencia verificable, quedan pendientes de un contrato backend autorizado. La comprobacion manual no desbloquea automaticamente esta instancia de la interfaz.

## Recarga y limites de validacion

El snapshot vive exclusivamente en memoria del componente. Una recarga, cierre o salida no bloqueada que destruya el componente/documento lo pierde; despues se podria crear otra clave para una operacion ya ejecutada. La interfaz lo advierte expresamente. No se guarda payload ni clave en localStorage, sessionStorage u otro almacen persistente. La proteccion entre recargas sigue pendiente; no debe considerarse resuelta ni sustituirse por persistencia improvisada de informacion financiera. Antes de iniciar otra operacion despues de perder ese estado se debe verificar el resultado anterior.

Las pruebas Node ejecutan el helper y los handlers reales con dobles de Vue/Inertia/axios. No prueban SQL, MongoDB, locks ni comportamiento backend; la evidencia de deduplicacion arriba es inspeccion de codigo, no una prueba de integracion. Compilacion/montaje Vue pendientes mientras este worktree no disponga de node_modules; no se usa ni modifica la instalacion del proyecto principal.

Comandos de validacion: node --test tests/Frontend/IdempotentAttempt.test.mjs; git diff --check.


## Correccion de abandono y navegacion (revision Agente 5)

### Superficies inspeccionadas

- Adjustments.vue: Link a financial.dashboard y formulario selectWallet -> router.get(financial.adjustments.index, wallet_id). Cambiar los tabs locales y consultar historial via Axios no desmontan la pagina.
- Controls.vue: Link a financial.dashboard. Tabs, filtros y paginacion consultan por Axios; permanecen en el mismo componente y mantienen el snapshot.
- AuthenticatedLayout.vue (solo lectura): logo, menu, perfil y logout usan Link/NavLink/DropdownLink de Inertia. El guard global de la pagina alcanza esas visitas sin modificar el layout.
- La recarga Inertia posterior al exito ocurre cuando el intento ya esta resuelto; debe seguir permitida.

### Mecanismo y alcance

Al pasar pending a true se instalan los listeners con watch flush: sync, antes de invocar el transporte financiero. Se bloquea tambien durante la solicitud en curso, ya que salir entonces podria perder una respuesta exitosa. Se cancelan las visitas cliente Inertia que ya estuvieran en vuelo mediante router.cancelAll (API presente en la version 2.3.27 del lockfile). Esa cancelacion no detiene ni revierte trabajo del servidor, ni cancela el Axios financiero. Una respuesta cliente ya aplicada o una sustitucion de componente ya en proceso no quedan garantizadas por este mecanismo.

| Salida | Comportamiento implementado | Garantia comprobada / limite |
| --- | --- | --- |
| Link y visitas router de Inertia, incluido logout | router.on('before') llama preventDefault; muestra advertencia en el dialogo pendiente, que se reabre si estaba cerrado. | En las visitas que emiten before se cancela la visita, sin generar una clave nueva ni descartar payload. No se ofrece continuar de todos modos. |
| Cambio de wallet | Usa el mismo before global. | No se ejecuta la visita Inertia ni cambia el contexto por esta via mientras pending sea true. |
| Recarga/cierre/navegacion nativa | beforeunload llama preventDefault y asigna returnValue para soporte anterior. | Solo solicita al navegador su aviso generico. La aplicacion no controla el texto, su aparicion ni la decision del usuario. |
| Navegacion nativa, incluido historial, con Navigation API | Se escucha window.navigation.navigate y se cancela solo si event.cancelable es true. | Cobertura condicionada a disponibilidad y cancelabilidad; no se afirma que todas las travesias sean cancelables. No se cambia el historial ni se inserta una entrada artificial. |
| Atras/Adelante sin Navigation API o evento no cancelable | No se intenta cancelar popstate. Si Vue llega a desmontar la pagina pendiente, onBeforeUnmount solicita un alert explicito de perdida y verificacion. | Es una notificacion adicional, NO un bloqueo: alert puede suprimirse y no impide el desmontaje. Esta via sigue pudiendo perder estado. |
| Cierre de dialogo | Permanece el banner visible en la pagina y existe Volver al intento pendiente. | No libera ni cancela la solicitud; la siguiente visita Inertia bloqueada reabre el mismo intento. |
| Exito o primer 422 definitivo | Se retiran listeners y se limpia la advertencia de salida. | Permite continuar el flujo habitual y evita listeners de beforeunload permanentes. Los 409/503 y 422 posteriores a incertidumbre mantienen la proteccion. |

Los listeners tambien se retiran al desmontar el componente, sin resetear el helper. No se utiliza un mensaje de confirmacion cuya aceptacion libere el intento. El banner existe mientras hay pending, incluso con el dialogo cerrado, y explica las limitaciones del navegador.

### Evidencia y pendientes

Las 66 pruebas aisladas mantienen los 51 casos anteriores y agregan 15 casos de navegacion: visitas a destinos de pagina y layout, cambio de wallet, proteccion en vuelo, registro/retirada de listeners, aviso de unload, navegacion nativa cancelable, ausencia de Navigation API y evento no cancelable/desmontaje. Ejecutan los scripts reales de las paginas con dobles de reactividad, router y eventos del navegador. La prueba de cancelAll comprueba que se solicita la cancelacion; no simula las carreras internas de Inertia ni acredita cancelacion del trabajo servidor.

No son pruebas E2E ni pruebas de visualizacion de un aviso nativo. Siguen pendientes compilacion/montaje Vue y validacion en navegadores reales (Chrome/Edge/Firefox/Safari, movil, boton de recarga/cierre, Atras/Adelante, navegacion desde historial y visitas ya en curso). No hay node_modules ni Vite local en este worktree; no se modifica ni usa la instalacion principal.

Riesgo residual pendiente de resolver mediante un contrato de recuperacion autorizado: un navegador puede no emitir beforeunload (p. ej. cierre del proceso en movil), suprimir alert, permitir la salida, carecer de Navigation API o presentar eventos no cancelables. El frontend no garantiza que el documento y el snapshot sobrevivan. Tras una perdida real, se debe verificar el resultado antes de otra operacion. No hay endpoint general de recuperacion por clave y no se inventa uno. No se guardan datos financieros en localStorage, sessionStorage ni history.state. No hay temporizadores de descarte, replay automatico ni cambios en los contratos financieros/identidad.

Referencias de compatibilidad consultadas:

- [Eventos Inertia v2: before cancelable y limite de popstate](https://inertiajs.com/docs/v2/advanced/events).
- [Router Inertia v2.3.27: cancelAll y despacho de before](https://raw.githubusercontent.com/inertiajs/inertia/v2.3.27/packages/core/src/router.ts).
- [MDN beforeunload: activacion previa, aviso generico y entrega no fiable](https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event).
- [MDN Navigation.navigate](https://developer.mozilla.org/en-US/docs/Web/API/Navigation/navigate_event).
