# Documento Integrador — Finanzas: wallets, ledger, recargas, retiros, bonos, transferencias, retenciones, caja, comprobantes y controles (E2)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 2 (autores en Git: Daniel Beltrán, Codex, Daniel Beltran, Viko43, Julian-Darkstar); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
E2 custodia el dinero digital del campus: wallets y ledger de doble registro en centavos, recargas, retiros, bonos BECA y saldos restringidos, transferencias, retenciones, devoluciones y reversos, caja de asociaciones, comprobantes y controles (límites, alertas, conciliación). Expone checkout a E3/E5/E6 y avisos a E6.

## 2. Arquitectura y datos
SQL Server (59 migraciones, 63 tablas, 76 FK) para el núcleo contable; transacciones con bloqueo y claves de idempotencia; clientes OAuth para los consumidores; avisos a E6 con outbox reintentable.
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E2 Finanzas). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E2.md` (11 compromisos; 11 implementados).

## 4. Integraciones verificadas
- E6: checkout de eventos, devolución y conciliación (D02)
- E6: avisos transaccionales con recuperación tras 503 (F05)
- E1: autorización financiera, designaciones y TOTP
- E5: cobros de servicios (PR#8)
- E3: checkout de Marketplace

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 311 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Autorizadores financieros Pending* por defecto, banderas de cobro y transferencias apagadas, revisor ≠ solicitante, TOTP reciente, idempotencia obligatoria.

## 7. Limitaciones conocidas
- Sin pruebas propias en este repositorio para recargas (2.3), retiros (2.4) y límites (2.10).
- Fechas: los movimientos del ledger se serializan como hora local y los comprobantes en UTC (observación de presentación).
- Transferencias personales apagadas por defecto.

## 8. Referencias
docs/modulo-2/, docs/integration/API_E2_COBROS_SERVICIOS.md, API_E2_MULTAS_BIBLIOTECA.md, CONTRATO_PAGO_EVENTOS_E2_E6.md, TRAZABILIDAD-E2.md
