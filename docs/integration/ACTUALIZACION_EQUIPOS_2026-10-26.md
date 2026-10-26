# Actualización selectiva del 26 de octubre de 2026

Copia alterna `/home/daniel/campus_digital_integracion_e1`, rama
`MERGE-1-3-4-5-6`. Base publicada `f609bd3`. Se conserva el proyecto principal.
Revisión contra referencias descargadas el 26 de octubre, America/Mexico_City.

## Fuentes contrastadas

| Equipo | Commit | Resultado |
|---|---|---|
| E1 | `86111548a7659ffa3dab4fd6ee441bb70160b3d9` | Sin novedades publicadas. Directorio y autorización institucional externa pendientes. |
| E2 | `bad1bf3ec87ddba036aa4c2cebae15426b7740c0` | Cinco commits nuevos. Evaluación real en SQL Server aislado, sin instalar Finanzas en la aplicación integrada. |
| E3 | `bf9fc2c8b77d5fe168191acf32b8acd72986331d` | Formularios por negocio y validación/auditoría de catálogo adaptados. |
| E4 | `fae5f574afaaf1ceec81a8475238c8b1aa05652f` | Proveedores, catálogo y costos adaptados; controles de webhook existentes preservados. |
| E5 | `ea7f0dc` | Sin novedades publicadas. |
| E7 | `e48031ee28a53ebfd60162ba84f727cb4edd6e0c` | Receptor evaluado en aislamiento. Conexión pendiente de compatibilidad y autenticación. |

No es una nueva auditoría porcentual de submódulos. No se hicieron merges de
aplicaciones completas ni se copiaron sus Docker, autenticación o roles legacy.

## Inventarios E4 incorporado

- Proveedores conserva productos surtidos por SKU y contactos adicionales; ambos
  se recuperan al editar. Las referencias se validan en el negocio seleccionado,
  con arrays acotados y elementos del tipo esperado.
- Compras recibe el catálogo por proveedor y filtra las opciones de su formulario.
  Un proveedor sin catálogo conserva la selección completa, como plantea E4.
  Este filtro es ayuda de captura, no una autorización financiera ni una regla
  nueva que invalide retrospectivamente órdenes de compra.
- Categorías permite indicar tallas, colores y temporadas; productos y souvenirs
  guardan y recuperan esos atributos. Son metadatos descriptivos: no crean IDs de
  variantes ni existencias por combinación. Se conservan los IDs de inventario.
- Costos muestra historiales de productos eliminados con paginación independiente
  de 25, búsqueda literal y aislamiento por negocio. Las nuevas capturas conservan
  SKU/nombre de origen; los registros anteriores sin esos datos siguen consultables
  por su product_id, sin inventar una descripción histórica.
- No se agrupan historiales por SKU: si se elimina un producto y otro reutiliza
  el SKU, sus costos permanecen separados por ID. Se conservan costo promedio,
  precio sugerido y la lista de productos sin costos de nuestra integración.
- El registro de costos sigue exigiendo un producto existente del negocio. No se
  adopta la escritura libre de costos para un SKU inexistente de la fuente.
- El panel muestra los mensajes de éxito/error ya proporcionados por el servidor.
  El observador del proveedor se registra después de crear el formulario.

R1–R6 del receptor E4 ya estaban resueltos en nuestra adaptación del 17 de octubre:
identidad única, conflicto de contenido, versiones previas a persistir efectos,
refund único, variante exacta y transacción MongoDB para todos los movimientos.
Se conserva el worker recuperable; no se reemplaza por la compensación manual
parcial de la nueva fuente. Las diez pruebas específicas del receptor siguen
incluidas en la regresión. Pagos y salida E7 permanecen apagados.
No se importa el contador duplicado de movimientos del dashboard ni un comando
que modifique categorías del negocio de demostración durante esta actualización.

## Marketplace E3 incorporado

