<?php

namespace App\Http\Controllers;

use App\Models\Organizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    protected function organizacion(Request $request, bool|string $editar = false): Organizacion
    {
        $organizacion = $request->attributes->get('organizacion');
        abort_unless($organizacion, 403, 'No tienes una organización activa asignada.');
        Gate::authorize(is_string($editar) ? $editar : ($editar ? 'update' : 'view'), $organizacion);

        return $organizacion;
    }
}
