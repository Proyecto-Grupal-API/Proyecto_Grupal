<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            $miembrosActivos = DB::table('miembros_organizacion')->where('estado', 'activo')->count();
            $eventosActivos = DB::table('eventos')->where('estado', 'publicado')->count();
            $becasPendientes = DB::table('solicitudes_becas')->where('estado', 'pendiente')->count();
            
            $cajaDisponible = 5400.00; // Simulado temporalmente

            $proximoEvento = DB::table('eventos')
                ->where('fecha_hora_inicio', '>=', now())
                ->orderBy('fecha_hora_inicio', 'asc')
                ->first();

            // NUEVO: Traemos las últimas 3 solicitudes de becas reales
            $ultimasBecas = DB::table('solicitudes_becas')
                ->join('convocatorias_becas', 'solicitudes_becas.convocatoria_id', '=', 'convocatorias_becas.id')
                ->select('solicitudes_becas.id', 'solicitudes_becas.estado', 'solicitudes_becas.usuario_id', 'convocatorias_becas.titulo as beca')
                ->orderBy('solicitudes_becas.creado_en', 'desc')
                ->take(3)
                ->get();

            return response()->json([
                'miembrosActivos' => $miembrosActivos,
                'cajaDisponible' => $cajaDisponible,
                'eventosActivos' => $eventosActivos,
                'becasPendientes' => $becasPendientes,
                'ultimasBecas' => $ultimasBecas, // <- Lo pasamos a Vue
                'proximoEvento' => $proximoEvento ? [
                    'nombre' => $proximoEvento->titulo,
                    'lugar' => $proximoEvento->ubicacion,
                    'fecha' => \Carbon\Carbon::parse($proximoEvento->fecha_hora_inicio)->format('d M, Y - h:i A'),
                    'asistencia_actual' => 0, // Por ahora 0
                    'asistencia_total' => $proximoEvento->capacidad ?? 100,
                ] : null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en Dashboard: ' . $e->getMessage());
            return response()->json(['error' => 'Error al cargar datos'], 500);
        }
    }
}