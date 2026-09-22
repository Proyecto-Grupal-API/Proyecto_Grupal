<?php

namespace App\Http\Middleware;

use App\Models\StockAlert;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        // Contar alertas activas con prioridad HIGH o CRITICAL.
        // Solo estas se consideran "urgentes" y disparan el badge rojo.
        $urgentAlertsCount = 0;

        try {
            $urgentAlertsCount = StockAlert::where('business_id', 'BUS-CD-SOUV-001')
                ->where('status', 'ACTIVE')
                ->whereIn('priority', ['HIGH', 'CRITICAL'])
                ->count();
        } catch (\Throwable $e) {
            $urgentAlertsCount = 0;
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'alertsCount' => $urgentAlertsCount,
        ];
    }
}
