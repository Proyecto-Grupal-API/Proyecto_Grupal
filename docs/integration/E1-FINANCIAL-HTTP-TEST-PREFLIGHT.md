# Preflight pendiente: HTTP financiero E1

> **Estado documental (2026-11-12): Histórico.** Ver `docs/CONTROL-DOCUMENTAL.md`; el estado vigente está en `docs/e1/E1-DOCUMENTO-INTEGRADOR.md`.

Esta preparación NO autoriza ejecutar la suite. No se ha acreditado ni aprovisionado
la infraestructura descrita. El phpunit.xml del merge fuerza la URI del Compose habitual. La guarda debe
bloquear ese destino antes del bootstrap. Este documento cubre la suite financiera HTTP.

## Perfil exclusivo propuesto, no existente acreditado

- Base y authSource: `campus_virtual_testing` (sin cambio).
- DNS privado: `campus-e1-test-mongo:27018`.
- Replica set de un miembro: `rs-e1-testing`.
- Usuario: `campus_e1_test`; único rol: `campus_e1_testing` en esa base.
- Acciones exactas: las enumeradas en `E1MongoTestTarget::ACTIONS`, exclusivamente
  sobre esa base y todas sus colecciones. No roles globales ni credenciales de
  desarrollo, integración, administración o migración.

Los identificadores son un perfil de seguridad nuevo y cerrado, NO una afirmación
de que existan esos recursos. Su adopción/aprovisionamiento requiere autorización
independiente. No renombrar ni reutilizar la infraestructura permanente integrada.

## Antes de solicitar ejecución

1. Acreditar procedencia y aislamiento del servidor, red, volumen y credenciales,
   incluido rechazo de acceso a otras bases. DNS y `hello` no son prueba
   criptográfica de propiedad del servidor: se requiere verificar la infraestructura.
2. Acreditar runtime compatible, replica set PRIMARY, autenticación y privilegios.
   Las guardas realizarán solamente `hello` y `connectionStatus` antes de limpiar;
   si falta información, permisos o conexión, bloquearán sin revelar errores del driver.
3. Preparar, con autorización, una ejecución efímera con código de E1 en solo
   lectura; sin `.env` ni `.env.testing` del proyecto; sin caché de configuración;
   sin sockets ni secretos administrativos; escrituras de logs/cachés/reportes
   limitadas a almacenamiento privado de testing. No usar el clon integrado.
4. Usar configuración PHPUnit específica que NO cargue las variables forzadas
   del phpunit.xml original. Proporcionar APP_ENV=testing, E1_TEST_MONGO_ENABLE=1,
   DB_CONNECTION=mongodb, DB_DATABASE=campus_virtual_testing, DB_HOST con el DNS
   autorizado, DB_PORT=27018 y MONGODB_DSN con usuario exclusivo, ruta, authSource
   y replicaSet del perfil. El secreto se inyectará por un canal privado, nunca en
   argumentos visibles ni informes. No DB_URL, DB_USERNAME/DB_PASSWORD alternativos
   ni APP_CONFIG_CACHE. CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync.
5. No cargar providers, conexiones secundarias, callbacks o scripts ajenos al
   candidato revisado. Queue sync no deshabilita jobs: revisar posibles despachos.
6. Registrar hashes de candidato, dependencias y configuración no sensible.
   Confirmar ausencia de otros escritores y exclusividad de la base de E1.
7. Solicitar autorización explícita para ejecutar únicamente
   `tests/Feature/FinancialIdentityApiTest.php`, sin paralelismo. El comando final
   depende del runtime/montajes/configuración aprobados; no está habilitado aquí.

## Efectos conservados

La suite elimina la base completa antes de cada caso, crea índices, ejecuta el
seeder de autorización y escribe fixtures sintéticos y auditoría. Usa transacciones.
No se cambió a limpieza parcial: sin probar equivalencia podría dejar mappings,
auditoría o índices entre casos. FinancialRoleGrantService conserva su restricción
literal a campus_virtual_testing y su autoridad sintética exclusivamente de testing.

Las guardas se ejecutan antes del bootstrap (control estático), después de este y
de los callbacks, antes de limpieza del esquema y en la entrada de fixtures
sintéticos. No constituyen un sandbox universal: código nuevo que escriba fuera
de esos puntos requiere revisión. Las comprobaciones unitarias usan arrays y URI
sintéticas, no acreditan infraestructura real ni ejecutan la suite HTTP.

Detener ante cualquier divergencia, intento de acceso externo, guarda fallida,
privilegio adicional, conexión secundaria, error de bootstrap o escritura imprevista.
No corregir automáticamente permisos, destinos ni datos; no rollback ni limpieza extra.
