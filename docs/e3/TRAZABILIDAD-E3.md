# Trazabilidad E3 — compromisos → código → pruebas → commits

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 3 (Marketplace) (autores en Git: Daniel Beltrán, Codex, Julian-Darkstar); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Fuentes de requisitos: docs/Campus_Digital_Diseno_Modulos.pdf (apartados 3.1–3.10 del módulo 3) y contratos con E1 (identidad por negocio), E2 (cobro con wallet), E4 (reservas de inventario) y E6 (comercio de asociaciones). Resultados: suite Feature completa (perfil integrated-financial) y Unit. Commits: git log real.

| Req. | Compromiso | Fuente | Estado | Pruebas (resultado) | Evidencia | Commits | Primer → último |
|---|---|---|---|---|---|---|---|
| 3.1 | Registro y clasificación de negocios | Diseño 3.1 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) | estados-e3/01–04 | 4 | 21e26a2 → e4bb97e |
| 3.2 | Flujo de autorización de negocios | Diseño 3.2 | Implementado y probado | MarketplaceFlowTest, MarketplaceIntegrationTest (37 OK / 0 fallos) | Aprobación real con E1 y E4 (estados-e3/05) | 4 | 21e26a2 → e4bb97e |
| 3.3 | Constructor de tienda virtual | Diseño 3.3 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) |  | 4 | 21e26a2 → e4bb97e |
| 3.4 | Catálogo de productos y servicios | Diseño 3.4 | Implementado y probado | MarketplaceFlowTest, MarketplaceIntegrationTest (37 OK / 0 fallos) | Servicio visible en catálogo público (estados-e3/06) | 5 | 21e26a2 → e4bb97e |
| 3.5 | Carrito, pedido y estados | Diseño 3.5 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) | Pedido en awaiting_payment (estados-e3/07) | 3 | 21e26a2 → 68b776e |
| 3.6 | Checkout y orquestación de pago | Diseño 3.6 | Implementado y probado | MarketplaceWalletCheckoutTest, MarketplaceCheckoutCancellationTest, PurchaseMarketplaceEventsTest (18 OK / 0 fallos) |  | 3 | 21e26a2 → 68b776e |
| 3.7 | Configuración SPEI directa | Diseño 3.7 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) | Comprobante registrado; confirmación pendiente de Finanzas (503 explícito) | 4 | 21e26a2 → e4bb97e |
| 3.8 | Adaptador Openpay | Diseño 3.8 | Pendiente: sin adaptador de Openpay (requiere credenciales sandbox del proveedor) |  (0 OK / 0 fallos) | Sólo perfil configurable; sin adaptador de proveedor en este corte | 4 | 21e26a2 → e4bb97e |
| 3.9 | Panel del vendedor y reporte de ventas | Diseño 3.9 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) | Filtros y CSV (estados-e3/08–09) | 4 | 21e26a2 → e4bb97e |
| 3.10 | Postventa comercial | Diseño 3.10 | Implementado y probado | MarketplaceFlowTest (3 OK / 0 fallos) |  | 4 | 21e26a2 → e4bb97e |

Código por compromiso: columna `codigo` del CSV.
Nota: 3.8 (Openpay) no tiene adaptador implementado: se declara como pendiente y no se acredita.
