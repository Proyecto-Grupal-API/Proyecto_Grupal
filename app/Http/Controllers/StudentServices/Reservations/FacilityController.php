<?php

namespace App\Http\Controllers\StudentServices\Reservations;

use App\Http\Controllers\Controller;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Services\StudentServices\Calendars\AvailabilityService;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use App\Services\StudentServices\Calendars\BookingStatus;
use App\Services\StudentServices\Calendars\CalendarRuleChecker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FacilityController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
        private CalendarRuleChecker $checker
    ) {}

    public function index(Request $request): Response
    {
        $this->bookings->refreshStatuses(BookableResources::FACILITY);

        $studentId = (string) $request->user()->id;

        $facilityModels = Facility::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $rules = $this->availability->rulesForMany(BookableResources::FACILITY, $facilityModels);

        $facilities = $facilityModels
            ->map(fn (Facility $facility): array => [
                'id' => (string) $facility->id,
                'name' => $facility->name,
                'type' => $facility->type,
                'building' => $facility->building,
                'capacity' => $facility->capacity,
                'cost_cents' => $facility->cost_cents,
                'rules' => $rules[(string) $facility->id],
            ])
            ->values();

        $facilityNames = $facilityModels->mapWithKeys(
            fn (Facility $facility): array => [(string) $facility->id => $facility->name]
        );

        $reservations = Reservation::query()
            ->where('student_id', $studentId)
            ->orderBy('start_at', 'desc')
            ->limit(60)
            ->get();

        $now = now();

        $isActive = fn (Reservation $reservation): bool => in_array($reservation->status, BookingStatus::ACTIVE, true)
            && $reservation->end_at->greaterThan($now);

        $myReservations = $reservations
            ->filter($isActive)
            ->sortBy(fn (Reservation $reservation): int => $reservation->start_at->getTimestamp())
            ->map(fn (Reservation $reservation): array => $this->reservationPayload($reservation, $facilityNames->all(), $rules))
            ->values();

        $history = $reservations
            ->reject($isActive)
            ->take(10)
            ->map(fn (Reservation $reservation): array => $this->reservationPayload($reservation, $facilityNames->all(), $rules))
            ->values();

        $maxAdvanceDays = max([1, ...array_map(fn (array $rule): int => (int) $rule['max_advance_days'], $rules)]);
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->addDays($maxAdvanceDays + 1)->endOfDay();
        $ids = array_values($facilityModels->map(fn (Facility $facility): string => $facility->id)->all());

        return Inertia::render(
            'student-services/reservations/Index',
            [
                'facilities' => $facilities,
                'myReservations' => $myReservations,
                'history' => $history,
                'bookedRanges' => $this->availability->bookedRanges(BookableResources::FACILITY, $ids, $from, $to),
                'blocks' => $this->availability->blocksByResource(BookableResources::FACILITY, $ids, $from, $to),
                'statusLabels' => BookingStatus::labels(),
            ]
        );
    }

    /**
     * @param  array<string, string>  $facilityNames
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function reservationPayload(Reservation $reservation, array $facilityNames, array $rules): array
    {
        $facilityId = (string) $reservation->facility_id;
        $facilityRules = $rules[$facilityId] ?? BookableResources::definition(BookableResources::FACILITY)['defaults'];

        $canCancel = $reservation->status === BookingStatus::WAITLISTED
            || ($reservation->status === BookingStatus::CONFIRMED
                && $this->checker->canStudentCancel($facilityRules, $reservation->start_at, now()));

        return [
            'id' => (string) $reservation->id,
            'folio' => $reservation->folio,
            'facility_id' => $facilityId,
            'facility_name' => $facilityNames[$facilityId] ?? 'Instalación',
            'start_at' => $reservation->start_at->toIso8601String(),
            'end_at' => $reservation->end_at->toIso8601String(),
            'status' => $reservation->status,
            'waitlist_position' => $this->availability->waitlistPosition(BookableResources::FACILITY, $reservation),
            'can_cancel' => $canCancel,
            'cancellation_reason' => $reservation->cancellation_reason,
        ];
    }
}
