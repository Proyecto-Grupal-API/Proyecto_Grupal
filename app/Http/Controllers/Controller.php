<?php

namespace App\Http\Controllers;

use App\Models\Organizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    protected function organizacion(Request $request, bool $editar = false): Organizacion
    {
        $organizacion = $request->attributes->get('organizacion');
        abort_unless($organizacion, 403, 'No tienes una organización activa asignada.');
        Gate::authorize($editar ? 'update' : 'view', $organizacion);

        return $organizacion;
    }
}
