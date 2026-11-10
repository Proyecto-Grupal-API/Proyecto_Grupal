<?php

namespace App\Services\StudentServices\Support;

use App\Models\StudentServices\Support\SupportTicket;
use App\Models\StudentServices\Support\TicketEvent;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Platform\ServicePlatformEvents;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class SupportTicketService
{
    private const CATEGORIES = [
        'network',
        'equipment',
        'facilities',
        'software',
        'printing',
        'other',
    ];

    private const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    private const STATUSES = [
        'open',
        'assigned',
        'in_progress',
        'resolved',
        'closed',
        'cancelled',
    ];

    public function createTicket(
        string $studentId,
        string $category,
        string $subject,
        string $description,
        string $priority,
        string $location,
        ?UploadedFile $evidence = null
    ): SupportTicket {
        $studentId = trim($studentId);
        $subject = trim($subject);
        $description = trim($description);
        $location = trim($location);

        if ($studentId === '') {
            throw new InvalidArgumentException(
                'El estudiante es obligatorio.'
            );
        }

        if (
            ! in_array(
                $category,
                self::CATEGORIES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'La categoría seleccionada no es válida.'
            );
        }

        if ($subject === '') {
            throw new InvalidArgumentException(
                'El asunto es obligatorio.'
            );
        }

        if ($description === '') {
            throw new InvalidArgumentException(
                'La descripción es obligatoria.'
            );
        }

        if ($location === '') {
            throw new InvalidArgumentException(
                'La ubicación es obligatoria.'
            );
        }

        if (
            ! in_array(
                $priority,
                self::PRIORITIES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'La prioridad seleccionada no es válida.'
            );
        }

        $evidencePath = null;
        $evidenceName = null;
        $ticket = null;

        try {
            if ($evidence !== null) {
                $evidenceName =
                    $evidence->getClientOriginalName();

                $evidencePath =
                    $evidence->store(
                        'student-services/support',
                        'local'
                    );
            }

            $openedAt = now();

            $slaDueAt =
                $this->calculateSlaDueAt(
                    $priority,
                    $openedAt
                );

            $ticket =
                SupportTicket::create([
                    'folio' => $this->generateFolio(),

                    'student_id' => $studentId,

                    'category' => $category,

                    'subject' => $subject,

                    'description' => $description,

                    'priority' => $priority,

                    'location' => $location,

                    'status' => 'open',

                    'assigned_to' => null,

                    'evidence_name' => $evidenceName,

                    'evidence_path' => $evidencePath,

                    'opened_at' => $openedAt,

                    'sla_due_at' => $slaDueAt,

                    'resolved_at' => null,

                    'closed_at' => null,
                ]);

            $this->registerEvent(
                $ticket,
                'created',
                null,
                'open',
                $studentId,
                'Ticket registrado por el estudiante.'
            );

            return $ticket->fresh();
        } catch (Throwable $exception) {
            if ($ticket !== null) {
                $ticket->delete();
            }

            if (
                is_string($evidencePath) &&
                Storage::disk('local')
                    ->exists($evidencePath)
            ) {
                Storage::disk('local')
                    ->delete($evidencePath);
            }

            throw $exception;
        }
    }

    public function assignTicket(
        SupportTicket $ticket,
        string $assignedTo,
        string $actorId
    ): SupportTicket {
        $assignedTo =
            trim($assignedTo);

        if ($assignedTo === '') {
            throw new InvalidArgumentException(
                'Debes indicar a quién se asignará el ticket.'
            );
        }

        if ($ticket->status !== 'open') {
            throw new RuntimeException(
                'Solo un ticket abierto puede asignarse.'
            );
        }

        $ticket->update([
            'assigned_to' => $assignedTo,

            'status' => 'assigned',
        ]);

        $this->registerEvent(
            $ticket,
            'assigned',
            'open',
            'assigned',
            $actorId,
            "Ticket asignado a {$assignedTo}."
        );

        return $ticket->fresh();
    }

    public function changeStatus(
        SupportTicket $ticket,
        string $newStatus,
        string $actorId,
        ?string $message = null
    ): SupportTicket {
        if (
            ! in_array(
                $newStatus,
                self::STATUSES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'El estado seleccionado no es válido.'
            );
        }

        if (
            in_array(
                $ticket->status,
                [
                    'closed',
                    'cancelled',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Este ticket ya no puede modificarse.'
            );
        }

        if (
            $ticket->status ===
            $newStatus
        ) {
            throw new RuntimeException(
                'El ticket ya se encuentra en ese estado.'
            );
        }

        if (
            ! $this->isValidTransition(
                $ticket->status,
                $newStatus
            )
        ) {
            throw new RuntimeException(
                'El cambio de estado solicitado no es válido.'
            );
        }

        $previousStatus =
            $ticket->status;

        $updates = [
            'status' => $newStatus,
        ];

        if (
            $newStatus ===
            'resolved'
        ) {
            $updates['resolved_at'] =
                now();
        }

        if (
            $newStatus ===
            'closed'
        ) {
            $updates['closed_at'] =
                now();
        }

        $ticket->update(
            $updates
        );

        $this->registerEvent(
            $ticket,
            'status_changed',
            $previousStatus,
            $newStatus,
            $actorId,
            $message ??
            $this->defaultStatusMessage(
                $newStatus
            )
        );

        return $ticket->fresh();
    }

    public function addComment(
        SupportTicket $ticket,
        string $actorId,
        string $message
    ): TicketEvent {
        $message =
            trim($message);

        if ($message === '') {
            throw new InvalidArgumentException(
                'El comentario no puede estar vacío.'
            );
        }

        if (
            in_array(
                $ticket->status,
                [
                    'closed',
                    'cancelled',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'No puedes agregar comentarios a un ticket cerrado o cancelado.'
            );
        }

        return $this->registerEvent(
            $ticket,
            'comment',
            $ticket->status,
            $ticket->status,
            $actorId,
            $message
        );
    }

    public function cancelTicket(
        SupportTicket $ticket,
        string $actorId,
        ?string $message = null
    ): SupportTicket {
        if (
            ! in_array(
                $ticket->status,
                [
                    'open',
                    'assigned',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Este ticket ya no puede cancelarse.'
            );
        }

        $previousStatus =
            $ticket->status;

        $ticket->update([
            'status' => 'cancelled',
        ]);

        $this->registerEvent(
            $ticket,
            'status_changed',
            $previousStatus,
            'cancelled',
            $actorId,
            $message ??
            'Ticket cancelado por el estudiante.'
        );

        return $ticket->fresh();
    }

    private function calculateSlaDueAt(
        string $priority,
        CarbonInterface $openedAt
    ): CarbonInterface {
        $hours =
            match ($priority) {
                'critical' => 2,
                'high' => 6,
                'medium' => 24,
                'low' => 48,

                default => throw new InvalidArgumentException(
                    'La prioridad no es válida.'
                ),
            };

        return $openedAt
            ->copy()
            ->addHours($hours);
    }

    private function isValidTransition(
        string $currentStatus,
        string $newStatus
    ): bool {
        $transitions = [
            'open' => [
                'assigned',
            ],

            'assigned' => [
                'in_progress',
                'resolved',
            ],

            'in_progress' => [
                'resolved',
            ],

            'resolved' => [
                'closed',
            ],

            'closed' => [],

            'cancelled' => [],
        ];

        return in_array(
            $newStatus,
            $transitions[$currentStatus]
            ?? [],
            true
        );
    }

    private function defaultStatusMessage(
        string $status
    ): string {
        return match ($status) {
            'assigned' => 'El ticket fue asignado al área de soporte.',

            'in_progress' => 'La atención del ticket ha comenzado.',

            'resolved' => 'La incidencia fue marcada como resuelta.',

            'closed' => 'El ticket fue cerrado.',

            default => 'El estado del ticket fue actualizado.',
        };
    }

    /**
     * Escalamiento automático del SLA (5.9): un ticket abierto, asignado o en
     * proceso cuyo plazo ya venció sube un nivel de prioridad (crítico se
     * queda en crítico), se marca como escalado y queda en su historial y en
     * la auditoría. Cada ticket se escala una sola vez; la marca atómica evita
     * escalarlo dos veces si el comando corre en paralelo.
     *
     * @return int tickets escalados en esta pasada
     */
    public function escalateOverdue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $escalated = 0;

        $overdue = SupportTicket::query()
            ->whereIn('status', ['open', 'assigned', 'in_progress'])
            ->where('sla_due_at', '<=', $now)
            ->whereNull('sla_escalated_at')
            ->get();

        foreach ($overdue as $ticket) {
            $from = (string) $ticket->priority;
            $index = array_search($from, self::PRIORITIES, true);
            $to = self::PRIORITIES[min(count(self::PRIORITIES) - 1, ($index === false ? 0 : $index) + 1)];

            $claimed = SupportTicket::query()
                ->where('_id', new ObjectId((string) $ticket->id))
                ->whereNull('sla_escalated_at')
                ->update([
                    'sla_escalated_at' => $now,
                    'priority_before_escalation' => $from,
                    'priority' => $to,
                ]);

            if ($claimed === 0) {
                continue;
            }

            $message = $from === $to
                ? 'Venció el SLA; el ticket ya tenía prioridad crítica.'
                : "Venció el SLA; prioridad {$this->priorityLabel($from)} → {$this->priorityLabel($to)}.";

            $this->registerEvent($ticket, 'sla_escalated', (string) $ticket->status, (string) $ticket->status, 'system', $message);

            app(ServiceAuditor::class)->record(
                'support.ticket.sla_escalated',
                'support_ticket',
                (string) $ticket->id,
                ['priority' => $from, 'sla_due_at' => $ticket->sla_due_at?->toIso8601String()],
                ['priority' => $to, 'sla_escalated_at' => $now->toIso8601String()],
                $message,
                'system'
            );

            // Analítica del Equipo 7 (la actualización masiva no pasa por el observador).
            app(ServicePlatformEvents::class)->emit('ticket.sla_escalated', (string) $ticket->id, [
                'student_id' => $ticket->student_id,
                'ticket_id' => $ticket->id,
                'from_priority' => $from,
                'to_priority' => $to,
            ]);

            $escalated++;
        }

        return $escalated;
    }

    private function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'low' => 'baja',
            'medium' => 'media',
            'high' => 'alta',
            'critical' => 'crítica',
            default => $priority,
        };
    }

    private function registerEvent(
        SupportTicket $ticket,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $actorId,
        ?string $message = null
    ): TicketEvent {
        return TicketEvent::create([
            'ticket_id' => $ticket->id,

            'event_type' => $eventType,

            'from_status' => $fromStatus,

            'to_status' => $toStatus,

            'actor_id' => $actorId,

            'message' => $message,

            'created_at' => now(),
        ]);
    }

    private function generateFolio(): string
    {
        do {
            $folio =
                'SUP-'.
                now()->format('Y').
                '-'.
                strtoupper(
                    substr(
                        bin2hex(
                            random_bytes(4)
                        ),
                        0,
                        6
                    )
                );

            $exists =
                SupportTicket::query()
                    ->where(
                        'folio',
                        $folio
                    )
                    ->exists();
        } while ($exists);

        return $folio;
    }
}
