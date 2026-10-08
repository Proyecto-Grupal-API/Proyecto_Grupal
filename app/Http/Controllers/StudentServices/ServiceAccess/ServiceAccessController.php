<?php

namespace App\Http\Controllers\StudentServices\ServiceAccess;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\ServiceAccess\ValidateServiceAccessRequest;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Services\StudentServices\ServiceAccess\ServiceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modulo 5.11 - Validación de acceso y uso (punto de escaneo).
 *
 * Es una pantalla de operador; quedará protegida con los roles del
 * Equipo 1 cuando estén disponibles.
 */
class ServiceAccessController extends Controller
{
    public function __construct(
        private ServiceAccessService $access
    ) {}

    public function index(Request $request): Response
    {
        $today = now()->startOfDay();

        $events = ServiceCheckin::query()
            ->orderBy('scanned_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn (ServiceCheckin $checkin): array => $this->payload($checkin))
            ->values();

        $todayQuery = fn () => ServiceCheckin::query()->where('scanned_at', '>=', $today);

        return Inertia::render('student-services/service-access/Index', [
            'events' => $events,
            'stats' => [
                'today' => $todayQuery()->count(),
                'granted' => $todayQuery()->where('granted', true)->count(),
                'denied' => $todayQuery()->where('granted', false)->count(),
            ],
            'operations' => ServiceAccessService::OPERATIONS,
            'result' => $request->session()->get('access_result'),
        ]);
    }

    public function validateAccess(ValidateServiceAccessRequest $request): RedirectResponse
    {
        $checkin = $this->access->validate(
            $request->validated(),
            (string) $request->user()->getAuthIdentifier()
        );

        return back()->with('access_result', $this->payload($checkin));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ServiceCheckin $checkin): array
    {
        return [
            'id' => (string) $checkin->id,
            'folio' => $checkin->folio,
            'student_id' => $checkin->student_id,
            'student_name' => $checkin->student_name,
            'credential' => $checkin->credential_hint,
            'service' => $checkin->service,
            'action' => $checkin->action,
            'reference' => $checkin->reference,
            'mode' => $checkin->method,
            'granted' => (bool) $checkin->granted,
            'message' => $checkin->message,
            'created_at' => $checkin->scanned_at?->toIso8601String(),
        ];
    }
}
