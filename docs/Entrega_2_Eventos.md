# Entrega 2: Eventos y boletos

## Flujo disponible

En `/modulo6/eventos`, cualquier usuario autenticado puede explorar eventos publicados de organizaciones activas, inscribirse y consultar sus reservas. No necesita pertenecer a la organización que publica. La presidencia vigente puede alternar entre el catálogo y la administración de su organización.

La administración permite crear borradores, editar, publicar, cancelar con motivo, consultar inscritos y registrar asistencia. Solo puede administrar eventos propios. Las fechas se introducen y muestran en horario de Ciudad de México; MongoDB las almacena en UTC.

Las reservas cuentan para el cupo. Cuando se llena, se permite entrar a lista de espera si está habilitada. Al cancelar una reserva o ampliar el cupo, se promueve en orden de inscripción antes del inicio. Repetir una inscripción no crea duplicados. Cancelar y volver a inscribirse cambia el código de acceso y coloca al usuario al final de la fila si no hay cupo.

En `/modulo6/mis-boletos` cada estudiante ve exclusivamente sus propias reservas, su posición en espera y su QR cuando corresponde. El QR se genera en el navegador, sin enviar el token a servicios externos. La vista actualiza su estado cada 20 segundos mientras esté visible; también tiene actualización manual.

## Eventos con costo: decisión acordada

Se aceptan reservas con **pago pendiente**, que ocupan cupo. No hay cobros, saldo simulado ni botón para marcar una reserva como pagada. Estas reservas no generan un QR utilizable ni permiten registrar asistencia. La lista de espera también conserva el pago pendiente al promoverse.

No existe vencimiento automático del pago pendiente en esta entrega. El lugar se mantiene hasta cancelación o finalización del evento. El equipo 2 deberá acordar confirmación confiable e idempotente de pagos, plazos y devoluciones. Los montos de la reserva se guardan como centavos MXN. Si en el futuro una reserva está pagada, no se permite cancelarla ni cancelar su evento hasta resolver la devolución.

## Reglas de acceso y cambios

- El acceso abre 30 minutos antes del inicio y cierra al terminar.
- Solo se admiten reservas confirmadas, exentas de pago o con pago confirmado; una segunda lectura se rechaza.
- Cancelar el evento o la inscripción invalida el acceso. Después de registrar asistencia se oculta el QR.
- Inscritos y catálogo no exponen los tokens. La auditoría tampoco los guarda. Los boletos privados usan `Cache-Control: private, no-store`.
- Con reservas activas no se pueden cambiar ubicación, precio ni inicio/final del evento. El cupo no puede bajar de las reservas confirmadas.
- No se permite cancelar una inscripción después del inicio o de haber asistido, ni cancelar un evento que ya comenzó o registró asistencia.
- El lector ofrece cámara, imagen QR y entrada manual. Para cámara se necesita permiso y un contexto seguro (localhost o HTTPS).

Las operaciones por evento se serializan mediante bloqueos de caché de archivos y los índices únicos de MongoDB evitan inscripciones duplicadas. Esta configuración corresponde a una instancia de Laravel. Antes de escalar a varias instancias se necesita un bloqueo compartido; las operaciones entre documentos y su auditoría aún no tienen una transacción conjunta. No ejecutar pruebas en paralelo contra la misma base.

## Preparación y demostración

Desde `/home/daniel/campus_digital` en WSL:

```bash
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed
docker compose exec --user sail laravel.test php artisan db:seed --class=EventoSeeder
docker compose exec --user sail laravel.test npm ci
docker compose exec --user sail laravel.test npm run build
```

`EventoSeeder` es opcional y crea un evento gratuito y otro de $75 MXN, ambos con dos lugares y fecha dos días después de su primera ejecución. Repetirlo conserva cambios y no reprograma eventos pasados. Para nuevas demostraciones, crear otro evento desde la interfaz. Las cuentas y preparación del entorno están en [Entrega 1](Entrega_1_MongoDB_Asociaciones.md).

1. Entrar como `presidencia@campus.test`, abrir Eventos y crear/publicar un evento gratuito con cupo 1 e inicio dentro de 20 minutos.
2. En otra sesión entrar como `estudiante@campus.test`, inscribirse y abrir Mis boletos.
3. Entrar como `andrea@campus.test` y apuntarse a la espera. Cancelar la reserva anterior y actualizar el boleto de Andrea para comprobar la promoción.
4. Descargar el QR de Andrea. Como presidencia abrir Inscritos y asistencia y cargar esa imagen: registra la asistencia. Volver a leerla: rechaza el acceso duplicado.
5. En un evento con costo reservar un lugar y comprobar que queda pendiente sin QR.

Contraseña de las cuentas de demostración: `CampusDemo2026!`.

## Verificación

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas de eventos cubren permisos, privacidad, horario, publicación, reservas, cupo, orden de espera, cancelación, reingreso, cambios protegidos, pagos pendientes y uso único del QR. Se ejecutan en `campus_digital_testing`, separada de la base de la aplicación.

### Resultado de esta entrega

- Suite completa: 54 pruebas y 350 aserciones aprobadas en MongoDB de pruebas.
- Compilación de producción con Vite aprobada.
- Recorrido en Edge: crear/publicar, inscribirse, cancelar, promover espera, generar/descargar QR, leer imagen y rechazar segundo uso, reserva con costo sin QR.
- Boletos y navegación comprobados con ancho móvil de 390 px.
- Cámara comprobada con un flujo de video simulado: decodificación, cierre de pistas y manejo de permiso denegado. Falta probar el dispositivo físico de la laptop.
- Tres solicitudes simultáneas para cupo 1: una confirmada y dos en espera. Evento de concurrencia cancelado al finalizar la prueba.
- Sin errores JavaScript no controlados en esos recorridos.

Los recorridos de navegador dejan eventos e inscripciones de demostración con títulos «Prueba de recorrido Eventos», «Prueba de cámara QR» y «Prueba simultánea». Los dos eventos de `EventoSeeder` permiten empezar otra demostración sin depender de esos registros.

## Siguiente entrega

Becas: flujo local implementado en [Entrega 3](Entrega_3_Becas.md), con entrega pendiente de los equipos 2 y 5. Campañas y bandeja: implementadas en [Entrega 4](Entrega_4_Comunicacion.md). Encuestas y votaciones: [Entrega 5](Entrega_5_Encuestas_Votaciones.md). Reportes y dashboard: [Entrega 6](Entrega_6_Transparencia_Dashboard.md). La integración de pagos sigue pendiente.


Personal delegado para validar entradas: [Entrega 7 — Staff](Entrega_7_Staff_Continuidad.md). La asignación se realiza por evento desde presidencia.
