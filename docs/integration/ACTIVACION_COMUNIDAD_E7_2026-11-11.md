# Activación local de Comunidad ↔ Recompensas

Destino único: contenedor `campus_integracion_e1-app-1`, aplicación integrada de `MERGE_COMPLETO` en http://127.0.0.1:8086. La copia original de Campus Digital no forma parte de esta activación.

## Alcance preparado y probado en el laboratorio

1. Crear los índices únicos y de entrega de `platform_community_notices` en la conexión `platform` y de `community_analytics_deliveries` en MongoDB de Comunidad. Se ejecutan únicamente las dos migraciones del 11 de noviembre; no hay reset, fresh ni eliminación de datos.
2. Crear, o validar y reutilizar, un cliente OAuth exclusivo de Recompensas con `notifications:read` y `notifications:send`. Debe ser diferente del cliente financiero. Las credenciales se guardan únicamente en `.env`, con permisos 0600, y no se imprimen ni se suben a Git.
3. Activar `PLATFORM_COMMUNITY_NOTICES_ENABLED` y `PLATFORM_COMMUNITY_ANALYTICS_ENABLED`. Establecer sus cortes `SINCE` al instante UTC de activación, con destino HTTP interno `http://app`. Se reutiliza el transporte firmado de E7 que ya existe. No se selecciona el histórico anterior al corte.
4. Ejecutar una revisión inicial y verificar las tareas de cada minuto. Los reintentos usan las mismas claves y comprueban recibos; no vuelven a otorgar puntos ni recursos.

Los avisos notifican puntos/canjes que E7 ya registró. La analítica publica únicamente reportes vigentes/retirados y totales de consultas cerradas. No exporta padrones, respuestas, nombres o expedientes. La revocación de beneficios E5 solo se solicita mediante la acción expresa de Presidencia; activar estas tareas no cancela beneficios por sí mismo.

## Evidencia y verificación

En bases aisladas, con usuarios nuevos, el recorrido HTTP/OAuth/HMAC verificó entrega, recuperación, repetición sin duplicados, consumo de 30 páginas y liberación de 70 restantes, liberación de un locker, encuestas/votaciones y retirada de un reporte. El detalle se conserva en `evidence/comunidad-90-2026-11-11/`.

El script preparado `/tmp/campus-90-20261111/activate-main.php` comprueba el entorno local y las conexiones actuales, conserva el resto de `.env`, compara cantidades de usuarios, roles, beneficios, reportes, movimientos y saldo de puntos antes/después. No concede roles humanos, modifica fondos ni habilita emisión de BECA. El build de Vite se instala conservando los archivos anteriores y sustituyendo el manifest de forma atómica.

## Estado de autorización

Autorizada explícitamente por el usuario: «Sí, activar Comunidad↔E7 en el integrado». Ejecutada el 11 de noviembre a las 10:25:08 UTC. La revisión automática anterior se resolvió con esa autorización, sin eludirla. Ambas migraciones y tareas terminaron correctamente. El primer escaneo seleccionó y envió cero registros históricos. La comparación antes/después conservó usuarios, asignaciones, reportes, beneficios, movimientos y puntos. El comprobante saneado está en `evidence/comunidad-90-2026-11-11/activacion-local.json`.

## Desactivación

Poner ambas banderas en `false` y recargar los procesos que mantengan configuración en memoria. Conservar outboxes, recibos y mensajes. No ejecutar los métodos `down` sobre datos existentes para desactivar el envío. El cliente OAuth puede deshabilitarse mediante el mecanismo oficial de E1 si se desea suspender también la recepción.
