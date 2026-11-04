# Incorporación selectiva del avance E4 del 30 de octubre

Destino: `/home/daniel/campus_digital_integracion_e1`, rama `MERGE_COMPLETO`.
Base previa local: `373ce0c`. Fuente: `DaniBG04/Modulo_4` en
`a3ea96d702b4c5a46012be1634dd65c784426c97`.
El proyecto principal y las ramas ajenas no se modificaron. No se realizó merge/rebase de la rama de E4.

## Comparación comprobada

El intervalo `fae5f57..a3ea96d` contiene cinco commits y diez archivos cambiados.
`a3ea96d` por sí solo elimina un bloque duplicado y una llave sobrante del controlador E3;
el avance comunicado incluye también sus cuatro commits anteriores.

| Elemento comunicado | Evidencia y decisión |
|---|---|
| HttpEventDispatcher | Nuevo en la fuente. Adaptado a InventoryModule, configuración privada, firma de la ruta real, ID y fecha persistentes, límites de tiempo y acuse verificable. |
| OutboxService::dispatchPending | La fuente usa `$this->dispatcher` sin declararlo/inyectarlo y llama `limit()` sobre el cursor de MongoDB. Se reutilizó el publicador existente con claim, reintentos y recuperación en vez de agregar un segundo worker. |
| E7ExpireCommand | No cambió en ese intervalo. No aparece el dispatch inmediato anunciado. Se conserva el expirador de todos los negocios y el publicador independiente programado cada minuto. |
| Team7WebhookController | Incorporado como inbox firmado, con índice único y conflicto de contenido; registra RECEIVED. El original marcaba PROCESSED después de solo escribir logs. |
| API Marketplace | Ya estaban sus operaciones y rutas con ámbito por negocio. Se conservan. No se aplican las nuevas rutas duplicadas que pisarían al contrato E7. |
| Prefijo MANUAL- | No incorporado: inserta un PAID de importe cero antes de validar la operación. El merge conserva el requisito de evidencia financiera E2 y añade regresiones explícitas. |
| Proveedores/Compras | Ya incorporados en la entrega anterior: supplied_products persistido y recuperado, filtro por SKU del proveedor y fallback si no tiene catálogo. No hay cambios adicionales de esos archivos en el intervalo recibido. |
| Bootstrap/CSRF | No se copia el bootstrap de otro equipo. Las rutas de integración usan el grupo API existente, conservando sesiones y CSRF de las vistas de E1. |

Contrato local completo: [equipo4/eq7.md](equipo4/eq7.md).
Las modificaciones de transporte no prueban el flujo completo de canjes y compensación.
Los productores de cinco tipos de eventos siguen pendientes en la fuente, igual que la recuperación
atómica liberación/outbox. Los avisos recibidos no ejecutan operaciones financieras ni puntos.

## Validación

Se añadieron pruebas que primero fallaron al faltar los endpoints/contrato, y después verifican
firma, reloj, privacidad anidada, duplicados idénticos, conflicto de contenido, origen, negocio,
ID/fecha estables, acuses incorrectos y recepción sin puntos ni movimientos.
Se comprueba además que MANUAL- no confirme un checkout y que las rutas E7 no se sobrescriban.
La batería se ejecuta en serie contra `campus_virtual_testing`, con HTTP simulado hacia el kernel
real del receptor y protección contra peticiones externas. No se presenta como una activación HTTP real.

Resultado: **85 pruebas aprobadas, 676 aserciones, 0 fallos**, en 50.98 segundos.
Batería: los seis archivos `Inventory*Test.php`, `PlatformIngestionIntegrationTest.php`
y `CommunityPlatformDeliveryTest.php`. Formato: Pint aprobado en los ocho archivos PHP revisados.
No se volvió a ejecutar la regresión completa de los siete equipos: esta validación cubre las rutas afectadas.

Comprobación HTTP del alterno: `/login` devuelve 200; ambos receptores nuevos devuelven 401
sin credenciales. Lectura de configuración: outbox E4 e inbox E4 apagados, sin secreto nuevo de
emisión/recepción; ingesta E7 y entrega E6 conservan true, con 71 auditorías de Comunidad y cero
eventos analíticos de inventario recibidos. Se mantuvo intacto el checkout principal, incluidos
sus archivos previos sin seguimiento en `docs/solicitudes/`.

## Despliegue y publicación

No se encendieron emisores nuevos ni se cambiaron roles, scopes, fondos o credenciales privadas.
El código se prepara localmente en MERGE_COMPLETO; publicarlo requiere el push correspondiente.
El contrato distingue los ajustes propios del merge de las afirmaciones de la fuente E4.
