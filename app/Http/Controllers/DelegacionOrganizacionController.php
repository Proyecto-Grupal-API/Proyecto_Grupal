<?php

namespace App\Http\Controllers;

use App\Models\DelegacionOrganizacion;
use App\Services\DelegacionesComunidad;
use App\Services\PermisosComunidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DelegacionOrganizacionController extends Controller
{
    public function index(Request $r, DelegacionesComunidad $servicio)
    {
        $org = $this->organizacion($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $administrar = Gate::allows('update', $org);
        $q = DelegacionOrganizacion::where('organizacion_id', (string) $org->id);
        if (! $administrar) {
            $q->where('destinatario_id', (string) $r->user()->id);
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(10);

        return response()->json(['delegaciones' => $p->getCollection()->map(fn ($d) => $servicio->resumen($d, $org)), 'puede_administrar' => $administrar,
            'capacidades' => PermisosComunidad::CAPACIDADES, 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, DelegacionesComunidad $servicio)
    {
        $org = $this->organizacion($r, true);
        $datos = $r->validate([
            'destinatario_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'capacidades' => ['required', 'array', 'min:1', 'max:3'],
            'capacidades.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PermisosComunidad::CAPACIDADES))],
            'inicio' => ['required', 'date_format:Y-m-d\TH:i'], 'fin' => ['required', 'date_format:Y-m-d\TH:i'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'], 'clave_idempotencia' => ['required', 'uuid'],
        ]);
        $d = $servicio->otorgar($r->user(), $org, $datos);

        return response()->json(['message' => 'Delegación guardada.', 'delegacion' => $servicio->resumen($d, $org)], $d->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function revocar(Request $r, string $id, DelegacionesComunidad $servicio)
    {
        $org = $this->organizacion($r, true);
        $datos = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:1000']]);
        $d = $servicio->revocar($r->user(), $org, $id, $datos['motivo']);

        return response()->json(['message' => 'Delegación revocada.', 'delegacion' => $servicio->resumen($d, $org)])->header('Cache-Control', 'private, no-store');
    }
}
