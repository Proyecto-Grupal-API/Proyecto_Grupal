<?php

namespace App\Http\Controllers\StudentServices\RestSpaces;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\RestSpaces\StoreRestSpaceRequest;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Calendars\AvailabilityService;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use App\Services\StudentServices\Calendars\BookingStatus;
use App\Services\StudentServices\Calendars\CalendarRuleChecker;
use App\Services\StudentServices\RestSpaces\RestBookingService;
use App\Services\StudentServices\RestSpaces\RestSpaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Modulo 5.6 - Zonas de descanso.
 *
 * Alta de espacios y mantenimiento quedan abiertos a cualquier usuario
 * autenticado hasta que el Equipo 1 entregue roles/policies.
 */
class RestSpaceController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
        private CalendarRuleChecker $checker,
        private RestBookingService $restBookings,
        private RestSpaceService $spaces
    ) {}

    public function index(Request $request): Response
    {
        $this->bookings->refreshStatuses(BookableResources::REST_SPACE);

        $studentId = (string) $request->user()->getAuthIdentifier();

        $spaceModels = RestSpace::query()
            ->where('active', '!=', false)
            ->orderBy('code')
            ->get();

        $rules = $this->availability->rulesForMany(BookableResources::REST_SPACE, $spaceModels);

        $spaces = $spaceModels
            ->map(fn (RestSpace $space): array => [
                'id' => (string) $space->id,
                'code' => $space->code,
                'name' => $space->name,
                'type' => $space->type,
                'location' => $space->location,
                'capacity' => (int) $space->capacity,
                'description' => (string) $space->description,
                'status' => $this->restBookings->liveStatus($space),
                'rules' => $rules[(string) $space->id],
            ])
            ->values();

        $names = $spaceModels->mapWithKeys(fn (RestSpace $space): array => [(string) $space->id => $space->name])->all();

        $bookings = RestBooking::query()
            ->where('student_id', $studentId)
            ->orderBy('start_at', 'desc')
            ->limit(30)
            ->get()
            ->map(fn (RestBooking $booking): array => $this->bookingPayload($booking, $names, $rules))
            ->values();

        $maxAdvanceDays = max([1, ...array_map(fn (array $rule): int => (int) $rule['max_advance_days'], $rules)]);
        $from = now()->startOfDay();
        $to = now()->addDays($maxAdvanceDays + 1)->endOfDay();
        $ids = $spaces->pluck('id')->all();

        return Inertia::render('student-services/rest-spaces/Index', [
            'spaces' => $spaces,
            'bookings' => $bookings,
            'bookedRanges' => $this->availability->bookedRanges(BookableResources::REST_SPACE, $ids, $from, $to),
            'blocks' => $this->availability->blocksByResource(BookableResources::REST_SPACE, $ids, $from, $to),
            'spaceTypes' => RestSpace::TYPES,
            'statusLabels' => BookingStatus::labels(),
        ]);
    }

    public function store(StoreRestSpaceRequest $request): RedirectResponse
    {
        try {
            $this->spaces->create($request->validated());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['space' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Espacio registrado.');
    }

    public function markMaintenance(string $spaceId): RedirectResponse
    {
        $space = RestSpace::findOrFail($spaceId);

        try {
            $cancelled = $this->spaces->markMaintenance($space);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['space' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            $cancelled > 0
                ? "Espacio en mantenimiento. Se cancelaron {$cancelled} reserva(s)."
                : 'Espacio en mantenimiento.'
        );
    }

    public function restoreAvailable(string $spaceId): RedirectResponse
    {
        $space = RestSpace::findOrFail($spaceId);

        try {
            $this->spaces->restoreAvailable($space);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['space' => $exception->getMessage()]);
        }

        return back()->with('success', 'Espacio disponible de nuevo.');
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function bookingPayload(RestBooking $booking, array $names, array $rules): array
    {
        $spaceId = (string) $booking->rest_space_id;
        $spaceRules = $rules[$spaceId] ?? BookableResources::definition(BookableResources::REST_SPACE)['defaults'];

        $canCancel = $booking->status === BookingStatus::WAITLISTED
            || ($booking->status === BookingStatus::CONFIRMED
                && $this->checker->canStudentCancel($spaceRules, $booking->start_at, now()));

        return [
            'id' => (string) $booking->id,
            'folio' => $booking->folio,
            'space_id' => $spaceId,
            'space_name' => $names[$spaceId] ?? 'Espacio',
            'start_at' => $booking->start_at->toIso8601String(),
            'end_at' => $booking->end_at->toIso8601String(),
            'status' => $booking->status,
            'waitlist_position' => $this->availability->waitlistPosition(BookableResources::REST_SPACE, $booking),
            'can_cancel' => $canCancel,
            'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
            'cancellation_reason' => $booking->cancellation_reason,
        ];
    }
}
