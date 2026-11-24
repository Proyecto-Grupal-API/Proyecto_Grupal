# Adenda documental — validación funcional E1–E7 (rama `validacion-funcional-e1-e7`)

| Versión | Fecha | Responsable | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-15 | Auditoría integral E1–E7; con Claude Code | Registro de los cambios de la rama local sobre `MERGE_COMPLETO` `0812221`. Rama no publicada |

Esta adenda completa los documentos integradores de cada módulo (`docs/eN/`) sin reescribirlos. La referencia de endpoints (`API-REFERENCIA.md` 1.1) se regeneró desde las rutas.

## Autorización: cargos de módulo por contexto de servicio (E1 1.3)

Identidad ya permite asignar un rol en un contexto (`scope_type`, `scope_id`). Los módulos reconocen el rol `admin` asignado en el contexto `service` que les corresponde, además del administrador global. La comprobación es `User::hasRole(rol, tipo, id)`: tupla exacta y asignación vigente. El contexto no rebaja la exigencia de segundo factor.

| Módulo | Contexto `service` | Cargo que otorga | Dónde se decide |
|---|---|---|---|
| E7 Recompensas | `recompensas` | administración del programa | `config/platform.php` (`context_role_map`), `ResolvePlatformActor` |
| E7 Recompensas | `plataforma` | administración de plataforma (aprueba configuraciones) | igual |
| E7 Recompensas | `auditoria` | auditoría de sólo lectura | igual |
| E5 Servicios | `servicios-estudiante` | personal de Servicios | `config/integracion_servicios.php` (`contexto_personal`), `ServiceAuthority` |
| E3 Tienda | `tienda` | revisión institucional de solicitudes | `config/marketplace.php` (`reviewer_context`), `ResolveMarketplaceReviewer` |
| E2 Finanzas | los que declare la instalación | controles financieros web | `FINANCIAL_CONTROL_VIEW_ROLES`, `FINANCIAL_CONTROL_MANAGE_ROLES` (`rol` o `rol@tipo:id`); `ConfiguredFinancialWebAuthorizer` |

`App\Support\Authorization\ServiceStaff::manages()` es el punto único de la comprobación para E5 y E3. En Finanzas, sin configuración se deniega a todos; el adaptador sólo cubre límites, alertas y conciliaciones.

Identidad: el rol `buyer` recibe la capacidad `business.purchasing.operate` (compras y abastecimiento).

## E4 Inventarios

| Cambio | Ruta o pantalla |
|---|---|
| Transferencia entre ubicaciones, con salida y entrada ligadas en el kardex | `POST /equipo4/inventario/transfer` (permiso `inventario.transfer`) |
| Consulta de auditoría del negocio | `/equipo4/auditoria` (permiso `auditoria.view`, propietario) |
| La recepción entra en la ubicación elegida | campo `location_id` en `POST /equipo4/recepciones` |
| El conteo ajusta la ubicación contada; lo no capturado no se toca | `StockCountController` |
| Una alerta descartada queda silenciada hasta que la existencia se recupere o la situación empeore | `AlertGeneratorService` |
| Auditoría de órdenes de compra, conteos, reservas, transferencias y descartes | colección `audit_events` |

## E7 Recompensas y plataforma

| Cambio | Detalle |
|---|---|
| Acumulación por venta y reverso por devolución (diseño §15) | Tienda encola un aviso por venta confirmada y por devolución aprobada (`marketplace_rewards_outbox`) y lo entrega firmado a `POST /api/v1/plataforma/eventos` (dominio `marketplace`, eventos `marketplace.order.confirmed.v1` y `marketplace.order.refunded.v1`). Orden `marketplace:publicar-recompensas`; bandera `MARKETPLACE_REWARDS_ENABLED` |
| Bandeja de eventos y reintento | `webhook_events` (campos nuevos `detail`, `reference`); `POST /api/v1/recompensas/integrations/webhooks/{id}/retry` reprocesa de verdad |
| Salud de integraciones | `GET /api/v1/recompensas/integrations/health` |
| Gobierno | `GET /api/v1/recompensas/governance/summary`; las políticas activas aplican tope diario de puntos y máximo de canjes por día |
| Exportaciones | `GET /api/v1/recompensas/points/ledger/export`, `GET /api/v1/recompensas/audit/logs/export` (CSV) |
| Configuración | una versión requiere una segunda persona con autoridad para aprobarse |
| Motor de puntos | los abonos saldan primero el saldo pendiente (`PointsAccount::credit`); sólo alumnos registrados acumulan o reciben ajustes |