- Configuración y tienda usan formularios independientes por ID de negocio.
  Cargar o editar una tarjeta no modifica el borrador de otra; los objetos anidados
  se copian al cargar, sin mutar las props originales.
- La edición del producto valida moneda MXN y registra el cambio activo/inactivo
  con actor real en la auditoría comercial.
- Se conserva MarketplaceLayout, autorización E1 por API, vínculos reales E4,
  importes enteros, generaciones/claves y recuperación de reservas existentes.
  Varias correcciones de catálogo/ObjectId de E3 ya estaban cubiertas localmente.
- No se restaura el editor local de roles ni los mocks de pagos/inventario. No se
  habilita aprobación manual de SPEI/Openpay ni una devolución financiera desde
  un adaptador simulado. El cambio remoto no prueba un pago E2 integrado.

## Verificación

- RED catálogo E4: cuatro casos fallaron antes de aplicar el incremento; GREEN
  cuatro casos, 46 aserciones. Dos casos adicionales de costos históricos se
  reprodujeron antes de su implementación y luego pasaron (56 aserciones).
- Regresión E4/E3: 101 pruebas, 816 aserciones, 53.54 s, seis archivos.
- RED moneda E3: USD se aceptaba durante la actualización; después se rechaza.
  Regresión E3 posterior: 34 pruebas, 230 aserciones, 11.20 s.
- Los bloques anteriores se solapan. XML cotejados: **102 casos únicos**,
  822 aserciones tomando el resultado más reciente de cada caso. No se suman
  como 135 pruebas ni se presenta el conjunto como una única ejecución.
- Vite final correcto en 9.08 s. Pint y revisión de diff sin espacios finales.
- Edge aislado con HTML/bundles reales y props controladas: separación de dos
  negocios E3, edición de atributos, contactos/productos surtidos, filtro de
  compras y costos eliminados; cinco comprobaciones sin errores JavaScript.
  Esta prueba de interfaz no ejecuta escrituras del servidor ni prueba identidad.
- Inicio de sesión normal de la cuenta documentada `identidad@campus.test`:
  Inventarios redirige al enrolamiento de segundo factor existente. No se alteró
  MFA ni se declara validada la navegación autenticada completa de E4.
- La revisión automática rechazó una inspección de hashes de contraseñas para
  seleccionar cuenta de prueba. No se ejecutó ni se intentó evadir; se utilizó
  el login normal y las comprobaciones de interfaz controladas descritas arriba.

Pruebas integradas seriales, como 1000:1000 y solo en `campus_virtual_testing`.
Sin migraciones nuevas, reseed ni cambios de credenciales/roles/OAuth. Las
[pruebas E2](EVALUACION_E2_SQL_2026-10-26.md) y la
[evaluación E7](EVALUACION_RECEPTOR_E7_2026-10-26.md) usaron otro laboratorio.

## Evidencia, recuperación y siguiente paso

Logs/XML locales: `storage/logs/actualizacion-20261026/` (ignorados por Git).
Capturas y scripts UI: carpeta de visualizaciones de este chat,
`actualizacion-ui-fixtures.cjs` y `actualizacion-ui-fixtures.json`.
Snapshot anterior: `antes-actualizacion-20261026.tar`, SHA256
`19d9ccca3514fe89ee91b6787753f5d578f0493d754239fcc6200c5f10f22bdb`.
Incluye código versionado de la base, sin `.env`, datos ni dependencias.

El incremento queda en commits locales. No se publica automáticamente. El
siguiente paso financiero es adaptar Identidad de E2 al contrato E1 y cerrar
receptor/contrapartida, vigencias y autoridad; aprobar pruebas conjuntas antes
de activar una configuración. E7 necesita resolver sus dos incompatibilidades
y un contrato de autenticación de servicio por origen. La respuesta institucional
E1/E2/E6 y el directorio solicitado a E1 siguen siendo acuerdos separados.
