# Documento Integrador — Marketplace: negocios, tiendas, catálogo, pedidos y postventa (E3)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 3 (Marketplace) (autores en Git: Daniel Beltrán, Codex, Julian-Darkstar); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
Registro y autorización de negocios, constructor de tienda, catálogo de productos y servicios, carrito y pedidos con estados, checkout orquestado con Finanzas, panel del vendedor con reporte de ventas y postventa. Las capacidades por negocio provienen de E1; las existencias, de E4.

## 2. Arquitectura y datos
Laravel + MongoDB (conexión marketplace). Pantallas Inertia en /tienda/*; API de catálogo en /api/v1/marketplace/*. Integraciones por OAuth (E1, E6), firma HMAC (E4) y API de cobro de E2.
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E3 Marketplace). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E3.md` (10 compromisos; 9 implementados; 9 con pruebas automatizadas en verde).

## 4. Integraciones verificadas
- E1: membresía, capacidades y alta del propietario por negocio (MarketplaceIntegrationTest; laboratorio: aprobación real)
- E4: registro del negocio y reservas firmadas (MarketplaceIntegrationTest, laboratorio)
- E2: cobro con wallet y recuperación ante respuestas perdidas (MarketplaceWalletCheckoutTest, PurchaseMarketplaceEventsTest)
- E6: cargos vigentes para tiendas de asociaciones (MarketplaceIntegrationTest)

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 55 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Revisión institucional sólo con rol admin; acciones de negocio sólo con capacidad vigente en E1 (consulta por solicitud, sin propiedad almacenada); fallo controlado 503 si E1/E4 no responden; idempotencia de pedidos; cancelación y postventa sólo del comprador.

## 7. Limitaciones conocidas
- Ningún otro módulo consume todavía la API de catálogo de E3 (/api/v1/marketplace/catalog).
- La confirmación de pagos directos (SPEI/Openpay) y la gestión de personal dependen de integraciones aún no disponibles (503 explícito).

## 8. Referencias
Evidencia de interfaz en evidencias/interfaz/estados-e3 (solicitud, aprobación, catálogo, pedido, panel y CSV).
