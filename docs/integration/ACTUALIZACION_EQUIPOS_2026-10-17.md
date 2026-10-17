# Revisión e incorporación de avances de los equipos

Revisión del **17 de octubre de 2026**, America/Mexico_City. Se ejecutó
`git fetch DaniBG04 --prune` contra `Proyecto-Grupal-API/Proyecto_Grupal` y se
compararon los commits con los orígenes ya revisados/importados. No se hicieron
push ni cambios en `/home/daniel/campus_digital`.

## Resultado de la revisión remota

| Equipo | Rama y commit más reciente | Resultado frente a nuestra integración |
|---|---|---|
| E1 | `feat/modulo-1-equipo-1` · `8611154` · 7 de octubre | Sin novedades. Su corrección de consentimientos ya está adaptada localmente. |
| E2 | `feat/modulo-2-equipo-2` · `39de155` · 12 de octubre | Sin novedades respecto al corte revisado. No se instala Finanzas ni SQL Server. |
| E3 | `equipo3_negocios` · `b9534b9` · 12 de octubre | Sin novedades; se conservan sus adaptadores reales de E1/E4/E6. |
| E4 | `Modulo_4` · `250c558` · 16 de octubre, 21:45 | Dos commits nuevos desde `1dec1fc`: `adbe336` y `250c558`, 31 archivos. Se adaptan los cambios de dominio descritos abajo. |
| E5 | `Equipo-5` · `ea7f0dc` · 13 de octubre | Sin novedades; el árbol coincide con el origen de Servicios ya integrado. |
| E6 | Publicación `MERGE-1-3-4-5-6` · `84133a7` | Al iniciar había once commits locales posteriores; se conservan permisos, comisiones, documentos, periodos y reservas. |
| E7 | `Equipo-7` · `71d49b9` · 29 de septiembre | Sin novedades; no aparece un receptor central nuevo que permita activar el transporte. |

Se revisaron también `Equipo-2`, `Modulo6_Comunidad`, `main` y `Testings`;
no son versiones posteriores a las fuentes anteriores. El fetch muestra cambios
respecto a refs locales antiguas, pero eso no implica novedades frente al código
importado. Los porcentajes del informe del 15 de octubre conservan su corte:
esta revisión de cambios no constituye otra auditoría porcentual de los 74 submódulos.

## Cambios de E4 incorporados

### Correcciones y auditoría local

- Fechas BSON en los filtros de recepciones/kardex y en los plazos restantes de
  idempotencia. La mayoría de conversiones de escrituras ya se había corregido
  al integrar E4 el 14 de octubre y se conserva.
- Fechas ISO en la consulta de reservas E3 y E7. Antes devolvían JSON interno
  de BSON. No se cambia el vencimiento ni la reserva de Marketplace.
- Colección `cost_histories` explícita en el modelo con la propiedad `table`
  del driver actual. El flujo de registro/referencia de costos pasó antes y
  después; no se atribuye una reparación funcional inexistente a ese ajuste.
- Observador registrado solo en los modelos `InventoryModule`, con actor real
  y negocio del documento. Las altas retienen el ObjectId generado; se registra
  alta/cambio/baja con antes/después. No observa modelos de Identidad ni consulta
  otras colecciones de dominio.
- La auditoría conserva el carácter de mejor esfuerzo del `AuditService`
  existente. No equivale a auditoría financiera certificada ni a entrega a E7.
  Las escrituras con driver nativo siguen usando sus registros explícitos;
  un observador Eloquent no intercepta todas las escrituras raw.

El observador publicado por E4 no estaba registrado y llamaba a parámetros que
su servicio no acepta. Se adaptó a la firma y al aislamiento de esta aplicación;
no se copió literalmente.

### Receptor financiero v2 preparado, sin activación

Se mantiene `POST /api/equipo4/webhooks/payment-status`, HMAC y negocio autorizado.
Requiere `event_id`, `event_version`, `payment_intent_id`, `order_id`, `business_id`,
`status`, `amount`, `currency` y `occurred_at`. Un reembolso requiere `refund_id`;
`items` identifica una devolución física. `amount` conserva el delta declarado;
este receptor no calcula, cobra ni devuelve dinero.

- Una identidad de evento con el mismo contenido validado devuelve el mismo
  recibo `202`, sin repetir efectos. Otro contenido con esa identidad da `409`.
- Se compara la versión por negocio/intención de pago. Una versión antigua o
  repetida da `409` y no se persiste. La intención conserva pedido y moneda.
- Índices únicos para evento, versión y refund. Un refund no se aplica otra vez
  bajo otra identidad de evento. Los índices parciales preservan recibos heredados.
- La aceptación `202` confirma persistencia; una devolución queda `PENDING`.
  `inventario:procesar-devoluciones` la procesa después. Usa bloqueo por pago,
  transacción MongoDB y sesión explícita también en operaciones del driver nativo.
