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
            'auth' => [
                'user' => $request->user(),
                'gestiona_organizaciones' => fn () => app(AutoridadOrganizaciones::class)->permite($request->user()),
                'organizacion' => fn () => $request->attributes->get('organizacion'),
                'puede_editar' => fn () => $request->attributes->get('organizacion') && Gate::allows('update', $request->attributes->get('organizacion')),
            ],
        ];
    }
}
