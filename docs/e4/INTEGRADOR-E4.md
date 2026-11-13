# Documento Integrador — Inventarios multi-negocio, abastecimiento y proveedores (E4)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 4 (Inventarios) (autores en Git: Daniel Beltrán, Codex); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
Existencias por negocio y ubicación, almacenes, kardex, proveedores, órdenes de compra con autorización, recepciones parciales y devoluciones, costos de referencia, reservas para checkout, alertas de reabastecimiento, conteos y ajustes, y operación de souvenirs (preventas). Autorización por negocio delegada a E1.

## 2. Arquitectura y datos
Laravel + MongoDB (conexión inventory). Pantallas Inertia en /equipo4/*; API firmada HMAC para E3/E2/E7 en /api/equipo4/* y /api/v1/inventory/*; outbox hacia E7. Contexto de negocio verificado contra E1 en cada solicitud (EnsureTeam4Role + IdentityService).
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E4 Inventario). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E4.md` (12 compromisos; 12 implementados; 12 con pruebas automatizadas en verde).

## 4. Integraciones verificadas
- E1: membresía y capacidades por negocio vía /api/v1/identity/* con OAuth (InventoryIntegrationTest)
- E3: reservas y disponibilidad firmadas (MarketplaceIntegrationTest)
- E2: eventos de pago y devoluciones (InventoryPaymentEventsTest)
- E7: outbox firmado de eventos de inventario (InventoryE7ContractTest, stage-inventory-e7 6/6)

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 88 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Fallo cerrado: sin negocio habilitado 404, identidad caída 503 sin cambios de stock, firma HMAC por consumidor y ruta, actor de movimientos ligado a la sesión, revocación revisada en la siguiente solicitud. El secreto literal histórico de la rama Modulo_4 no está en MERGE_COMPLETO; su rotación está documentada en SEGURIDAD-SECRETO-E4.md.

## 7. Limitaciones conocidas
- Rotación del secreto TEAM_SERVICE_SHARED_SECRET pendiente del propietario (rama Modulo_4 pública).
- Las pantallas dependen de la API de identidad de E1; si no responde, se muestra la página de acceso recuperable.

## 8. Referencias
Evidencia de interfaz en evidencias/interfaz/estados-e4; contrato con E7 en evidencias/laboratorio/stage-inventory-e7.json.
