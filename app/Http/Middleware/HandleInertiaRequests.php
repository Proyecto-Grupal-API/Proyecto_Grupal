<?php

namespace App\Http\Middleware;

use App\Services\AutoridadOrganizaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'inventoryContext' => fn () => $request->is('equipo4*') && $request->attributes->has('inventory_user') ? [
                'user' => $request->attributes->get('inventory_user'),
                'business_id' => app(\App\Services\InventoryModule\BusinessContext::class)->id(),
                'businesses' => app(\App\Services\InventoryModule\BusinessContext::class)->options(),
            ] : null,
            'permissions' => fn () => $request->attributes->has('inventory_user')
                ? app(\App\Services\InventoryModule\IdentityService::class)->effectivePermissions((string) $request->user()->getAuthIdentifier()) : [],
            'dashboardKpis' => fn () => $request->attributes->has('inventory_user')
                ? app(\App\Services\InventoryModule\IdentityService::class)->getDashboardKpis($request->attributes->get('inventory_user')['roles']) : [],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],
            'auth' => [
                'user' => $request->user(),
                'servicios_staff' => fn () => app(\App\Services\StudentServices\ServiceAuthority::class)->staff($request->user()),
                'hasStudentProfile' => $request->user()?->studentProfile()->exists() ?? false,
                'gestiona_organizaciones' => fn () => app(AutoridadOrganizaciones::class)->permite($request->user()),
                'organizacion' => fn () => $request->attributes->get('organizacion'),
                'puede_editar' => fn () => $request->attributes->get('organizacion') && Gate::allows('update', $request->attributes->get('organizacion')),
            ],
        ];
    }
}
