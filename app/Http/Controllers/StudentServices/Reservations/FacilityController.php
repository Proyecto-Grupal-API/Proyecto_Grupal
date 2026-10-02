<?php

namespace App\Http\Controllers\StudentServices\Reservations;

use App\Http\Controllers\Controller;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FacilityController extends Controller
{
    public function index(Request $request): Response
    {
        $studentId = (string) $request->user()->id;

        $facilities = Facility::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Facility $facility) {
                return [
                    'id' => (string) $facility->id,
                    'name' => $facility->name,
                    'type' => $facility->type,
                    'building' => $facility->building,
                    'capacity' => $facility->capacity,
                    'cost_cents' => $facility->cost_cents,
                ];
            })
            ->values();

        $myReservations = Reservation::query()
            ->where('student_id', $studentId)
            ->where('status', '!=', 'cancelled')
            ->where('end_at', '>=', now())
            ->orderBy('start_at')
            ->get()
            ->map(function (Reservation $reservation) use ($facilities) {
                $facility = $facilities->firstWhere(
                    'id',
                    (string) $reservation->facility_id
                );

                return [
                    'id' => (string) $reservation->id,
                    'folio' => $reservation->folio,
                    'facility_id' => (string) $reservation->facility_id,
                    'facility_name' => $facility['name'] ?? 'Instalación',
                    'start_at' => $reservation->start_at->toIso8601String(),
                    'end_at' => $reservation->end_at->toIso8601String(),
                    'status' => $reservation->status,
                ];
            })
            ->values();

        /*
         * Ocupación por instalación y por día (solo reservas CONFIRMADAS,
         * de todos los estudiantes): el frontend la usa para pintar el
         * calendario en verde/amarillo/rojo según capacidad de cada sala.
         */
        $occupancyByFacility = Reservation::query()
            ->where('status', 'confirmed')
            ->get()
            ->groupBy(function (Reservation $reservation) {
                return (string) $reservation->facility_id;
            })
            ->map(function ($reservations) {
                return $reservations
                    ->groupBy(function (Reservation $reservation) {
                        return $reservation->start_at->format('Y-m-d');
                    })
                    ->map(function ($group) {
                        return $group->count();
                    });
            });

        return Inertia::render(
            'student-services/reservations/Index',
            [
                'facilities' => $facilities,
                'myReservations' => $myReservations,
                'occupancyByFacility' => $occupancyByFacility,
            ]
        );
    }
}
