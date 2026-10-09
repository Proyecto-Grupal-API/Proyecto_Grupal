<?php

namespace App\Http\Controllers\StudentServices\Calendars;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Calendars\StoreCalendarBlockRequest;
use App\Http\Requests\StudentServices\Calendars\UpdateCalendarRulesRequest;
use App\Models\StudentServices\Calendars\CalendarBlock;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Calendars\AvailabilityService;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use App\Services\StudentServices\Calendars\BookingStatus;
use App\Services\StudentServices\Calendars\CalendarSettingsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\Laravel\Eloquent\Model;
use RuntimeException;

/**
 * Modulo 5.10 - Panel de calendarios, cupos y reglas.
 *
 * Por ahora las acciones quedan abiertas a cualquier usuario
 * autenticado (igual que el resto de acciones de operador del Equipo 5);
 * cuando el Equipo 1 entregue roles/policies se deben proteger.
 */
class AvailabilityController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
        private CalendarSettingsService $settings
    ) {}

    public function index(): Response
    {
        $resources = [];
        $names = [];
        $waitlist = [];

        foreach (BookableResources::types() as $type) {
            $this->bookings->refreshStatuses($type);

            $resourceModel = BookableResources::resourceModel($type);
            $models = $resourceModel::query()->orderBy('name')->get();
            $rules = $this->availability->rulesForMany($type, $models);

            foreach ($models as $model) {
                $id = (string) $model->getKey();
                $names[$type.':'.$id] = $model->name;
                $resources[] = [
                    'key' => $type.':'.$id,
                    'id' => $id,
                    'resource_type' => $type,
                    'resource_label' => BookableResources::definition($type)['label'],
                    ...$this->describe($type, $model),
                    'capacity' => (int) $model->capacity,
                    'rules' => $rules[$id],
                ];
            }

            $waitlist = [...$waitlist, ...$this->waitlistEntries($type, $names)];
        }

        usort($waitlist, fn (array $a, array $b): int => strcmp($a['start_at'], $b['start_at']));

        $blocks = CalendarBlock::query()
            ->where('end_at', '>=', now()->startOfDay())
            ->orderBy('start_at')
            ->get()
            ->map(fn (CalendarBlock $block): array => [
                'id' => (string) $block->id,
                'folio' => $block->folio,
                'resource_key' => $block->resource_type.':'.$block->resource_id,
                'resource_name' => $names[$block->resource_type.':'.$block->resource_id] ?? 'Recurso eliminado',
                'start_at' => $block->start_at->toIso8601String(),
                'end_at' => $block->end_at->toIso8601String(),
                'reason' => $block->reason,
                'cancelled_bookings' => (int) $block->cancelled_bookings,
            ])
            ->values();

        return Inertia::render('student-services/availability/Index', [
            'resources' => $resources,
            'blocks' => $blocks,
            'waitlist' => $waitlist,
        ]);
    }

    public function updateRules(
        UpdateCalendarRulesRequest $request,
        string $resourceType,
        string $resourceId
    ): RedirectResponse {
        $resource = BookableResources::findResource($resourceType, $resourceId);

        if ($resource === null) {
            return back()->withErrors(['rules' => 'El recurso no existe.']);
        }

        try {
            $this->settings->updateRules(
                $resourceType,
                $resource,
                $request->validated(),
                (string) $request->user()->getAuthIdentifier()
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['rules' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reglas actualizadas.');
    }

    public function storeBlock(StoreCalendarBlockRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $resource = BookableResources::findResource($data['resource_type'], $data['resource_id']);

        if ($resource === null) {
            return back()->withErrors(['block' => 'El recurso no existe.']);
        }

        try {
            $result = $this->settings->createBlock(
                $data['resource_type'],
                $resource,
                Carbon::parse($data['date'].' '.$data['start_time']),
                Carbon::parse($data['date'].' '.$data['end_time']),
                $data['reason'],
                (string) $request->user()->getAuthIdentifier()
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['block' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            $result['cancelled'] > 0
                ? "Bloqueo creado. Se cancelaron {$result['cancelled']} reserva(s) afectadas."
                : 'Bloqueo creado.'
        );
    }

    public function destroyBlock(string $blockId): RedirectResponse
    {
        $block = $this->settings->findBlock($blockId);

        if ($block === null) {
            return back()->withErrors(['block' => 'El bloqueo no existe.']);
        }

        $this->settings->deleteBlock($block);

        return back()->with('success', 'Bloqueo eliminado.');
    }

    public function promote(string $resourceType, string $bookingId): RedirectResponse
    {
        $booking = $this->bookings->findBooking($resourceType, $bookingId);

        if ($booking === null) {
            return back()->withErrors(['waitlist' => 'La solicitud no existe.']);
        }

        try {
            $this->bookings->promote($resourceType, $booking);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['waitlist' => $exception->getMessage()]);
        }

        return back()->with('success', 'Solicitud promovida a confirmada.');
    }

    public function cancelWaitlist(Request $request, string $resourceType, string $bookingId): RedirectResponse
    {
        $booking = $this->bookings->findBooking($resourceType, $bookingId);

        if ($booking === null) {
            return back()->withErrors(['waitlist' => 'La solicitud no existe.']);
        }

        if ($booking->status !== BookingStatus::WAITLISTED) {
            return back()->withErrors(['waitlist' => 'La solicitud ya no está en lista de espera.']);
        }

        try {
            $this->bookings->cancel($resourceType, $booking, 'staff', 'Cancelada desde la lista de espera');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['waitlist' => $exception->getMessage()]);
        }

        return back()->with('success', 'Solicitud cancelada.');
    }

    /**
     * @return array{name: string, category: string, location: string, active: bool}
     */
    private function describe(string $type, Model $model): array
    {
        if ($type === BookableResources::REST_SPACE) {
            return [
                'name' => (string) $model->name,
                'category' => RestSpace::TYPES[$model->type] ?? (string) $model->type,
                'location' => (string) $model->location,
                'active' => (bool) ($model->active ?? true) && $model->status !== 'maintenance',
            ];
        }

        return [
            'name' => (string) $model->name,
            'category' => (string) $model->type,
            'location' => (string) $model->building,
            'active' => (bool) $model->active,
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function waitlistEntries(string $type, array $names): array
    {
        $bookingModel = BookableResources::bookingModel($type);
        $foreignKey = BookableResources::foreignKey($type);

        return $bookingModel::query()
            ->whereNotNull('waitlisted_at')
            ->where('end_at', '>=', now()->subDays(7))
            ->get()
            ->map(function (Model $booking) use ($foreignKey, $names, $type): array {
                $key = $type.':'.$booking->{$foreignKey};

                $state = match (true) {
                    $booking->status === BookingStatus::WAITLISTED => 'waiting',
                    $booking->promoted_at !== null => 'promoted',
                    $booking->status === BookingStatus::EXPIRED => 'expired',
                    default => 'cancelled',
                };

                return [
                    'id' => (string) $booking->getKey(),
                    'folio' => $booking->folio,
                    'resource_type' => $type,
                    'resource_key' => $key,
                    'resource_name' => $names[$key] ?? 'Recurso eliminado',
                    'student_id' => (string) $booking->student_id,
                    'start_at' => $booking->start_at->toIso8601String(),
                    'end_at' => $booking->end_at->toIso8601String(),
                    'position' => $this->availability->waitlistPosition($type, $booking),
                    'status' => $state,
                ];
            })
            ->values()
            ->all();
    }
}
