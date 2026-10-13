<?php

use App\Http\Controllers\StudentServices\Benefits\PrintScholarshipPaymentController;
use App\Http\Controllers\StudentServices\Calendars\AvailabilityController;
use App\Http\Controllers\StudentServices\Library\BookController;
use App\Http\Controllers\StudentServices\Library\BookCopyController;
use App\Http\Controllers\StudentServices\Library\BookReservationController;
use App\Http\Controllers\StudentServices\Library\LibraryFineController;
use App\Http\Controllers\StudentServices\Library\LoanController;
use App\Http\Controllers\StudentServices\Lockers\LockerAccessController;
use App\Http\Controllers\StudentServices\Lockers\LockerAssignmentController;
use App\Http\Controllers\StudentServices\Lockers\LockerController;
use App\Http\Controllers\StudentServices\Lockers\LockerPeriodController;
use App\Http\Controllers\StudentServices\Lockers\LockerRequestController;
use App\Http\Controllers\StudentServices\Rentals\RentalController;
use App\Http\Controllers\StudentServices\Reservations\FacilityController;
use App\Http\Controllers\StudentServices\Reservations\ReservationController;
use App\Http\Controllers\StudentServices\RestSpaces\RestBookingController;
use App\Http\Controllers\StudentServices\RestSpaces\RestSpaceController;
use App\Http\Controllers\StudentServices\ServiceAccess\ServiceAccessController;
use App\Http\Controllers\StudentServices\Services\ServiceOrderController;
use App\Http\Controllers\StudentServices\Support\SupportTicketController;
use App\Services\StudentServices\Calendars\BookableResources;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('servicios-estudiante')
    ->name('student-services.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Inicio
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/',
            'student-services/Index'
        )->name('index');

        /*
        |--------------------------------------------------------------------------
        | Lockers - Periodos (5.3)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/lockers/periodos',
            [LockerPeriodController::class, 'index']
        )->name('lockers.periods.index');

        Route::post(
            '/lockers/periodos',
            [LockerPeriodController::class, 'store']
        )->name('lockers.periods.store');

        Route::patch(
            '/lockers/periodos/{periodId}',
            [LockerPeriodController::class, 'update']
        )->name('lockers.periods.update');

        Route::patch(
            '/lockers/periodos/{periodId}/cerrar',
            [LockerPeriodController::class, 'close']
        )->name('lockers.periods.close');

        /*
        |--------------------------------------------------------------------------
        | Lockers - Solicitudes (5.4)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/lockers/solicitudes',
            [LockerRequestController::class, 'index']
        )->name('lockers.requests.index');

        Route::post(
            '/lockers/solicitudes',
            [LockerRequestController::class, 'store']
        )->name('lockers.requests.store');

        Route::patch(
            '/lockers/solicitudes/{requestId}/pagar',
            [LockerRequestController::class, 'pay']
        )->name('lockers.requests.pay');

        Route::patch(
            '/lockers/solicitudes/{requestId}/cancelar',
            [LockerRequestController::class, 'cancel']
        )->name('lockers.requests.cancel');

        Route::patch(
            '/lockers/solicitudes/{requestId}/asignar',
            [LockerRequestController::class, 'assign']
        )->name('lockers.requests.assign');

        /*
        |--------------------------------------------------------------------------
        | Lockers - Asignaciones (5.4)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/lockers/asignaciones',
            [LockerAssignmentController::class, 'index']
        )->name('lockers.assignments.index');

        Route::post(
            '/lockers/asignaciones/beca',
            [LockerAssignmentController::class, 'storeSponsored']
        )->name('lockers.assignments.sponsored');

        Route::patch(
            '/lockers/asignaciones/{assignmentId}/renovar',
            [LockerAssignmentController::class, 'renew']
        )->name('lockers.assignments.renew');

        Route::patch(
            '/lockers/asignaciones/{assignmentId}/liberar',
            [LockerAssignmentController::class, 'release']
        )->name('lockers.assignments.release');

        /*
        |--------------------------------------------------------------------------
        | Lockers - Acceso QR/NFC (5.4)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/lockers/acceso',
            [LockerAccessController::class, 'index']
        )->name('lockers.access.index');

        Route::post(
            '/lockers/acceso/validar',
            [LockerAccessController::class, 'check']
        )->name('lockers.access.check');

        /*
        |--------------------------------------------------------------------------
        | Lockers - Catálogo (5.3)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/lockers',
            [LockerController::class, 'index']
        )->name('lockers.index');

        Route::post(
            '/lockers',
            [LockerController::class, 'store']
        )->name('lockers.store');

        Route::patch(
            '/lockers/{lockerId}',
            [LockerController::class, 'update']
        )->name('lockers.update');

        Route::patch(
            '/lockers/{lockerId}/mantenimiento',
            [LockerController::class, 'markMaintenance']
        )->name('lockers.maintenance');

        Route::patch(
            '/lockers/{lockerId}/disponible',
            [LockerController::class, 'restoreAvailable']
        )->name('lockers.available');

        /*
        |--------------------------------------------------------------------------
        | Reservas de instalaciones (5.5 + 5.10)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/reservas',
            [FacilityController::class, 'index']
        )->name('reservations.index');

        Route::post(
            '/reservas',
            [ReservationController::class, 'store']
        )->name('reservations.store');

        Route::patch(
            '/reservas/{reservationId}/cancelar',
            [ReservationController::class, 'cancel']
        )->name('reservations.cancel');

        /*
        |--------------------------------------------------------------------------
        | Biblioteca - Catálogo (5.1)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/biblioteca',
            [BookController::class, 'index']
        )->name('library.index');

        Route::post(
            '/biblioteca/libros',
            [BookController::class, 'store']
        )->name('library.books.store');

        Route::patch(
            '/biblioteca/libros/{bookId}',
            [BookController::class, 'update']
        )->name('library.books.update');

        Route::patch(
            '/biblioteca/libros/{bookId}/desactivar',
            [BookController::class, 'deactivate']
        )->name('library.books.deactivate');

        /*
        |--------------------------------------------------------------------------
        | Biblioteca - Ejemplares
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/biblioteca/ejemplares',
            [BookCopyController::class, 'index']
        )->name('library.copies.index');

        Route::post(
            '/biblioteca/ejemplares',
            [BookCopyController::class, 'store']
        )->name('library.copies.store');

        Route::patch(
            '/biblioteca/ejemplares/{copyId}',
            [BookCopyController::class, 'update']
        )->name('library.copies.update');

        Route::patch(
            '/biblioteca/ejemplares/{copyId}/mantenimiento',
            [BookCopyController::class, 'markMaintenance']
        )->name('library.copies.maintenance');

        Route::patch(
            '/biblioteca/ejemplares/{copyId}/extraviado',
            [BookCopyController::class, 'markLost']
        )->name('library.copies.lost');

        Route::patch(
            '/biblioteca/ejemplares/{copyId}/disponible',
            [BookCopyController::class, 'restoreAvailable']
        )->name('library.copies.available');

        /*
        |--------------------------------------------------------------------------
        | Zonas de descanso (5.6) - usa el motor de calendarios 5.10
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/zonas-descanso',
            [RestSpaceController::class, 'index']
        )->name('rest-spaces.index');

        Route::post(
            '/zonas-descanso/reservas',
            [RestBookingController::class, 'store']
        )->name('rest-spaces.bookings.store');

        Route::patch(
            '/zonas-descanso/reservas/{bookingId}/cancelar',
            [RestBookingController::class, 'cancel']
        )->name('rest-spaces.bookings.cancel');

        Route::post(
            '/zonas-descanso/espacios',
            [RestSpaceController::class, 'store']
        )->name('rest-spaces.store');

        Route::patch(
            '/zonas-descanso/espacios/{spaceId}/mantenimiento',
            [RestSpaceController::class, 'markMaintenance']
        )->name('rest-spaces.maintenance');

        Route::patch(
            '/zonas-descanso/espacios/{spaceId}/disponible',
            [RestSpaceController::class, 'restoreAvailable']
        )->name('rest-spaces.available');

        /*
        |--------------------------------------------------------------------------
        | Calendarios, cupos y reglas (5.10)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/calendarios-cupos',
            [AvailabilityController::class, 'index']
        )->name('availability.index');

        Route::patch(
            '/calendarios-cupos/{resourceType}/{resourceId}/reglas',
            [AvailabilityController::class, 'updateRules']
        )->whereIn('resourceType', BookableResources::types())
            ->name('availability.rules.update');

        Route::post(
            '/calendarios-cupos/bloqueos',
            [AvailabilityController::class, 'storeBlock']
        )->name('availability.blocks.store');

        Route::delete(
            '/calendarios-cupos/bloqueos/{blockId}',
            [AvailabilityController::class, 'destroyBlock']
        )->name('availability.blocks.destroy');

        Route::patch(
            '/calendarios-cupos/espera/{resourceType}/{bookingId}/promover',
            [AvailabilityController::class, 'promote']
        )->whereIn('resourceType', BookableResources::types())
            ->name('availability.waitlist.promote');

        Route::patch(
            '/calendarios-cupos/espera/{resourceType}/{bookingId}/cancelar',
            [AvailabilityController::class, 'cancelWaitlist']
        )->whereIn('resourceType', BookableResources::types())
            ->name('availability.waitlist.cancel');

        /*
        |--------------------------------------------------------------------------
        | Validación de acceso y uso (5.11)
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/validacion-servicios',
            [ServiceAccessController::class, 'index']
        )->name('service-access.index');

        Route::post(
            '/validacion-servicios/validar',
            [ServiceAccessController::class, 'validateAccess']
        )->name('service-access.validate');

        /*
|--------------------------------------------------------------------------
| Biblioteca - Préstamos
|--------------------------------------------------------------------------
*/

        Route::get(
            '/biblioteca/prestamos',
            [LoanController::class, 'index']
        )->name('library.loans.index');

        Route::post(
            '/biblioteca/prestamos',
            [LoanController::class, 'store']
        )->name('library.loans.store');

        Route::patch(
            '/biblioteca/prestamos/{loanId}/renovar',
            [LoanController::class, 'renew']
        )->name('library.loans.renew');

        Route::patch(
            '/biblioteca/prestamos/{loanId}/devolver',
            [LoanController::class, 'returnBook']
        )->name('library.loans.return');

        /*
|--------------------------------------------------------------------------
| Biblioteca - Reservas
|--------------------------------------------------------------------------
*/

        Route::get(
            '/biblioteca/reservas',
            [BookReservationController::class, 'index']
        )->name('library.reservations.index');

        Route::post(
            '/biblioteca/reservas',
            [BookReservationController::class, 'store']
        )->name('library.reservations.store');

        Route::patch(
            '/biblioteca/reservas/{reservationId}/asignar',
            [BookReservationController::class, 'assign']
        )->name('library.reservations.assign');

        Route::patch(
            '/biblioteca/reservas/{reservationId}/completar',
            [BookReservationController::class, 'fulfill']
        )->name('library.reservations.fulfill');

        Route::patch(
            '/biblioteca/reservas/{reservationId}/cancelar',
            [BookReservationController::class, 'cancel']
        )->name('library.reservations.cancel');

        Route::patch(
            '/biblioteca/reservas/{reservationId}/expirar',
            [BookReservationController::class, 'expire']
        )->name('library.reservations.expire');

        /*
|--------------------------------------------------------------------------
| Biblioteca - Multas
|--------------------------------------------------------------------------
*/

        Route::get(
            '/biblioteca/multas',
            [LibraryFineController::class, 'index']
        )->name('library.fines.index');

        Route::post(
            '/biblioteca/multas',
            [LibraryFineController::class, 'store']
        )->name('library.fines.store');

        Route::patch(
            '/biblioteca/multas/{fineId}/pagar',
            [LibraryFineController::class, 'pay']
        )->name('library.fines.pay');

        Route::patch(
            '/biblioteca/multas/{fineId}/condonar',
            [LibraryFineController::class, 'waive']
        )->name('library.fines.waive');

        Route::patch(
            '/biblioteca/multas/{fineId}/cancelar',
            [LibraryFineController::class, 'cancel']
        )->name('library.fines.cancel');

        /*
|--------------------------------------------------------------------------
| Renta de equipos - Módulo 5.7
|--------------------------------------------------------------------------
*/

        Route::get(
            '/renta-equipos',
            [RentalController::class, 'index']
        )->name('rentals.index');

        Route::post(
            '/renta-equipos',
            [RentalController::class, 'store']
        )->name('rentals.store');

        Route::patch(
            '/renta-equipos/{rentalId}/cancelar',
            [RentalController::class, 'cancel']
        )->name('rentals.cancel');

        Route::patch(
            '/renta-equipos/{rentalId}/devolver',
            [RentalController::class, 'returnRental']
        )->name('rentals.return');

        /*
|--------------------------------------------------------------------------
| Servicios e impresiones - Módulo 5.8
|--------------------------------------------------------------------------
*/

        Route::get(
            '/servicios-impresiones',
            [ServiceOrderController::class, 'index']
        )->name('services.index');

        Route::post(
            '/servicios-impresiones',
            [ServiceOrderController::class, 'store']
        )->name('services.store');

        Route::patch(
            '/servicios-impresiones/{orderId}/pagar',
            [ServiceOrderController::class, 'pay']
        )->name('services.pay');

        Route::patch(
            '/servicios-impresiones/{orderId}/pagar-con-beca',
            [PrintScholarshipPaymentController::class, 'pay']
        )->name('services.pay-scholarship');

        Route::patch(
            '/servicios-impresiones/{orderId}/cancelar',
            [ServiceOrderController::class, 'cancel']
        )->name('services.cancel');

        Route::patch(
            '/servicios-impresiones/{orderId}/listo',
            [ServiceOrderController::class, 'ready']
        )->name('services.ready');

        Route::patch(
            '/servicios-impresiones/{orderId}/entregar',
            [ServiceOrderController::class, 'deliver']
        )->name('services.deliver');

        /*
|--------------------------------------------------------------------------
| Tickets de soporte - Módulo 5.9
|--------------------------------------------------------------------------
*/

        Route::get(
            '/soporte',
            [SupportTicketController::class, 'index']
        )->name('support.index');

        Route::post(
            '/soporte',
            [SupportTicketController::class, 'store']
        )->name('support.store');

        Route::post(
            '/soporte/{ticketId}/comentarios',
            [SupportTicketController::class, 'comment']
        )->name('support.comment');

        Route::patch(
            '/soporte/{ticketId}/cancelar',
            [SupportTicketController::class, 'cancel']
        )->name('support.cancel');

        /*
        |--------------------------------------------------------------------------
        | Acciones de operador / administrador
        |--------------------------------------------------------------------------
        |
        | Por ahora quedan funcionales, pero cuando integremos roles
        | del Equipo 1 habrá que protegerlas con permisos.
        |
        */

        Route::patch(
            '/soporte/{ticketId}/estado',
            [SupportTicketController::class, 'changeStatus']
        )->name('support.status');

        Route::patch(
            '/soporte/{ticketId}/asignar',
            [SupportTicketController::class, 'assign']
        )->name('support.assign');

    });
