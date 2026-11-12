# Contratos verificables de Comunidad

Corte base: `1b648b7`, 11 de noviembre de 2026. Los fragmentos describen el código existente; no modifican rutas, permisos, fondos ni despliegues.

`runtime-routes.json` es la salida de `php artisan route:list --json --path=api/v1/comunidad`, obtenida por lectura en el contenedor integrado como usuario sail. Incluye middleware, acciones y métodos; HEAD implícito no se cuenta como otra operación. No contiene credenciales. Si cambian las rutas debe regenerarse ese inventario desde el entorno correcto y revisarse el diff.

Cada `*.openapi.fragment.json` contiene paths y componentes propios de un bloque. El catálogo agregado conserva explícitamente `x-contract-status`: `reviewed` para los esquemas revisados y cualquier otro valor para pendientes. La presencia de una ruta no significa contrato completo ni aceptación de negocio.

## Construcción y validación offline

Requiere Python 3 y la biblioteca `jsonschema` con soporte Draft 2020-12 y `referencing`. No se agregan dependencias a Laravel ni se ejecuta TestCase.

```bash
python3 scripts/build-community-openapi.py
python3 scripts/validate-community-openapi.py
```

El constructor incorpora solo rutas presentes en el snapshot y rechaza colisiones entre componentes de distintos fragmentos. El validador comprueba referencias locales, esquemas JSON, IDs únicos, parámetros de path, cobertura exacta de rutas y respuestas HTTP saneadas guardadas de los ensayos anteriores. No hace nuevas llamadas HTTP ni accede a bases de datos.

Las respuestas históricas limpiadas que no tienen una operación revisada o cuerpo JSON aplicable se contabilizan como omitidas, nunca como aprobadas. Una divergencia debe revisarse en el controlador/serializador y su evidencia; no ensanchar un esquema para ocultar un fallo. Los campos que `.only()` puede omitir en documentos antiguos se declaran opcionales de forma explícita. Los cuerpos de entrada Laravel pueden aceptar/ignorar campos no declarados: no se afirma rechazo de extras si no lo exige la implementación.

Las respuestas 422 de `abort()` pueden contener solo `message`; las de validación incluyen además `errors`. El contrato debe admitir ambas formas cuando corresponda. El portal utiliza sesión web y CSRF en escrituras, no token OAuth para suplantar al humano. Las APIs de servicio mantienen contratos y scopes independientes.

## Alcance de la evidencia

La validación offline demuestra coherencia de documentación e inventario y compatibilidad con los DTO guardados. No acredita un recorrido nuevo BECA, disponibilidad del directorio E1 o NFC físico, ni reemplaza la ejecución protegida de pruebas funcionales. El avance funcional y la rúbrica se mantienen separados.
