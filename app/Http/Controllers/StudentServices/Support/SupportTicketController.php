<?php

namespace App\Http\Controllers\StudentServices\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Support\AddTicketCommentRequest;
use App\Http\Requests\StudentServices\Support\AssignTicketRequest;
use App\Http\Requests\StudentServices\Support\ChangeTicketStatusRequest;
use App\Http\Requests\StudentServices\Support\StoreSupportTicketRequest;
use App\Models\StudentServices\Support\SupportTicket;
use App\Models\StudentServices\Support\TicketEvent;
use App\Services\StudentServices\Support\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $supportTicketService
    ) {
    }

    public function index(
        Request $request
    ): Response {
        $studentId =
            $this->getUserId(
                $request
            );

        $tickets =
            SupportTicket::query()
                ->where(
                    'student_id',
                    $studentId
                )
                ->orderBy(
                    'opened_at',
                    'desc'
                )
                ->get()
                ->map(
                    fn (SupportTicket $ticket) =>
                    $this->ticketPayload(
                        $ticket
                    )
                )
                ->values();

        return Inertia::render(
            'student-services/support/Index',
            [
                'tickets' =>
                    $tickets,
            ]
        );
    }

    public function store(
        StoreSupportTicketRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $studentId =
                $this->getUserId(
                    $request
                );

            $this->supportTicketService
                ->createTicket(
                    $studentId,
                    $data['category'],
                    $data['subject'],
                    $data['description'],
                    $data['priority'],
                    $data['location'],
                    $request->file(
                        'evidence'
                    )
                );

            return back()->with(
                'success',
                'Ticket registrado correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'ticket' =>
                        $exception->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'ticket' =>
                        'No fue posible registrar el ticket.',
                ])
                ->withInput();
        }
    }

    public function comment(
        AddTicketCommentRequest $request,
        string $ticketId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $ticket =
                SupportTicket::findOrFail(
                    $ticketId
                );

            $actorId =
                $this->getUserId(
                    $request
                );

            $this->validateOwner(
                $ticket,
                $actorId
            );

            $this->supportTicketService
                ->addComment(
                    $ticket,
                    $actorId,
                    $data['message']
                );

            return back()->with(
                'success',
                'Comentario agregado correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'ticket' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'ticket' =>
                        'No fue posible agregar el comentario.',
                ]);
        }
    }

    public function cancel(
        Request $request,
        string $ticketId
    ): RedirectResponse {
        try {
            $ticket =
                SupportTicket::findOrFail(
                    $ticketId
                );

            $actorId =
                $this->getUserId(
                    $request
                );

            $this->validateOwner(
                $ticket,
                $actorId
            );

            $this->supportTicketService
                ->cancelTicket(
                    $ticket,
                    $actorId,
                    'Ticket cancelado por el estudiante.'
                );

            return back()->with(
                'success',
                'Ticket cancelado correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'ticket' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'ticket' =>
                        'No fue posible cancelar el ticket.',
                ]);
        }
    }

    public function changeStatus(
        ChangeTicketStatusRequest $request,
        string $ticketId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $ticket =
                SupportTicket::findOrFail(
                    $ticketId
                );

            $actorId =
                $this->getUserId(
                    $request
                );

            /*
             * Esta acción corresponde al
             * personal de soporte.
             *
             * Cuando se integre el sistema
             * de roles del Equipo 1,
             * esta ruta debe protegerse
             * mediante permisos.
             */
            $this->supportTicketService
                ->changeStatus(
                    $ticket,
                    $data['status'],
                    $actorId,
                    $data['message']
                    ?? null
                );

            return back()->with(
                'success',
                'Estado actualizado correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'ticket' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'ticket' =>
                        'No fue posible cambiar el estado del ticket.',
                ]);
        }
    }

    public function assign(
        AssignTicketRequest $request,
        string $ticketId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $ticket =
                SupportTicket::findOrFail(
                    $ticketId
                );

            $actorId =
                $this->getUserId(
                    $request
                );

            /*
             * Esta acción corresponde al
             * personal de soporte.
             *
             * Posteriormente deberá protegerse
             * con los permisos del Equipo 1.
             */
            $this->supportTicketService
                ->assignTicket(
                    $ticket,
                    $data['assigned_to'],
                    $actorId
                );

            return back()->with(
                'success',
                'Ticket asignado correctamente.'
            );
        } catch (
        RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'ticket' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'ticket' =>
                        'No fue posible asignar el ticket.',
                ]);
        }
    }

    private function getUserId(
        Request $request
    ): string {
        $user =
            $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'No se encontró un usuario autenticado.'
            );
        }

        return (string)
        $user->getAuthIdentifier();
    }

    private function validateOwner(
        SupportTicket $ticket,
        string $studentId
    ): void {
        if (
            (string) $ticket->student_id
            !==
            $studentId
        ) {
            throw new RuntimeException(
                'No puedes modificar un ticket que pertenece a otro usuario.'
            );
        }
    }

    private function ticketPayload(
        SupportTicket $ticket
    ): array {
        $events =
            TicketEvent::query()
                ->where(
                    'ticket_id',
                    new ObjectId(
                        (string)
                        $ticket->id
                    )
                )
                ->orderBy(
                    'created_at',
                    'asc'
                )
                ->get()
                ->map(
                    fn (TicketEvent $event) =>
                    $this->eventPayload(
                        $event,
                        $ticket
                    )
                )
                ->values();

        return [
            'id' =>
                (string)
                $ticket->id,

            'folio' =>
                $ticket->folio,

            'category' =>
                $ticket->category,

            'subject' =>
                $ticket->subject,

            'description' =>
                $ticket->description,

            'priority' =>
                $ticket->priority,

            'location' =>
                $ticket->location,

            'status' =>
                $ticket->status,

            'assignedTo' =>
                $ticket->assigned_to,

            'evidence' =>
                $ticket->evidence_name,

            'openedAt' =>
                $ticket->opened_at
                    ?->toISOString(),

            'slaDueAt' =>
                $ticket->sla_due_at
                    ?->toISOString(),

            'resolvedAt' =>
                $ticket->resolved_at
                    ?->toISOString(),

            'closedAt' =>
                $ticket->closed_at
                    ?->toISOString(),

            'events' =>
                $events,
        ];
    }

    private function eventPayload(
        TicketEvent $event,
        SupportTicket $ticket
    ): array {
        return [
            'id' =>
                (string)
                $event->id,

            'type' =>
                $this->eventTypeLabel(
                    $event
                ),

            'comment' =>
                $event->message
                ?? '',

            'user' =>
                $this->eventUserLabel(
                    $event,
                    $ticket
                ),

            'createdAt' =>
                $event->created_at
                    ?->toISOString(),
        ];
    }

    private function eventTypeLabel(
        TicketEvent $event
    ): string {
        if (
            $event->event_type ===
            'created'
        ) {
            return 'Creación';
        }

        if (
            $event->event_type ===
            'assigned'
        ) {
            return 'Asignación';
        }

        if (
            $event->event_type ===
            'comment'
        ) {
            return 'Comentario';
        }

        if (
            $event->event_type ===
            'status_changed'
        ) {
            return match (
            $event->to_status
            ) {
                'resolved' =>
                'Resolución',

                'closed' =>
                'Cierre',

                'cancelled' =>
                'Cancelación',

                default =>
                'Cambio de estado',
            };
        }

        return 'Evento';
    }

    private function eventUserLabel(
        TicketEvent $event,
        SupportTicket $ticket
    ): string {
        if (
            (string)
            $event->actor_id
            ===
            (string)
            $ticket->student_id
        ) {
            return 'Estudiante';
        }

        if (
            $ticket->assigned_to
            !== null
        ) {
            return (string)
            $ticket->assigned_to;
        }

        return 'Sistema';
    }
}