- Producto, ubicación y variante deben existir en ese negocio. No hay upsert.
  Si falla una partida o el movimiento, se revierten todas las partidas; queda
  `FAILED` con reintento tras cinco minutos. `PROCESSED` no vuelve a modificar stock.
- E3 consulta la versión aceptada mayor dentro de negocio/pedido; un `PAID`
  antiguo o de otro negocio no sustituye un reembolso vigente.

La implementación recibida ejecutaba la devolución dentro del request y no
aportaba el índice único anunciado. Esta adaptación separa la recepción de su
procesamiento y comprueba conflictos del contenido completo.

**`INVENTARIO_PAGOS_HABILITADOS` continúa falso en esta laptop.** No se han
conectado emisores E2, fondos, permisos financieros, pagos tardíos, ventas pagadas
ni devoluciones reales. Antes de activar deben acordarse y probarse la autoridad,
el emisor, su firma, la correlación de la venta y la evidencia de devolución
física. Registrar un callback no verifica por sí solo un cobro bancario.

### Transporte E7 preparado, sin receptor conectado

Se adapta el comando de origen `team4:publish-outbox` para la colección privada
de Inventarios y el tema `inventory.reservation.expired`:

- Lotes de hasta cien eventos, plazo HTTP y redirecciones deshabilitadas.
- Identidad estable en `X-Event-Id`, tema/versión y negocio en el payload.
- Claim persistido con vigencia para evitar entregas simultáneas; recuperación
  tras interrupción. Las respuestas perdidas pueden repetir la entrega con la
  misma identidad: el receptor debe deduplicarla.
- `SENT` solo tras HTTP 2xx; errores quedan `FAILED`, con espera de cinco minutos.
  No se marca entrega ante un fallo de red. Eventos heredados sin negocio se
  conservan pendientes de conciliación; no se les inventa un ámbito.

La activación requiere `INVENTARIO_E7_OUTBOX_HABILITADO=true`,
`TEAM7_WEBHOOK_URL` e `INVENTARIO_E7_TOKEN`. El formato y la autenticación deben
acordarse con el receptor real antes de configurarlos. **El indicador continúa
falso; no se generaron credenciales ni se contactó un servicio E7.** No se cambia
el emisor de E1 ni se afirma entrega exactamente una vez. La generación del evento
de expiración y la liberación de stock conservan el límite transaccional anterior;
su conciliación conjunta sigue siendo trabajo para la integración operativa E7.

Los comandos nuevos están en el scheduler cada minuto con exclusión de
solapamiento, y salen sin efectos cuando su integración está apagada.

## Preparación y verificación

La migración aditiva `2026_10_17_000200_inventory_payment_events_v2.php` se aplicó
solo al alterno: cuatro índices, sin reseed, con cero eventos financieros y ambos
indicadores apagados. No cambia roles, OAuth, bases E1/E5/E6 ni infraestructura.

- RED observado en fechas BSON expuestas, falta de auditoría registrada, ausencia
  de validaciones v2/procesador y comando de salida todavía inexistente.
- Regresión E4/E3: **95 pruebas aprobadas, 714 aserciones**, 42.51 s.
  Incluye fallo real de validación de MongoDB al insertar un movimiento y
  reversión del stock, variantes, versiones, firmas, reintentos y claims vencidos.
- `npm run build`: correcto, **8.50 s**. Pint en archivos nuevos: correcto.
- HTTP real local, sin `Http::fake`: siete lecturas E3 con firma válida responden
  `200`; callback E2 responde `503`, con cero eventos financieros nuevos.
- Edge aislado y login normal: la cuenta administradora exige completar
  `/security/two-factor-enrollment`; no se desactivó ni modificó 2FA. No se declara
  verificación visual completa de Inventarios con esa sesión. No cambiaron las vistas.

La suite completa y el cotejo de inventario quedan pendientes de cierre.
Evidencia local excluida de Git: `storage/logs/integracion-e4-20261017/`.
Respaldo de código previo: `antes-integracion.tar`, en la carpeta de visualizaciones
de este chat, 1091 entradas, SHA256
`539b1f6f01fb2b45d8cf30d54e9999fc96a3f18898e495c0bc8b9ab4e053c98a`.
La base de recuperación es `be5f7a7`; no se incluyen secretos ni bases de datos.

No se importó el comando de relleno `team4:seed-alerts`, que inserta referencias
ficticias y conteos incompletos. Tampoco se reemplazaron Docker, autenticación,
actor real, permisos o el puente multinegocio por la configuración standalone.
El título del commit menciona Souvenirs/Proveedores, pero no agrega vistas nuevas
ni una especialización funcional completa: no se contabiliza como tal.
