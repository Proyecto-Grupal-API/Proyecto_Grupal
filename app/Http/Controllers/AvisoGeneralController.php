<?php

namespace App\Http\Controllers;

use App\Jobs\EntregarAvisoGeneral;
use App\Models\AuditoriaComunidad;
use App\Models\AvisoGeneral;
use App\Support\Authorization\CoordinadorComunicaciones;
use Illuminate\Http\Request;

class AvisoGeneralController extends Controller
{
    public function index(Request $request)
    {
        $puede = CoordinadorComunicaciones::permite($request->user());
        $avisos = $puede ? AvisoGeneral::orderBy('publicado_en', 'desc')->limit(50)->get()->map(fn (AvisoGeneral $a) => [
            'id' => (string) $a->getKey(), 'asunto' => $a->asunto, 'cuerpo' => $a->cuerpo, 'autor_nombre' => $a->autor_nombre,
            'publicado_en' => $a->publicado_en?->toISOString(), 'estado' => $a->estado, 'entregados' => (int) $a->entregados,
        ])->values() : [];

        return response()->json(['puede_publicar' => $puede, 'avisos' => $avisos])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        abort_unless(CoordinadorComunicaciones::permite($request->user()), 403, 'Publicar avisos generales requiere el cargo de coordinación de comunicaciones.');
        $data = $request->validate(['asunto' => ['required', 'string', 'min:3', 'max:150'], 'cuerpo' => ['required', 'string', 'min:5', 'max:5000'],
            'confirmacion' => ['accepted']], ['confirmacion.accepted' => 'Confirma que el aviso se enviará a todos los usuarios.']);
        $user = $request->user();
        $aviso = AvisoGeneral::create(['asunto' => $data['asunto'], 'cuerpo' => $data['cuerpo'], 'autor_id' => (string) $user->getKey(),
            'autor_nombre' => $user->name, 'publicado_en' => now(), 'estado' => 'enviando', 'entregados' => 0, 'ultimo_usuario' => null]);
        AuditoriaComunidad::create(['organizacion_id' => null, 'usuario_id' => (string) $user->getKey(), 'accion' => 'aviso_general_publicado',
            'entidad_id' => (string) $aviso->getKey(), 'antes' => null, 'despues' => ['asunto' => $aviso->asunto]]);
        EntregarAvisoGeneral::dispatch((string) $aviso->getKey());

        return response()->json(['message' => 'Aviso publicado. Se está entregando en la bandeja de todos los usuarios.',
            'aviso' => ['id' => (string) $aviso->getKey(), 'estado' => $aviso->estado]], 201);
    }
}
