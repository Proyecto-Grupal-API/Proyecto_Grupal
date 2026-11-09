# Configuración de E2

Referencia: base `9c1c91a`, archivos `config/database.php` y `config/financial*.php`. No reemplazar `.env` en un proyecto existente. Los ejemplos no contienen credenciales y requieren completar valores locales por un canal seguro. El diagnóstico no lee ni imprime su contenido: solo comprueba que exista.

## Conexiones

| Variable | Uso |
|---|---|
| `APP_ENV` | `local` para desarrollo; `testing` solo para pruebas aisladas |
| `APP_KEY` | Clave propia de la instalación; conservar en proyectos existentes |
| `APP_URL` | URL de esta instancia Laravel |
| `DB_CONNECTION` | `mongodb` en la configuración actual |
| `DB_DATABASE` | Base MongoDB: desarrollo separado de `campus_virtual_testing` |
| `MONGODB_DSN` | Conexión MongoDB; transacciones requieren una topología compatible, por ejemplo el replica set local `rs0` |
| `DB_USERNAME` y `DB_PASSWORD` | Credenciales MongoDB si corresponden |
| `SQLSRV_HOST` y `SQLSRV_PORT` | Servidor y puerto SQL Server |
| `SQLSRV_DATABASE` | Base financiera de desarrollo, separada de `campus_digital_financial_testing` |
| `SQLSRV_USERNAME` y `SQLSRV_PASSWORD` | Cuenta SQL con permisos acordados |
| `SQLSRV_ENCRYPT` | Cifrado de la conexión |
| `SQLSRV_TRUST_SERVER_CERTIFICATE` | Confianza en certificado; revisar para cada entorno, no trasladar una excepción local a producción |
| `DB_URL` y `SQLSRV_URL` | URLs opcionales que pueden sustituir opciones individuales; revisarlas antes de afirmar qué base se utiliza |

No incluir contraseñas en comandos que se vayan a compartir, capturas, logs o archivos versionados. Un nombre correcto de base en un archivo no acredita la conexión efectiva.

## Integraciones y automatismos

Mantener desactivadas las funciones que aún no tengan configuración y autorización válidas. Los siguientes valores son ejemplos de desactivación, no instrucciones para sobrescribir una integración existente aprobada:

```dotenv
FINANCIAL_IDENTITY_AUTHORIZATION_ENABLED=false
FINANCIAL_TEAM4_WEBHOOK_ENABLED=false
FINANCIAL_MARKETPLACE_ENABLED=false
FINANCIAL_MARKETPLACE_INSTITUTIONAL_BECA_ENABLED=false
FINANCIAL_INSTITUTIONAL_BECA_EXPIRATION_ENABLED=false
FINANCIAL_RECONCILIATION_SCHEDULE_ENABLED=false
FINANCIAL_HOLD_SCHEDULE_EXPIRATION=false
```

| Grupo | Variables y condiciones |
|---|---|
| E1 | `FINANCIAL_IDENTITY_AUTHORIZATION_ORIGIN`, `..._CLIENT_ID`, `..._CLIENT_SECRET`. Consulta autoridad, no otorga permisos automáticamente. |
| E4 | `FINANCIAL_TEAM4_WEBHOOK_URL`, `..._SECRET`. Habilitar solo tras probar receptor y acuse con datos válidos; revisar eventos locales pendientes antes de enviar. |
| Marketplace | `FINANCIAL_MARKETPLACE_CLIENTS_JSON`: mapa de clientes OAuth y negocios admitidos. Un token no autoriza cualquier negocio. `..._TTL_SECONDS` y `..._PASSWORD_MAX_AGE_SECONDS` controlan vigencias; revisar el contrato existente. |
| BECA automática | `FINANCIAL_INSTITUTIONAL_BECA_EXPIRATION_ACTOR`: actor válido y facultad correspondiente, además de la bandera de programación. |
| Conciliación | Horario, calendario, zona, actor, caché compartida, cola y worker según `config/financial.php` y `docs/modulo-2/operacion-conciliacion-programada.md`. Una bandera sola no basta. |
| Consultas y administración | Los adaptadores que deniegan por defecto no se desbloquean agregando una variable o un rol llamado admin. Mantener los contratos de autorización vigentes. |

Los nombres abreviados con `...` en la tabla comparten el prefijo del grupo; no son nombres literales para copiar en `.env`. Consultar el archivo de configuración correspondiente para opciones y valores actuales.

## Configuración cacheada

`bootstrap/cache/config.php` puede hacer que Laravel use valores diferentes a los editados en `.env`. El diagnóstico solo informa su presencia. Cambiar configuración y borrar/regenerar caché son acciones distintas de la lectura; realizarlas expresamente según el entorno y coordinar procesos persistentes cuando corresponda.

No cambiar la configuración de E1, E3 u otro módulo desde esta herramienta. Verificar configuración no equivale a habilitar facultades ni a certificar una integración real.
