<?php

use App\Models\StudentServices\Audit\ServiceAuditLog;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\User;
use App\Services\StudentServices\Calendars\BookableResources;
use Carbon\Carbon;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-10-12 08:00'));

    $this->admin = User::factory()->create();
    $this->facility = Facility::create([
        'name' => 'Sala de estudio 3',
        'type' => 'Sala',
        'building' => 'Edificio B',
        'capacity' => 1,
        'cost_cents' => 0,
        'active' => true,
    ]);
});

test('waiving a fine is audited with actor, before and after, reason and request context', function () {
    $fine = LibraryFine::create(['student_id' => 'student-1', 'type' => 'late', 'amount_cents' => 3000, 'status' => 'pending', 'reason' => 'Atraso']);

    $this->actingAs($this->admin)
        ->withHeaders(['X-Correlation-Id' => 'corr-123', 'User-Agent' => 'Mostrador biblioteca'])
        ->patch("/servicios-estudiante/biblioteca/multas/{$fine->id}/condonar", ['notes' => 'Primera vez'])
        ->assertSessionHasNoErrors();

    $log = ServiceAuditLog::where('action', 'library.fine.waived')->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe((string) $this->admin->id)
        ->and($log->entity_id)->toBe((string) $fine->id)
        ->and($log->before)->toBe(['status' => 'pending', 'amount_cents' => 3000])
        ->and($log->after)->toBe(['status' => 'waived'])
        ->and($log->reason)->toBe('Primera vez')
        ->and($log->correlation_id)->toBe('corr-123')
        ->and($log->device)->toBe('Mostrador biblioteca')
        ->and($log->source_domain)->toBe('services')
        ->and($log->published_at)->toBeNull();
});

test('changing calendar rules and blocking a slot are audited', function () {
    $this->actingAs($this->admin)
        ->patch("/servicios-estudiante/calendarios-cupos/facility/{$this->facility->id}/reglas", [
            'capacity' => 2,
            'open_time' => '09:00',
            'close_time' => '17:00',
            'slot_minutes' => 60,
            'min_booking_minutes' => 60,
            'max_booking_minutes' => 120,
            'cancel_before_minutes' => 30,
            'no_show_tolerance_minutes' => 10,
            'max_active_per_student' => 2,
            'max_advance_days' => 14,
            'max_no_shows' => 2,
            'no_show_window_days' => 15,
            'operating_days' => [1, 2, 3, 4, 5],
        ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post('/servicios-estudiante/calendarios-cupos/bloqueos', [
        'resource_type' => BookableResources::FACILITY,
        'resource_id' => (string) $this->facility->id,
        'date' => '2026-10-12',
        'start_time' => '12:00',
        'end_time' => '14:00',
        'reason' => 'Mantenimiento preventivo',
    ])->assertSessionHasNoErrors();

    $rules = ServiceAuditLog::where('action', 'calendar.rules.updated')->first();
    $block = ServiceAuditLog::where('action', 'calendar.block.created')->first();

    expect($rules->after['open_time'])->toBe('09:00')
        ->and($rules->before['open_time'])->not->toBe('09:00')
        ->and($block->reason)->toBe('Mantenimiento preventivo')
        ->and($block->after['resource_id'])->toBe((string) $this->facility->id);
});

test('a manual waitlist promotion is audited but a failed attempt is not', function () {
    foreach (range(1, 2) as $student) {
        $this->actingAs(User::factory()->create())->post('/servicios-estudiante/reservas', [
            'facility_id' => (string) $this->facility->id,
            'date' => '2026-10-12',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    $waiting = Reservation::where('status', 'waitlisted')->first();

    $this->actingAs($this->admin)->patch("/servicios-estudiante/calendarios-cupos/espera/facility/{$waiting->id}/promover");

    expect(ServiceAuditLog::where('action', 'calendar.waitlist.promoted_manually')->count())->toBe(0);

    $this->facility->update(['capacity' => 2]);

    $this->actingAs($this->admin)
        ->patch("/servicios-estudiante/calendarios-cupos/espera/facility/{$waiting->id}/promover")
        ->assertSessionHasNoErrors();

    expect(ServiceAuditLog::where('action', 'calendar.waitlist.promoted_manually')->first()->entity_id)
        ->toBe((string) $waiting->id);
});

test('a denied access in service validation is audited', function () {
    $this->actingAs($this->admin)->post('/servicios-estudiante/validacion-servicios/validar', [
        'credential' => 'NFC-DESCONOCIDA',
        'method' => 'nfc',
        'service' => 'facility',
        'action' => 'checkin',
    ]);

    $log = ServiceAuditLog::where('action', 'service_access.denied')->first();

    expect($log->reason)->toBe('Credencial no reconocida.')
        ->and($log->after['service'])->toBe('facility');
});
