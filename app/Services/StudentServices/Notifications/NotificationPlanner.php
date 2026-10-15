<?php
namespace App\Services\StudentServices\Notifications;

use App\Models\StudentServices\Notifications\NotificationOutbox;
use App\Models\StudentServices\Library\{Loan, BookReservation, LibraryFine};
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\StudentServices\Support\{TicketEvent, SupportTicket};
use App\Services\CatalogoAvisosServicios;

class NotificationPlanner
{
    public const MODELS = [Loan::class, BookReservation::class, LibraryFine::class, LockerAssignment::class,
        Reservation::class, RestBooking::class, Rental::class, ServiceOrder::class, TicketEvent::class];

    public function saving($model): void
    {
        if ($model instanceof LibraryFine && (! $model->exists || $model->isDirty(['amount_cents', 'reason']))) {
            $model->notice_revision = (int) $model->notice_revision + 1;
        }
    }

    public function saved($model): void
    {
        // No HTTP in the student's business operation. The scanner recovers from a failed enqueue.
        try { $this->synchronize($model); } catch (\Throwable $e) { report($e); }
    }

    public function synchronize($m): void
    {
        $keys = [];
        foreach ($this->candidates($m) as $candidate) {
            $keys[] = $candidate['key'];
            NotificationOutbox::firstOrCreate(['key' => $candidate['key']], [
                'source_class' => $m::class, 'source_id' => (string) $m->id, 'payload' => $candidate['payload'],
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'withdraw_pending' => false,
            ]);
        }
        NotificationOutbox::where('source_class', $m::class)->where('source_id', (string) $m->id)
            ->whereNotIn('key', $keys)->whereIn('status', ['pending', 'delivered'])
            ->update(['withdraw_pending' => true, 'next_attempt_at' => now()]);
    }

    public function scan(): int
    {
        $count = 0;
        foreach (self::MODELS as $class) {
            foreach ($class::query()->cursor() as $m) { $this->synchronize($m); $count++; }
        }
        return $count;
    }

    public function applicable(NotificationOutbox $outbox): bool
    {
        $class = $outbox->source_class;
        if (! in_array($class, self::MODELS, true) || ! $m = $class::find($outbox->source_id)) { return false; }
        return in_array($outbox->key, array_column($this->candidates($m), 'key'), true);
    }

    public function candidates($m): array
    {
        $items = [];
        $id = (string) $m->id;
        $student = (string) $m->student_id;
        $add = function (string $tipo, string $asunto, string $texto, string $revision = '', $expira = null) use (&$items, $m, &$student, $id) {
            if ($student === '') { return; }
            $payload = ['destinatario_id' => $student, 'tipo' => $tipo, 'asunto' => $asunto,
                'texto' => mb_substr(trim(strip_tags($texto)), 0, 5000),
                'accion' => ['destino' => CatalogoAvisosServicios::TIPOS[$tipo][0], 'id' => $m instanceof TicketEvent ? (string) $m->ticket_id : $id],
                'importancia' => CatalogoAvisosServicios::TIPOS[$tipo][1] ? 'alta' : 'normal'];
            if ($expira) { $payload['expira_en'] = $expira->toIso8601String(); }
            $items[] = ['key' => 'e5:'.$tipo.':'.hash('sha256', $m::class.':'.$id.':'.$revision), 'payload' => $payload];
        };
        if ($m instanceof Loan && in_array($m->status, ['active', 'overdue'], true) && $m->due_at) {
            if ($m->due_at->lte(now())) { $add('prestamo_vencido', 'Préstamo vencido', 'Tu préstamo venció. Consulta la devolución en Biblioteca.', $m->due_at->toISOString()); }
            elseif ($m->due_at->lte(now()->addDay())) { $add('prestamo_por_vencer', 'Tu préstamo está por vencer', 'Devuelve tu préstamo antes de '.$m->due_at->toIso8601String().'.', $m->due_at->toISOString(), $m->due_at); }
        }
        if ($m instanceof BookReservation && $m->status === 'ready') {
            $add('reserva_libro_disponible', 'Tu libro está disponible', 'Ya hay un ejemplar reservado para ti. Consulta tu reserva.', $m->ready_at?->toISOString() ?? '');
        }
        if ($m instanceof LibraryFine && $m->status === 'pending') {
            $add('multa_generada', 'Multa de biblioteca', 'Tienes una multa de $'.number_format($m->amount_cents / 100, 2, '.', '').' MXN. '.$m->reason,
                (string) $m->amount_cents.':'.(int) $m->notice_revision.':'.hash('sha256', (string) $m->reason));
        }
        if ($m instanceof LockerAssignment && $m->status === 'active' && $m->ends_at?->gt(now()) && $m->ends_at->lte(now()->addDays(3))) {
            $add('locker_por_vencer', 'Tu locker está por vencer', 'Tu periodo de locker termina el '.$m->ends_at->toIso8601String().'.', $m->ends_at->toISOString(), $m->ends_at);
        }
        if ($m instanceof Reservation || $m instanceof RestBooking) {
            if ($m->status === 'confirmed') {
                if ($m->promoted_at) { $add('lista_espera_promovida', 'Tu reserva fue confirmada', 'Se liberó un lugar y tu reserva de la lista de espera ya está confirmada.', $m->promoted_at->toISOString()); }
                if ($m->start_at?->gt(now()) && $m->start_at->lte(now()->addHour())) { $add('recordatorio_reserva', 'Tu reserva está por comenzar', 'Tu reserva comienza el '.$m->start_at->toIso8601String().'.', $m->start_at->toISOString(), $m->start_at); }
            }
            if ($m->status === 'no_show') { $add('no_show_registrado', 'Ausencia registrada', 'Se registró que no te presentaste a tu reserva.', $m->no_show_at?->toISOString() ?? ''); }
        }
        if ($m instanceof Rental && $m->status === 'active' && $m->due_at?->gt(now()) && $m->due_at->lte(now()->addHours(2))) {
            $add('renta_por_devolver', 'Tu renta está por terminar', 'Devuelve tu equipo antes de '.$m->due_at->toIso8601String().'.', $m->due_at->toISOString(), $m->due_at);
        }
        if ($m instanceof ServiceOrder && $m->status === 'ready') { $add('orden_lista', 'Tu orden está lista', 'Tu orden '.$m->folio.' está lista para recoger.', $m->ready_at?->toISOString() ?? ''); }
        if ($m instanceof TicketEvent && in_array($m->event_type, ['status_changed', 'assigned', 'comment'], true)) {
            $ticket = SupportTicket::find((string) $m->ticket_id);
            if ($ticket && (string) $m->actor_id !== (string) $ticket->student_id) {
                $student = (string) $ticket->student_id;
                $add('ticket_actualizado', 'Tu ticket de soporte fue actualizado', 'Ticket '.$ticket->folio.': '.($m->message ?: 'Nuevo estado: '.$m->to_status));
            }
        }
        return $items;
    }
}
