<?php

use App\Services\StudentServices\Calendars\CalendarRuleChecker;
use Carbon\CarbonImmutable;

function calendarRules(array $overrides = []): array
{
    return [
        'open_time' => '08:00',
        'close_time' => '18:00',
        'slot_minutes' => 30,
        'min_booking_minutes' => 30,
        'max_booking_minutes' => 120,
        'cancel_before_minutes' => 60,
        'no_show_tolerance_minutes' => 15,
        'max_active_per_student' => 3,
        'max_advance_days' => 7,
        'max_no_shows' => 3,
        'no_show_window_days' => 30,
        'operating_days' => [1, 2, 3, 4, 5],
        ...$overrides,
    ];
}

function at(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value);
}

// 2026-10-12 es lunes.
$now = '2026-10-12 07:00';

test('a valid booking has no violations', function () use ($now) {
    $violations = (new CalendarRuleChecker)->violations(
        calendarRules(),
        at('2026-10-12 10:00'),
        at('2026-10-12 11:30'),
        at($now)
    );

    expect($violations)->toBe([]);
});

test('it rejects bookings outside operating hours, misaligned or too long', function (string $start, string $end, string $message) use ($now) {
    $violations = (new CalendarRuleChecker)->violations(calendarRules(), at($start), at($end), at($now));

    expect($violations)->toContain($message);
})->with([
    'before opening' => ['2026-10-12 07:30', '2026-10-12 08:30', 'El horario debe estar dentro de 08:00 – 18:00.'],
    'after closing' => ['2026-10-12 17:30', '2026-10-12 18:30', 'El horario debe estar dentro de 08:00 – 18:00.'],
    'misaligned slot' => ['2026-10-12 10:15', '2026-10-12 11:15', 'Las reservas deben ajustarse a franjas de 30 minutos.'],
    'too long' => ['2026-10-12 08:00', '2026-10-12 11:00', 'La duración máxima por reserva es de 120 minutos.'],
    'closed day (saturday)' => ['2026-10-17 10:00', '2026-10-17 11:00', 'El recurso no opera ese día de la semana.'],
    'too far ahead' => ['2026-10-26 10:00', '2026-10-26 11:00', 'Solo se puede reservar con un máximo de 7 día(s) de anticipación.'],
    'in the past' => ['2026-10-09 10:00', '2026-10-09 11:00', 'No puedes reservar un horario que ya pasó.'],
]);

test('back to back bookings never overlap when computing peak occupancy', function () {
    $checker = new CalendarRuleChecker;

    $ranges = [
        [at('2026-10-12 09:00'), at('2026-10-12 10:00')],
        [at('2026-10-12 10:00'), at('2026-10-12 11:00')],
    ];

    expect($checker->peakOccupancy($ranges, at('2026-10-12 09:00'), at('2026-10-12 11:00')))->toBe(1);

    $ranges[] = [at('2026-10-12 09:30'), at('2026-10-12 10:30')];

    expect($checker->peakOccupancy($ranges, at('2026-10-12 09:00'), at('2026-10-12 11:00')))->toBe(2);
});

test('students can only cancel before the minimum cancellation window', function () {
    $checker = new CalendarRuleChecker;
    $start = at('2026-10-12 10:00');

    expect($checker->canStudentCancel(calendarRules(), $start, at('2026-10-12 08:59')))->toBeTrue()
        ->and($checker->canStudentCancel(calendarRules(), $start, at('2026-10-12 09:00')))->toBeFalse();
});

test('check-in window opens early and closes after the no-show tolerance', function () {
    $checker = new CalendarRuleChecker;
    $window = $checker->checkInWindow(calendarRules(), at('2026-10-12 10:00'), at('2026-10-12 11:00'));

    expect($window['opens_at']->format('H:i'))->toBe('09:45')
        ->and($window['closes_at']->format('H:i'))->toBe('10:15')
        ->and($checker->isNoShow(calendarRules(), at('2026-10-12 10:00'), at('2026-10-12 11:00'), at('2026-10-12 10:16')))->toBeTrue()
        ->and($checker->isNoShow(calendarRules(), at('2026-10-12 10:00'), at('2026-10-12 11:00'), at('2026-10-12 10:10')))->toBeFalse();
});

test('it validates rule configuration consistency', function () {
    $checker = new CalendarRuleChecker;

    expect($checker->ruleViolations(calendarRules()))->toBe([])
        ->and($checker->ruleViolations(calendarRules(['close_time' => '07:00'])))->toContain('La hora de cierre debe ser posterior a la de apertura.')
        ->and($checker->ruleViolations(calendarRules(['max_booking_minutes' => 45])))->toContain('La duración máxima debe ser múltiplo de la franja.')
        ->and($checker->slotsForDay(calendarRules(['open_time' => '08:00', 'close_time' => '09:30'])))->toBe([
            ['start' => '08:00', 'end' => '08:30'],
            ['start' => '08:30', 'end' => '09:00'],
            ['start' => '09:00', 'end' => '09:30'],
        ]);
});
