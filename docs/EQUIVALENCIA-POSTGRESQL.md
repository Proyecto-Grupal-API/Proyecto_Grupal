# Equivalencia de persistencia frente a PostgreSQL (rúbrica 7.x)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipos 1–7 (decisión de arquitectura integrada); redactado con Claude Code | Primera versión con cifras del esquema efectivo del laboratorio aislado |

## Decisión vigente (no se migra de motor)
La rúbrica asume PostgreSQL. La aplicación integrada usa **MongoDB** (réplica) para E1, E3–E7 y **SQL Server** para el núcleo contable de E2 (`database/migrations/financial`). La decisión es anterior a este corte y está en el diseño de módulos (`docs/Campus_Digital_Diseno_Modulos.pdf`) y en el contexto de entrega (E2 en SQL Server por transacciones/ledger). **No se migran motores para mejorar la nota**: este documento explica cómo se cubre cada exigencia.

## Cifras del esquema efectivo (laboratorio cv-cierre-20261112, migraciones ejecutadas desde cero)
- SQL Server: **63 tablas, 270 índices, 76 claves foráneas**, creadas por las 59 migraciones financieras en orden, sin SQL manual (`evidencias/laboratorio/esquema.json`).
- MongoDB: **87 colecciones** con índices creados por migraciones Laravel (`database/migrations/*.php`).

## Correspondencia de criterios
| Criterio | Exigencia | Cómo se cumple aquí | Evidencia |
|---|---|---|---|
| 7.1 | Tablas en singular | Convención Laravel en plural (`wallets`, `role_assignments`), uniforme en ambos motores. **No se cumple literalmente**; renombrar rompería contratos e historial. | `DICCIONARIO-DE-DATOS.md` |
| 7.2 | Migraciones sin SQL manual | 59 migraciones SQL + migraciones Mongo ejecutadas desde cero en un laboratorio limpio, sin scripts manuales | `esquema.json` |
| 7.3 | PK, FK e índices | SQL: 76 FK y 270 índices (únicos para idempotencia, p. ej. `idempotency_key`). Mongo: `_id` ObjectId, índices únicos (matrícula, claves de idempotencia, `event_id`) | `esquema.json`, migraciones |
| 7.4 | Tipos adecuados | Dinero en **centavos enteros** (`bigInteger`), UUID públicos, fechas UTC con milisegundos; en Mongo tipos BSON (ObjectId, UTCDateTime, Decimal/enteros) | `DICCIONARIO-DE-DATOS.md` |
| 7.5–7.6 | Integridad y relaciones entre módulos | Dentro de E2 por FK; entre módulos **por contrato** (UUID públicos y ObjectId canónicos), nunca por consultas cruzadas | contratos `docs/integration` |
| 7.7 | Sin datos críticos quemados | Secretos y clientes sólo por entorno; `.env.example` sin valores | `.env.example` |

## Limitación declarada
Si el docente exige PostgreSQL literal, 7.1/7.4 deben evaluarse como «No aplica justificado» o «Cumple parcialmente», según su criterio; este documento no pretende convertirlos en «Cumple».