## Datos

| Cambio | Detalle |
|---|---|
| Colecciones reales de tres modelos | `PointsLedger` → `points_ledgers`, `ServiceClient` → `service_clients`, `AcademicStatusHistory` → `academic_status_histories` (el controlador de MongoDB ignora `$collection`; los datos siempre estuvieron ahí). El diccionario de datos 1.0 lista los nombres declarados antes |
| Migración `2026_11_15_000200_align_indexes_with_collections_in_use` | Sólo hacia delante: índice único de `idempotency_key` y de `client_id`, e índices de consulta, en las colecciones con datos. Se detiene sin tocar nada si hay claves repetidas |
| Migración `2026_11_15_000100_create_marketplace_rewards_outbox_index` | Índice de la bandeja de avisos de Tienda |

## E5 Servicios, E6 Comunidad y E2 Finanzas

| Cambio | Detalle |
|---|---|
| E5 · Impresiones | control «Marcar lista» (`PATCH …/servicios-impresiones/{id}/listo`) |
| E5 · Soporte | atención del ticket desde el detalle (`PATCH …/soporte/{id}/asignar`, `…/estado`) |
| E2 · Controles | `ConfiguredFinancialWebAuthorizer` sustituye al autorizador que negaba a todos |

## Instalador y demostración

| Cambio | Detalle |
|---|---|
| Contraseña de demostración | Fuera del repositorio: `CAMPUS_DEMO_PASSWORD` en un archivo privado; `campus:install --demo --env-file` la genera si falta |
| Configuración de laboratorio | Clientes OAuth, banderas y receptores para los cobros de Servicios (E5→E2) y de eventos (E6→E2), los avisos de Finanzas (E2→E6) y los eventos hacia Recompensas |
| Cuentas | 31 cuentas `demo.*`. De ellas, la única administradora global es `demo.e1` |
| Cuentas de la demostración anterior | `campus:install --demo` sigue creando las siete cuentas de los sembradores anteriores (`presidencia@`, `estudiante@`, `consejo@`, `andrea@`, `staff@`, `gestion@` e `identidad@campus.test`). **`identidad@campus.test` es administradora global de Identidad** (así la publicó el sembrador de integración). Su contraseña estaba escrita en cuatro sembradores, seis documentos y una prueba; ahora la toman del mismo archivo privado que las cuentas `demo.*` (`Database\Seeders\Demo\DemoPassword`) y sin esa fuente los sembradores se niegan antes de escribir nada |
| Contraseña anterior | El valor antiguo sigue en la historia ya publicada y debe considerarse conocido: no usarlo en ninguna instalación. Una instalación creada antes de este cambio conserva en esas siete cuentas la contraseña antigua (los sembradores nunca reescriben la de una cuenta existente): cambiarla desde el perfil o reinstalar sobre una base nueva |
| `StudentServicesSeeder` | No lo ejecuta el instalador. Ahora sólo corre en local/testing y toma la contraseña del archivo privado (antes usaba una palabra genérica y no comprobaba el entorno) |
| `php artisan db:seed` | Requiere `CAMPUS_DEMO_PASSWORD` (12 caracteres o más) en el entorno; sin ella se niega con un mensaje que lo indica |

## Pruebas añadidas

`InventoryBuyerRoleTest`, `InventoryOperationsTest`, `RewardsAdministrationTest`, `RewardsCommerceIntegrationTest`, `CollectionAlignmentTest`, `ServiceContextStaffTest`, `FinancialControlWebAuthorizationTest`; casos nuevos en `DemoTeamAccountsTest`.
