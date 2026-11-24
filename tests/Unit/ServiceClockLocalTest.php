<?php

use App\Services\StudentServices\ServiceClock;

// Regresión de la fase L (recorrido de E5): los mensajes de validación de servicios formateaban instantes guardados en
// UTC sin pasarlos a la zona horaria del servicio (una reserva 07:30–08:00 se anunciaba «hasta las 14:00»).

test('a stored UTC instant is shown in the service timezone', function () {
    $utc = new DateTimeImmutable('2026-11-16 14:00:00', new DateTimeZone('UTC'));

    expect(ServiceClock::local($utc, 'America/Mexico_City')->format('H:i'))->toBe('08:00')
        ->and(ServiceClock::local(new DateTimeImmutable('2026-11-18 05:59:59', new DateTimeZone('UTC')), 'America/Mexico_City')->format('d/m/Y'))->toBe('17/11/2026');
});

test('user facing service messages never format a stored instant without the service timezone', function () {
    $root = dirname(__DIR__, 2).'/app/Services/StudentServices/';
    foreach (['ServiceAccess/ServiceAccessService.php', 'Calendars/BookingService.php', 'Calendars/AvailabilityService.php'] as $file) {
        $source = file_get_contents($root.$file);
        expect(preg_match_all('/\{\$[^{}]*->format\(\'(H:i|d\/m\/Y)[^\']*\'\)\}/', $source))->toBe(0, $file);
    }
    expect(substr_count(file_get_contents($root.'ServiceAccess/ServiceAccessService.php'), 'ServiceClock::local('))->toBeGreaterThanOrEqual(3);
});

test('locker dates are serialized in the service timezone, not cut from the stored UTC instant', function () {
    $root = dirname(__DIR__, 2).'/app/';
    foreach (['Models/StudentServices/Lockers/LockerPeriod.php', 'Http/Controllers/StudentServices/Lockers/LockerAssignmentController.php',
        'Http/Controllers/StudentServices/Lockers/LockerRequestController.php'] as $file) {
        $source = file_get_contents($root.$file);
        expect(preg_match_all('/->\w+\s*\?->format\(\s*\'Y-m-d/', $source))->toBe(0, $file)
            ->and($source)->toContain('ServiceClock::localOrNull(');
    }
    expect(ServiceClock::localOrNull(null))->toBeNull()
        ->and(ServiceClock::localOrNull(new DateTimeImmutable('2026-12-31 05:59:59', new DateTimeZone('UTC')), 'America/Mexico_City')->format('Y-m-d'))->toBe('2026-12-30');
});
