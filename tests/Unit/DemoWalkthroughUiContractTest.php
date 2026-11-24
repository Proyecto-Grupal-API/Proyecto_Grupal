<?php

// Regresiones de interfaz encontradas por los recorridos de demostración por equipo (fase L). Cada una era un
// formulario o una vista que no coincidía con lo que envía o exige el servidor. La comprobación de fondo es el
// recorrido en navegador; estas pruebas fijan el contrato en el código fuente.

function uiSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$path);
}

test('E1 student form shows the status reason validation error', function () {
    expect(uiSource('Components/StudentForm.vue'))->toContain('form.errors.status_reason');
});

test('E1 NFC history reads the author as the server sends it', function () {
    expect(uiSource('Pages/NFC/History.vue'))->toContain('event.performed_by?.name');
});

test('E7 business enrolment sends the fields the API requires and lists the ones it returns', function () {
    $source = uiSource('Pages/Platform/admin/AdminBusinesses.vue');
    $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/PlatformModule/BusinessController.php');

    expect($controller)->toContain("'business_id' => 'required", "'business_name' => 'required")
        // Envía los campos que exige la API y lista los que devuelve (sin recurrir a campos que la API no entrega).
        ->and($source)->toContain('business_id: form.value.code', 'business_name: form.value.name', 'name: b.business_name', 'code: b.business_id');
});

test('E7 campaign proposal sends starts_at and ends_at', function () {
    expect(uiSource('Pages/Platform/professor/ProfessorCampaigns.vue'))->toContain('starts_at: form.value.start_date', 'ends_at: form.value.end_date')
        ->not->toContain('start_date: form.value.start_date');
});

test('E7 professor delivers a validated redemption using the id the API returns', function () {
    expect(uiSource('Pages/Platform/professor/ProfessorValidate.vue'))->toContain('searchResult.redemption._id || searchResult.redemption.id');
});

test('E7 ledger reconcile toast reads the reconciled account and empty searches clear the table', function () {
    $source = uiSource('Pages/Platform/admin/AdminLedger.vue');

    expect($source)->toContain('res.account?.balance')->not->toContain('if (list.length > 0) {');
});

test('E5 locker dates are shown in the campus timezone instead of cutting the UTC string', function (string $page) {
    expect(uiSource('Pages/student-services/lockers/'.$page))->toContain("timeZone: 'America/Mexico_City'");
})->with(['Periods.vue', 'Assignments.vue']);

test('E7 student activity filters by the real movement type instead of labelling every non-earn movement a redemption', function () {
    expect(uiSource('Pages/Platform/student/StudentActivity.vue'))->toContain('type: item.type,')->not->toContain("item.type === 'earn' ? 'earn' : 'redeem'");
});

// P-16: el recorrido de Servicios vio que la página añadía por su cuenta el aviso «Reserva confirmada.» también a una
// reserva con costo, que nadie cobra. La página no puede anunciar una reserva con costo como confirmada ni como pagada.
test('E5 facility reservations with a cost are announced as held with payment pending, never as confirmed or paid', function () {
    $source = uiSource('Pages/student-services/reservations/Index.vue');

    expect($source)
        ->toContain('reservation.status_label ??')                       // la etiqueta del estado la decide el servidor
        ->toContain("reservation.payment_status === 'pending'")           // y la tarjeta dice que el pago está pendiente
        ->toContain('quedará apartada con el pago pendiente')              // antes de reservar
        ->toContain('Reserva apartada con el pago pendiente. No se ha realizado ningún cobro.')   // al reservar
        // «Reserva confirmada.» sólo queda para la instalación sin costo
        ->toMatch('/hasCost\s*\?\s*\'Reserva apartada con el pago pendiente[^\']*\'\s*:\s*\'Reserva confirmada\.\'/')
        ->not->toMatch('/[Pp]agada/');
});

// 7.8-b, 7.8-d y 7.10-f: la bitácora de Recompensas guarda los valores de antes y después, el dispositivo y la correlación de cada
// acción, y el servidor ya ofrece el detalle de un registro y la traza de una operación. La pantalla sólo listaba actor, acción,
// entidad, fecha, IP y correlación: tiene que ofrecer el detalle con esos datos y la traza.
test('E7 audit screen opens the detail of a record with its before and after values, device and correlation trace', function () {
    $source = uiSource('Pages/Platform/admin/AdminAudit.vue');

    expect($source)
        ->toContain('api.audit.getLog(')                 // detalle del registro
        ->toContain('api.audit.getCorrelation(')         // traza de la operación
        ->toContain('Ver detalle')
        ->toContain('Valores antes y después')
        ->toContain('Dispositivo')
        ->toContain('Traza de la operación');
});

// 7.4-e: el catálogo deja fijar y ver el límite de canjes por alumno que el servidor ahora aplica.
test('E7 catalog form sends and shows the redemption limit per student', function () {
    expect(uiSource('Pages/Platform/admin/AdminCatalog.vue'))
        ->toContain('limit_per_user: limitValue()')
        ->toContain('Máximo de canjes por alumno')
        ->toContain('por alumno</div>');
});

// Hallado por el recorrido de E7 (7.4-e): un canje rechazado mostraba el aviso «Request failed with status code 422» en vez del
// motivo que da el servidor (saldo, límite por alumno, máximo diario).
test('E7 student catalog shows the reason the server gives for a rejected redemption', function () {
    expect(uiSource('Pages/Platform/student/StudentCatalog.vue'))
        ->toContain('err.response?.data?.error')
        ->not->toContain("error(err.message || 'No se pudo procesar el canje.')");
});
