<?php

namespace App\Http\Middleware;

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use Closure;
use Illuminate\Http\Request;

class SeleccionarOrganizacion
{
    public function handle(Request $request, Closure $next)
    {
        $ids = MiembroOrganizacion::activos()->where('usuario_id', (string) $request->user()->id)->pluck('organizacion_id');
        $organizaciones = Organizacion::whereIn('_id', $ids)->where('estado', 'activa')->orderBy('nombre')->get();
        $seleccionada = $organizaciones->firstWhere('id', $request->session()->get('organizacion_id')) ?? $organizaciones->first();
        if (! $request->isMethodSafe() && ! $request->is('api/organizaciones/seleccionar') && ! $request->is('api/gestion-organizaciones*') && $request->hasHeader('X-Organization-Id')) {
            abort_unless($request->header('X-Organization-Id') === (string) $seleccionada?->id, 409, 'La organización activa cambió en otra pestaña. Recarga antes de guardar.');
        }
        $request->attributes->set('organizaciones', $organizaciones);
        $request->attributes->set('organizacion', $seleccionada);
        $request->session()->put('organizacion_id', $seleccionada ? (string) $seleccionada->id : null);

        return $next($request);
    }
}
