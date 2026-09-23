# Entrega 3: Becas y apoyos

## Alcance y permisos

Flujo local de los apartados 6.4 y 6.5 del documento del proyecto. MongoDB almacena convocatorias, solicitudes, dictámenes y asignaciones; Storage guarda los archivos en privado. La entrega real de dinero, bonos o servicios sigue pendiente de los equipos 2 y 5.

En `/modulo6/becas` cualquier usuario autenticado puede explorar apoyos y consultar Mis solicitudes. La presidencia vigente administra exclusivamente su organización. Esta política provisional debe adaptarse al comité de becas y al contrato de roles del equipo 1. Los requisitos académicos se revisan manualmente, sin inventar datos de elegibilidad.

## Convocatorias y solicitudes

- Crear borrador con condiciones, beneficio, monto/unidades por persona, número de beneficiarios, fechas y hasta cinco documentos obligatorios.
- Tipos disponibles con IDs reales de MongoDB: apoyo monetario, bono restringido, comidas, impresiones, transporte y locker.
- Dinero en centavos enteros, sin operaciones de coma flotante. Servicios con cantidad y monto cero.
- Fechas de Ciudad de México. El último día de recepción/vigencia se incluye completo; en MongoDB el final es el inicio del día siguiente, exclusivo, en UTC. La vigencia empieza después del último día de recepción.
- Solo los borradores admiten cambios. Publicar fija condiciones para todos los solicitantes.
- La recepción termina al vencer el plazo o al cerrarla anticipadamente con confirmación. El cierre anticipado es definitivo.
- Una solicitud por estudiante y convocatoria, con folio; puede guardar texto y documentos antes de enviarla. El envío bloquea su edición y permite la revisión por responsables.
- Los borradores no participan en dictámenes ni consumen cupo. Siguen privados aunque se retiren o se cancele la convocatoria.
- Se puede retirar antes del cierre y de la revisión. La retirada es definitiva para esa convocatoria y conserva historial; la interfaz lo advierte antes de confirmar.
- Cancelar convocatoria requiere motivo; no se permite si hay aprobaciones que necesitan revocación coordinada.

## Documentos privados

PDF/JPG/PNG, máximo 5 MB cada uno y cinco por solicitud. Cada archivo obligatorio debe asociarse a su requisito; uno adicional no satisface un requisito obligatorio. Se pueden eliminar y sustituir archivos mientras la solicitud está en borrador.

El disco `becas` usa `storage/app/private/becas`, sin enlace público. Las descargas verifican al solicitante o a la presidencia de la organización, se fuerzan como adjunto con `private, no-store` y generan auditoría. Las rutas físicas nunca se entregan en JSON. Los responsables solo reciben acceso después del envío. La validación de formato y tamaño no realiza análisis antivirus.

## Dictamen y asignación

Después del cierre se puede marcar En revisión, Aprobar o Rechazar, con motivo y evidencia escrita. Nadie puede dictaminar su propia solicitud. Aprobaciones y rechazos son definitivos en esta entrega. Los cambios quedan en `auditoria_comunidad`, sin copiar archivos ni el texto personal de motivación.

Aprobar consume uno de los espacios disponibles, contado desde las solicitudes aprobadas. Todas las escrituras de una convocatoria usan el mismo bloqueo. No se aprueban beneficios vencidos ni solicitudes de cuentas eliminadas. Finalizar dictámenes requiere resolver todas las solicitudes enviadas; no significa que el beneficio externo ya se entregó.

Cada aprobación prepara una asignación única con estado `pendiente_integracion`. Repetir el mismo dictamen no duplica el beneficio. El estudiante ve «Apoyo aprobado · entrega pendiente»: no se acredita dinero, no se emiten bonos ni se asignan lockers ficticios.

## Contrato propuesto para equipos 2 y 5

El responsable puede consultar `GET /api/becas/solicitudes/{id}/contrato`. Requiere sesión y autorización; no es un webhook público.

