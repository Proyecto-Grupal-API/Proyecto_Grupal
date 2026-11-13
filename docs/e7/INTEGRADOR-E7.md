# Documento Integrador — Programa de Recompensas, auditoría, analítica e integración (E7)

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 7 (Recompensas y Plataforma) (autores en Git: Daniel Beltrán, Julian-Darkstar, Codex); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Estado: **vigente para la evaluación del tercer parcial**.

## 1. Propósito y alcance
Ledger de puntos por estudiante, adhesión de negocios, reglas de acumulación y campañas, catálogo, canje y entrega con código, gobierno (políticas, fraude, ajustes), auditoría transversal, dashboards por rol, ingesta firmada de eventos de E4/E5/E6 y configuración versionada. Identidad y roles provienen de E1 (admin/maestro/estudiante); E7 no guarda credenciales propias.

## 2. Arquitectura y datos
Laravel + MongoDB (conexión platform). Rutas web e Inertia en /recompensas/{alumno,profesor,admin}; API de sesión en /api/v1/recompensas/*; ingesta entre módulos en /api/v1/plataforma/{eventos,auditoria} con firma HMAC por dominio. Permisos por rol en config/platform_permissions.php validados en servidor (EnsurePlatformPermission).
Diccionario: `../DICCIONARIO-DE-DATOS.md` (sección E7 / plataforma). Equivalencia de motores: `../EQUIVALENCIA-POSTGRESQL.md`.

## 3. Requisitos vigentes y trazabilidad
`TRAZABILIDAD-E7.md` (11 compromisos; 11 implementados; 11 con pruebas automatizadas en verde).

## 4. Integraciones verificadas
- E1: identidad y rol efectivo (ResolvePlatformActor) sin leer colecciones de usuarios
- E4: eventos de inventario firmados → analítica (stage-inventory-e7, 6/6)
- E5 y E6: auditoría y analítica firmadas (F02, 12 casos)
- E2/E3: acumulación desde ventas (POST /points/earn con dominio de origen)

## 5. Calidad y pruebas
Pruebas Feature del módulo en la suite completa: 90 casos en verde (detalle por compromiso en la trazabilidad).

## 6. Seguridad y configuración
Permisos por rol en servidor; el alumno sólo ve y cancela sus canjes; ajustes manuales y políticas sólo con governance.manage/points.adjust; la aprobación de configuraciones exige settings.approve (rol platform_admin, sin mapeo desde E1 en este corte); secretos de ingesta por dominio sólo por entorno.

## 7. Limitaciones conocidas
- La aprobación de versiones de configuración (7.11) requiere el rol platform_admin, que no tiene mapeo desde los roles de E1: un admin puede redactar y proponer, no aprobar (separación de funciones; queda pendiente de decisión del equipo).
- lifetime_redeemed no se descuenta al cancelar un canje (el reverso se registra como movimiento propio); el saldo sí se restituye.
- Validaciones del backend devueltas en español desde este corte (lang/es).

## 8. Referencias
Contratos en docs/integration; evidencia de laboratorio en evidencias/laboratorio/stage-e7-flujo.json, stage-e7-coherencia.json y evidencias/interfaz/estados-e7.
