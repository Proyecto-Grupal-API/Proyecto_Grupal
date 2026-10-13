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
