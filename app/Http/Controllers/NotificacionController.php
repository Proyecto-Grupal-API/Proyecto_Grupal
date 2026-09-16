<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificacionController extends Controller
{
    public function index()
    {
        // Solo traemos los mensajes que NO han sido eliminados
        $mensajes = DB::table('mensajes')
            ->whereNull('eliminado_en')
            ->orderBy('creado_en', 'desc')
            ->take(5)
            ->get();

        $notificaciones = $mensajes->map(function ($msg) {
            return [
                'id' => $msg->id,
                'titulo' => $msg->asunto,
                'detalle' => $msg->cuerpo,
                'tiempo' => Carbon::parse($msg->creado_en)->locale('es')->diffForHumans(),
                'leida' => $msg->leido_en !== null
            ];
        });

        return response()->json($notificaciones, 200);
    }

    public function marcarLeidas()
    {
        DB::table('mensajes')
            ->whereNull('leido_en')
            ->whereNull('eliminado_en')
            ->update([
                'leido_en' => now(), 
                'actualizado_en' => now()
            ]);

        return response()->json(['message' => 'Notificaciones actualizadas'], 200);
    }

    // NUEVO: Función para hacer el "Soft Delete" de las ya leídas
    public function eliminarLeidas()
    {
        DB::table('mensajes')
            ->whereNotNull('leido_en')
            ->whereNull('eliminado_en')
            ->update([
                'eliminado_en' => now(),
                'actualizado_en' => now()
            ]);

        return response()->json(['message' => 'Notificaciones leídas eliminadas'], 200);
    }
}