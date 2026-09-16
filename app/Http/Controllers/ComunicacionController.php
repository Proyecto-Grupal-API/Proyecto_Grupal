<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComunicacionController extends Controller
{
    public function index()
    {
        try {
            // 1. Traemos las últimas campañas enviadas
            $campanas = DB::table('campañas')
                ->select('id', 'nombre', 'criterios_audiencia', 'estado', 'creado_en')
                ->orderBy('creado_en', 'desc')
                ->take(5)
                ->get();

            // 2. Calculamos las métricas de lectura para cada campaña
            foreach ($campanas as $campana) {
                // Total de mensajes enviados para esta campaña
                $totalMensajes = DB::table('mensajes')
                    ->where('campaña_id', $campana->id)
                    ->count();
                
                // Mensajes que ya fueron abiertos (tienen fecha en leido_en)
                $leidos = DB::table('mensajes')
                    ->where('campaña_id', $campana->id)
                    ->whereNotNull('leido_en')
                    ->count();

                $campana->total_enviados = $totalMensajes;
                $campana->total_leidos = $leidos;
                $campana->tasa_lectura = $totalMensajes > 0 ? round(($leidos / $totalMensajes) * 100) : 0;
            }

            // 3. Traemos las encuestas activas
            $encuestas = DB::table('encuestas')
                ->where('activa', 1)
                ->select('id', 'titulo')
                ->count();

            return response()->json([
                'campanas' => $campanas,
                'encuestasActivas' => $encuestas
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error cargando comunicación: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }
}