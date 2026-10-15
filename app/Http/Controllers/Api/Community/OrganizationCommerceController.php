<?php

namespace App\Http\Controllers\Api\Community;

use App\Http\Controllers\Controller;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use Illuminate\Http\Request;

class OrganizationCommerceController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['subject_id' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]+$/']]);
        $memberIds = MiembroOrganizacion::activos()->where('usuario_id', $data['subject_id'])->pluck('organizacion_id')->all();
        $presidencies = RolOrganizacion::vigentes()->where('usuario_id', $data['subject_id'])->where('slug_rol', 'presidencia')
            ->whereIn('organizacion_id', $memberIds)->pluck('organizacion_id')->all();
        $organizations = Organizacion::where('estado', 'activa')->whereIn('_id', $presidencies)->get(['nombre', 'tipo']);

        return response()->json(['subject_id' => $data['subject_id'], 'organizations' => $organizations->map(fn ($o) => [
            'id' => (string) $o->id, 'name' => $o->nombre, 'type' => $o->tipo === 'consejo' ? 'council' : 'association', 'can_request_store' => true])->all()])
            ->header('Cache-Control', 'private, no-store');
    }
}
