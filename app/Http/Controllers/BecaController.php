<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BecaController extends Controller
{
    public function index()
    {
        try {
            // 1. Traemos las convocatorias activas y su tipo de beneficio
            $convocatorias = DB::table('convocatorias_becas')
                ->join('tipos_beneficio', 'convocatorias_becas.tipo_beneficio_id', '=', 'tipos_beneficio.id')
                ->select(
                    'convocatorias_becas.id',
                    'convocatorias_becas.titulo',
                    'convocatorias_becas.descripcion',
                    'convocatorias_becas.monto',
                    'convocatorias_becas.fecha_fin',
                    'convocatorias_becas.total_espacios',
                    'tipos_beneficio.es_monetario',
                    'tipos_beneficio.es_servicio'
                )
                ->whereIn('convocatorias_becas.estado', ['publicada', 'en_revision'])
                ->get();

            // Calculamos cuántos espacios se han ocupado para cada convocatoria
            foreach ($convocatorias as $conv) {
                $ocupados = DB::table('asignaciones_beneficios')
                    ->join('solicitudes_becas', 'asignaciones_beneficios.solicitud_id', '=', 'solicitudes_becas.id')
                    ->where('solicitudes_becas.convocatoria_id', $conv->id)
                    ->count();
                
                $conv->espacios_ocupados = $ocupados;
                $conv->porcentaje = $conv->total_espacios > 0 ? round(($ocupados / $conv->total_espacios) * 100) : 0;
            }

            // 2. Traemos todas las solicitudes para el panel de dictaminación
            $solicitudes = DB::table('solicitudes_becas')
                ->join('convocatorias_becas', 'solicitudes_becas.convocatoria_id', '=', 'convocatorias_becas.id')
                ->join('tipos_beneficio', 'convocatorias_becas.tipo_beneficio_id', '=', 'tipos_beneficio.id')
                ->select(
                    'solicitudes_becas.id',
                    'solicitudes_becas.usuario_id',
                    'solicitudes_becas.estado',
                    'solicitudes_becas.documentos',
                    'convocatorias_becas.titulo as convocatoria',
                    'convocatorias_becas.requiere_documentos',
                    'tipos_beneficio.es_servicio'
                )
                ->orderBy('solicitudes_becas.creado_en', 'desc')
                ->get();

            return response()->json([
                'convocatorias' => $convocatorias,
                'solicitudes' => $solicitudes
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error cargando becas: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }
}