# Entorno de integración de Campus Digital

La rama de entrega grupal es `MERGE_COMPLETO`. En este equipo la copia de trabajo está en `/home/daniel/campus_digital_integracion_e1` y el Compose integrado publica Laravel en `http://127.0.0.1:8086`. Identidad y los módulos no financieros usan MongoDB. Finanzas usa SQL Server y expone sus operaciones a los otros módulos mediante sus contratos.

## Origen de los avances

| Origen local | Resultado de la consolidación |
| --- | --- |
| Comunidad principal, puerto 80 | Su commit `055af14` es ancestro del merge. La copia de desarrollo de Comunidad queda disponible para trabajo en su propia rama. |
| Integración general, puerto 8086 | Fuente de entrega. Aquí están el directorio de Comunidad, el catálogo de Souvenirs, el piloto de tienda con wallet y los cambios de devoluciones y garantías. |
| Demo BECA, puerto 8087 | El ciclo BECA y su enlace con Comunidad están en el merge desde `2cca14e`. El proveedor de autoridad sintética, los accesos y los presupuestos de la demo son fixtures de prueba. |
| Laboratorio anterior de Finanzas | Entorno de evaluación de E2 en SQL Server. El merge ya incorpora la evolución de su checkout, comprobantes y ciclo BECA. El laboratorio aporta evidencia de prueba. |
| Copia de revisión de Inventarios | Se incorporaron las adaptaciones de catálogo para colecciones, paquetes, preventa y producto oficial. Las extensiones de aprobación de conteos y correo requieren adaptar sus controladores, permisos y configuración al módulo con namespaces. |

La integración conserva las migraciones y los defaults seguros. Las bases, credenciales, sesiones, saldos y existencias de los pilotos se administran en el entorno que los creó. El historial de Git contiene código y configuración de ejemplo.

## Preparación reproducible

1. Partir de `MERGE_COMPLETO` y usar `compose.integracion.yaml`. Revisar la configuración local a partir de `.env.example`. Mantener la clave existente al actualizar una instalación.
2. Instalar dependencias con los locks del merge. Ejecutar Composer con `composer install` y Node con `npm ci` en el runtime adecuado. Validar que PHP tenga MongoDB, PDO SQL Server y las extensiones de generación de comprobantes. El [diagnóstico de E2](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/d0829737968c7711cb4329d5df2d0bbd00286f9c) describe su rama de Windows. El merge usa su propio lock y contenedores.
3. Confirmar la réplica y las bases efectivas de MongoDB y SQL Server antes de aplicar migraciones. En una instancia con datos, revisar el historial de migraciones y disponer de respaldo antes de cambiar esquemas. Las migraciones de Finanzas están en `database/migrations/financial`.
4. Compilar la interfaz con `npm run build`. Verificar `/login`, las rutas de los módulos y el arranque del scheduler y del worker de Comunidad. Para comprobar tablas y mutexes de Finanzas usar `php artisan financial:operations-check` desde el contenedor del merge.
5. Configurar cada piloto con sus clientes, cuentas, ámbitos y permisos aprobados. La guía de [BECA](../modulo-2/2.5-piloto-beca-e2-e6.md), el [contrato de Eventos](CONTRATO_PAGO_EVENTOS_E2_E6.md) y el [piloto de garantías](PILOTO_GARANTIAS_RENTA_E5_E2.md) detallan sus requisitos.

## Alcance de los pilotos

- Tienda tiene un piloto local con un comprador y un negocio. El pago requiere consentimiento del titular y un evento firmado de Finanzas antes de confirmar inventario.
- Eventos y multas conservan sus contratos y recuperación por referencia. Su habilitación depende de las cuentas receptoras y clientes de cada entorno.
- Los roles de revisión, ejecución y cargos de mostrador conservan la guarda de asignaciones sintéticas. La activación operativa requiere una autoridad acordada.
- Para BECA, Presidencia aprueba el dictamen y Tesorería valida el presupuesto por solicitud. El aval se registra por solicitud. Para habilitar el piloto con usuarios operativos quedan las designaciones de emisión y presupuesto.
