<?php

namespace App\Http\Controllers;

use App\Models\Mensaje;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    private function mensajes(Request $request)
    {
        return Mensaje::query()->where('usuario_id', (string) $request->user()->id)->whereNull('eliminado_en');
    }

    public function index(Request $request)
    {
        return response()->json($this->mensajes($request)->whereNull('archivado_en')->orderBy('creado_en', 'desc')->limit(5)->get()->map(fn ($m) => ['id' => $m->id, 'titulo' => $m->asunto, 'detalle' => $m->cuerpo, 'tiempo' => Carbon::parse($m->creado_en)->locale('es')->diffForHumans(), 'leida' => ($m->leido_en ?? null) !== null]))->header('X-Unread-Count', (string) $this->mensajes($request)->whereNull('archivado_en')->whereNull('leido_en')->count())->header('Cache-Control', 'private, no-store');
    }

    public function marcarLeidas(Request $request)
    {
        $this->mensajes($request)->whereNull('archivado_en')->whereNull('primera_lectura_en')->update(['primera_lectura_en' => now()]);
        $this->mensajes($request)->whereNull('archivado_en')->whereNull('leido_en')->update(['leido_en' => now(), 'actualizado_en' => now()]);

        return response()->json(['message' => 'Notificaciones actualizadas.']);
    }

    public function eliminarLeidas(Request $request)
    {
        $this->mensajes($request)->whereNotNull('leido_en')->update(['eliminado_en' => now(), 'actualizado_en' => now()]);

        return response()->json(['message' => 'Notificaciones leídas eliminadas.']);
    }
}
