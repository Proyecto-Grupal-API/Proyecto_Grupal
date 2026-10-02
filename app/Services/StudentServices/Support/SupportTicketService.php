<?php

namespace App\Services\StudentServices\Support;

use App\Models\StudentServices\Support\SupportTicket;
use App\Models\StudentServices\Support\TicketEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
            !in_array(
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
            !in_array(
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
                    'folio' =>
                        $this->generateFolio(),

                    'student_id' =>
                        $studentId,

                    'category' =>
                        $category,

                    'subject' =>
                        $subject,

                    'description' =>
                        $description,

                    'priority' =>
                        $priority,

                    'location' =>
                        $location,

                    'status' =>
                        'open',

                    'assigned_to' =>
                        null,

                    'evidence_name' =>
                        $evidenceName,

                    'evidence_path' =>
                        $evidencePath,

                    'opened_at' =>
                        $openedAt,

                    'sla_due_at' =>
                        $slaDueAt,

                    'resolved_at' =>
                        null,

                    'closed_at' =>
                        null,
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
                $evidencePath !== null &&
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
            'assigned_to' =>
                $assignedTo,

            'status' =>
                'assigned',
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
            !in_array(
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
            !$this->isValidTransition(
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
            'status' =>
                $newStatus,
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
            !in_array(
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
            'status' =>
                'cancelled',
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
               $openedAt
    ) {
        $hours =
            match ($priority) {
                'critical' => 2,
                'high' => 6,
                'medium' => 24,
                'low' => 48,

                default =>
                throw new InvalidArgumentException(
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
            'assigned' =>
            'El ticket fue asignado al área de soporte.',

            'in_progress' =>
            'La atención del ticket ha comenzado.',

            'resolved' =>
            'La incidencia fue marcada como resuelta.',

            'closed' =>
            'El ticket fue cerrado.',

            default =>
            'El estado del ticket fue actualizado.',
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
            'ticket_id' =>
                $ticket->id,

            'event_type' =>
                $eventType,

            'from_status' =>
                $fromStatus,

            'to_status' =>
                $toStatus,

            'actor_id' =>
                $actorId,

            'message' =>
                $message,

            'created_at' =>
                now(),
        ]);
    }

    private function generateFolio(): string
    {
        do {
            $folio =
                'SUP-' .
                now()->format('Y') .
                '-' .
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
