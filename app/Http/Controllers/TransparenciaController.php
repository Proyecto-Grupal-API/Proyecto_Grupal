<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransparenciaController extends Controller
{
    public function index()
    {
        try {
            // 1. Traemos los reportes públicos publicados
            $reportesData = DB::table('reportes_transparencia')
                ->select('id', 'titulo', 'datos_agregados', 'publicado_en')
                ->orderBy('publicado_en', 'desc')
                ->get();

            // Decodificamos el JSON interno que guardaste en "datos_agregados"
            $reportes = $reportesData->map(function ($reporte) {
                return [
                    'id' => $reporte->id,
                    'titulo' => $reporte->titulo,
                    'publicado_en' => $reporte->publicado_en,
                    'datos' => json_decode($reporte->datos_agregados, true)
                ];
            });

            // 2. Traemos la última elección y sus resultados de votos
            $eleccion = DB::table('elecciones')
                ->select('id', 'titulo', 'criterios_votantes')
                ->orderBy('fecha_inicio', 'desc')
                ->first();

            $resultadosVotos = [];
            $totalVotos = 0;

            if ($eleccion) {
                // Buscamos las opciones (Planillas) de esta elección
                $opciones = DB::table('opciones_elecciones')
                    ->where('eleccion_id', $eleccion->id)
                    ->select('id', 'cargo')
                    ->get();

                foreach ($opciones as $opcion) {
                    $cantidadVotos = DB::table('votos')
                        ->where('opcion_eleccion_id', $opcion->id)
                        ->count();

                    $resultadosVotos[] = [
                        'id' => $opcion->id,
                        'planilla' => $opcion->cargo, // En DataGrip guardamos "Planilla Azul" aquí
                        'votos' => $cantidadVotos
                    ];
                    $totalVotos += $cantidadVotos;
                }

                // Calculamos el porcentaje
                foreach ($resultadosVotos as &$resultado) {
                    $resultado['porcentaje'] = $totalVotos > 0 ? round(($resultado['votos'] / $totalVotos) * 100) : 0;
                }
            }

            return response()->json([
                'reportes' => $reportes,
                'eleccion' => $eleccion,
                'resultados' => $resultadosVotos,
                'totalVotos' => $totalVotos
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error cargando transparencia: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }
}