| Campo | Uso |
| --- | --- |
| `version` | 1 |
| `clave_idempotencia` | `beca:{solicitud_id}`, estable ante reintentos |
| `equipo_destino` | 2: dinero/bonos; 5: servicios |
| `beneficiario_id`, `organizacion_id`, `convocatoria_id`, `solicitud_id`, `folio` | Referencias de origen |
| `tipo`, `monto_centavos`, `moneda`, `cantidad` | Beneficio aprobado; MXN cuando aplique |
| `vigencia_inicio`, `vigencia_fin_exclusiva` | ISO 8601, final exclusivo |
| `reglas` | Condiciones textuales de la convocatoria |

Falta acordar adaptadores, autenticación entre dominios, restricciones estructuradas de bonos, disponibilidad, respuesta externa, reintentos de entrega, revocaciones y conciliación. No existe botón para marcar manualmente un beneficio como entregado. Preparar el contrato local no equivale a probar la integración con otro equipo.

## Persistencia y límites

Índices únicos por convocatoria/usuario, asignación/solicitud y slug de tipo. Se conserva la base existente. Los bloqueos por archivos corresponden a una instancia de Laravel; varias instancias necesitan un almacén compartido.

Solicitud, archivo, auditoría y asignación no comparten una transacción en este MongoDB standalone. La aprobación es la fuente del cupo; si falla la preparación del contrato, repetir el mismo dictamen repara la asignación sin consumir otro lugar. Antes de producción se necesitan transacciones/outbox y conciliación según la arquitectura acordada.

## Preparación y demostración

Ejecutar desde `/home/daniel/campus_digital` en WSL:

```bash
docker compose up -d --no-build
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed
docker compose exec --user sail laravel.test php artisan db:seed --class=BecaSeeder
docker compose exec --user sail laravel.test npm ci
docker compose exec --user sail laravel.test npm run build
```

El seeder opcional y repetible crea un bono de alimentación de $800 del Consejo y 100 impresiones de Sistemas, con dos beneficiarios cada uno. No reinicia fechas ni cambios manuales. Crear nuevas convocatorias para demostraciones posteriores.

Todas las cuentas de demostración usan `CampusDemo2026!`:

1. Como `estudiante@campus.test` o `andrea@campus.test`, iniciar solicitud, escribir motivo, adjuntar documentos de prueba y enviar.
2. Como `consejo@campus.test` para el bono o `presidencia@campus.test` para impresiones, cerrar recepción y abrir Revisar solicitudes.
3. Revisar expediente/documentos y registrar dictamen con motivo.
4. Volver a Mis solicitudes como estudiante y comprobar el dictamen y la entrega pendiente.
5. Completar cupo, comprobar rechazo de otra aprobación, resolver solicitudes restantes y finalizar dictámenes.

Si Docker inició desde la copia antigua del Escritorio, ejecutar `docker compose up -d --no-build --force-recreate laravel.test` desde WSL. El contenedor debe montar `/home/daniel/campus_digital` en `/var/www/html`.

## Verificación

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas usan solo `campus_digital_testing` y un disco falso de archivos. No ejecutarlas en paralelo contra la misma base. Cubren permisos, documentos, privacidad antes/después del envío, fechas, requisitos, cupo, autoaprobación, retiro, cancelación, contratos, seed repetible e idempotencia.

### Verificación realizada

- Suite completa: 70 pruebas aprobadas, más comprobación de privacidad de borradores en el dashboard.
- Compilación de producción con Vite aprobada.
- Recorrido en Edge: crear/publicar convocatoria, iniciar borrador privado, adjuntar PDF, enviar, descargar con autorización, dictaminar y consultar resultado.
- Intento de descargar el documento desde otra cuenta rechazado.
- Dos aprobaciones simultáneas para un cupo: una aceptada y otra rechazada. Contrato de servicio preparado con entrega pendiente.
- Vista de solicitud comprobada a 390 px de ancho, sin desbordamiento; sin errores JavaScript no controlados.

El recorrido deja convocatorias «Prueba Becas» y «Prueba cupo Becas» con solicitudes y un PDF de prueba. Las dos convocatorias del seeder quedan disponibles para otra demostración.

## Próximo bloque

Comunicación implementada en [Entrega 4](Entrega_4_Comunicacion.md). Encuestas y votaciones: [Entrega 5](Entrega_5_Encuestas_Votaciones.md). Reportes y dashboard: [Entrega 6](Entrega_6_Transparencia_Dashboard.md). Continúan pendientes las integraciones reales con identidad, pagos, servicios y auditoría global.
