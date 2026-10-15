<?php
namespace App\Http\Middleware;

use App\Services\StudentServices\ServiceAuthority;
use Closure;
use Illuminate\Http\Request;

class AutorizarServicios
{
    public function handle(Request $r, Closure $next)
    {
        $name = str_replace('module5.', '', $r->route()->getName());
        if (is_string($r->input('student_id')) && preg_match('/^[a-f0-9]{24}$/i', $r->input('student_id'))) { $r->merge(['student_id' => strtolower($r->input('student_id'))]); }
        $staff = app(ServiceAuthority::class)->staff($r->user());
        $payment = in_array($name, ['library.fines.pay', 'lockers.requests.pay', 'services.pay'], true);
        if ($payment) {
            $message = 'El pago está pendiente de la API de cartera del equipo 2. No se realizó ningún cobro.';
            return $r->expectsJson() ? response()->json(['message' => $message], 503) : back()->withErrors(['pago' => $message]);
        }
        $staffPages = ['lockers.periods.index', 'lockers.access.index', 'library.copies.index', 'service-access.index'];
        if ($r->isMethod('GET') && ! in_array($name, $staffPages, true)) { return $next($r); }
        if ($staff) {
            if (in_array($name, ['library.loans.store', 'library.reservations.store', 'lockers.assignments.sponsored'], true) && $r->filled('student_id')) {
                $r->validate(['student_id' => ['required', 'string', 'max:128']]);
                if ($error = $this->estudiante($r, $r->input('student_id'))) { return $error; }
            }
            return $next($r);
        }
        $allowed = ['lockers.requests.store', 'lockers.requests.cancel', 'library.reservations.store', 'library.reservations.cancel',
            'library.loans.renew', 'reservations.store', 'reservations.cancel', 'rest-spaces.bookings.store', 'rest-spaces.bookings.cancel',
            'rentals.store', 'rentals.cancel', 'services.store', 'services.cancel', 'services.pay-scholarship', 'support.store', 'support.comment', 'support.cancel'];
        abort_unless(in_array($name, $allowed, true), 403, 'Esta operación requiere personal autorizado de Servicios.');
        // The beneficiary of a self-service operation comes from the E1 authenticated session.
        $r->merge(['student_id' => (string) $r->user()->id]);
        if (in_array($name, ['lockers.requests.store', 'library.reservations.store', 'reservations.store', 'rest-spaces.bookings.store', 'rentals.store', 'services.store'], true)) {
            if ($error = $this->estudiante($r, (string) $r->user()->id)) { return $error; }
        }
        $models = [
            'requestId' => \App\Models\StudentServices\Lockers\LockerRequest::class,
            'reservationId' => \App\Models\StudentServices\Library\BookReservation::class,
            'loanId' => \App\Models\StudentServices\Library\Loan::class,
            'rentalId' => \App\Models\StudentServices\Rentals\Rental::class,
            'orderId' => \App\Models\StudentServices\Services\ServiceOrder::class,
            'ticketId' => \App\Models\StudentServices\Support\SupportTicket::class,
            'bookingId' => \App\Models\StudentServices\RestSpaces\RestBooking::class,
        ];
        if (str_starts_with($name, 'reservations.')) { $models['reservationId'] = \App\Models\StudentServices\Reservations\Reservation::class; }
        foreach ($models as $param => $model) {
            if ($id = $r->route($param)) {
                abort_unless($model::where('student_id', (string) $r->user()->id)->where('_id', $id)->exists(), 404);
            }
        }
        return $next($r);
    }
    private function estudiante(Request $r, string $id)
    {
        try {
            $status = app(\App\Services\StudentServices\Benefits\StudentDirectory::class)->status($id);
            if ($status === 'active') { return null; }
            $message = 'Identidad no reconoce un perfil estudiantil activo para esta solicitud.';
            $code = 422;
        } catch (\Throwable) {
            $message = 'No fue posible confirmar el perfil con Identidad. Intenta nuevamente.';
            $code = 503;
        }
        return $r->expectsJson() ? response()->json(['message' => $message], $code) : back()->withErrors(['estudiante' => $message]);
    }

}
