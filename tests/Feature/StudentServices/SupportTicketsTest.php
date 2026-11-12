<?php

use App\Models\StudentServices\Support\SupportTicket;
use App\Models\StudentServices\Support\TicketEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();
    Storage::fake('local');

    $this->travelTo(Carbon::parse('2026-10-12 09:00'));

    $this->student = User::factory()->create();
    $this->agent = User::factory()->create();
});

function openTicket(User $student, string $priority = 'high', array $overrides = []): SupportTicket
{
    test()->actingAs($student)->post('/servicios-estudiante/soporte', [
        'category' => 'network',
        'subject' => 'Sin internet en el laboratorio',
        'description' => 'La red del laboratorio 3 no conecta desde la mañana.',
        'priority' => $priority,
        'location' => 'Edificio B, laboratorio 3',
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return SupportTicket::latest('created_at')->first();
}

test('the SLA due date depends on the priority', function (string $priority, int $hours) {
    $ticket = openTicket($this->student, $priority);

    expect($ticket->status)->toBe('open')
        ->and($ticket->sla_due_at->equalTo(now()->addHours($hours)))->toBeTrue();
})->with([
    'critical' => ['critical', 2],
    'high' => ['high', 6],
    'medium' => ['medium', 24],
    'low' => ['low', 48],
]);

test('evidence is stored with the ticket', function () {
    $ticket = openTicket($this->student, 'medium', [
        'evidence' => UploadedFile::fake()->create('reporte.pdf', 30, 'application/pdf'),
    ]);

    Storage::disk('local')->assertExists($ticket->evidence_path);
});

test('a ticket follows the allowed workflow and records every change', function () {
    $ticket = openTicket($this->student);

    $this->actingAs($this->agent)
        ->patch("/servicios-estudiante/soporte/{$ticket->id}/estado", ['status' => 'resolved'])
        ->assertSessionHasErrors('ticket');

    $this->actingAs($this->agent)
        ->patch("/servicios-estudiante/soporte/{$ticket->id}/asignar", ['assigned_to' => 'agente-redes'])
        ->assertSessionHasNoErrors();

    foreach (['in_progress', 'resolved', 'closed'] as $status) {
        $this->actingAs($this->agent)
            ->patch("/servicios-estudiante/soporte/{$ticket->id}/estado", ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    expect($ticket->fresh()->status)->toBe('closed')
        ->and(TicketEvent::where('ticket_id', new ObjectId((string) $ticket->id))->count())->toBeGreaterThanOrEqual(5);

    $this->actingAs($this->student)
        ->post("/servicios-estudiante/soporte/{$ticket->id}/comentarios", ['message' => '¿Sigue cerrado?'])
        ->assertSessionHasErrors('ticket');
});

test('a student can comment and cancel only an open ticket', function () {
    $ticket = openTicket($this->student);

    $this->actingAs($this->student)
        ->post("/servicios-estudiante/soporte/{$ticket->id}/comentarios", ['message' => 'Ya reinicié el equipo.'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/soporte/{$ticket->id}/cancelar")
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe('cancelled');

    $this->actingAs($this->agent)
        ->patch("/servicios-estudiante/soporte/{$ticket->id}/asignar", ['assigned_to' => 'agente-redes'])
        ->assertSessionHasErrors('ticket');
});

test('an overdue ticket is escalated once by the SLA review and the history shows it', function () {
    openTicket($this->student, 'medium');
    openTicket($this->student, 'low', ['subject' => 'Impresora del piso 2 sin tóner']);
    // Se crean en el mismo instante: se ubican por asunto.
    $ticket = SupportTicket::where('subject', 'Sin internet en el laboratorio')->firstOrFail();
    $onTime = SupportTicket::where('subject', 'Impresora del piso 2 sin tóner')->firstOrFail();

    // Antes del vencimiento no pasa nada.
    $this->artisan('servicios:revisar-sla')->assertSuccessful();
    expect($ticket->fresh()->sla_escalated_at)->toBeNull();

    $this->travel(25)->hours();
    $this->artisan('servicios:revisar-sla')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->priority)->toBe('high')
        ->and($ticket->priority_before_escalation)->toBe('medium')
        ->and($ticket->sla_escalated_at)->not->toBeNull()
        ->and($onTime->fresh()->priority)->toBe('low')
        ->and(TicketEvent::where('ticket_id', new ObjectId((string) $ticket->id))->where('event_type', 'sla_escalated')->count())->toBe(1)
        ->and(App\Models\StudentServices\Audit\ServiceAuditLog::where('action', 'support.ticket.sla_escalated')->where('entity_id', (string) $ticket->id)->count())->toBe(1)
        ->and(App\Models\StudentServices\Platform\ServicePlatformEvent::where('event_name', 'services.ticket.sla_escalated.v1')->where('aggregate_id', (string) $ticket->id)->count())->toBe(1);

    // Una segunda pasada no lo vuelve a escalar.
    $this->travel(30)->hours();
    $this->artisan('servicios:revisar-sla')->assertSuccessful();
    expect($ticket->fresh()->priority)->toBe('high')
        ->and($onTime->fresh()->priority)->toBe('medium')
        ->and(TicketEvent::where('ticket_id', new ObjectId((string) $ticket->id))->where('event_type', 'sla_escalated')->count())->toBe(1)
        ->and(App\Models\StudentServices\Platform\ServicePlatformEvent::where('event_name', 'services.ticket.sla_escalated.v1')->where('aggregate_id', (string) $ticket->id)->count())->toBe(1);

    $this->actingAs($this->student)->get('/servicios-estudiante/soporte')
        ->assertInertia(fn ($page) => $page->where('tickets', fn ($tickets) => collect($tickets)
            ->firstWhere('id', (string) $ticket->id)['slaEscalated'] === true));
});

test('resolved tickets and critical ones keep their priority when the SLA expires', function () {
    openTicket($this->student, 'critical');
    openTicket($this->student, 'low', ['subject' => 'Proyector sin imagen']);
    $critical = SupportTicket::where('subject', 'Sin internet en el laboratorio')->firstOrFail();
    $resolved = SupportTicket::where('subject', 'Proyector sin imagen')->firstOrFail();
    SupportTicket::whereKey($resolved->id)->update(['status' => 'resolved']);

    $this->travel(3)->days();
    $this->artisan('servicios:revisar-sla')->assertSuccessful();

    expect($critical->fresh()->priority)->toBe('critical')
        ->and($critical->fresh()->sla_escalated_at)->not->toBeNull()
        ->and($resolved->fresh()->sla_escalated_at)->toBeNull()
        ->and($resolved->fresh()->priority)->toBe('low');
});
