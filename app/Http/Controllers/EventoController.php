<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Evento;
use App\Models\RegistroEvento; 

class EventoController extends Controller
{
    // 1. LECTURA MULTI-EVENTO (GET)
    public function index()
    {
        try {
            $eventos = Evento::where('estado', 'publicado')
                ->orderBy('fecha_hora_inicio', 'asc')
                ->get();

            $eventosConDatos = $eventos->map(function($evento) {
                $inscritos = RegistroEvento::where('evento_id', $evento->id)
                    ->select('id', 'usuario_id', 'estado_pago', 'token_qr', 'estado_asistencia')
                    ->orderBy('registrado_en', 'desc')
                    ->get();

                $evento->inscritos = $inscritos;
                $evento->stats = [
                    'total' => $inscritos->count(),
                    'asistieron' => $inscritos->where('estado_asistencia', 'asistio')->count(),
                    'capacidad' => $evento->capacidad ?? 100
                ];
                return $evento;
            });

            return response()->json(['eventos' => $eventosConDatos], 200);

        } catch (\Exception $e) {
            Log::error('Error cargando eventos: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }

    // 2. ESCRITURA (POST) - Crear nuevo evento
    public function store(Request $request)
    {
        try {
            $fechaInicio = Carbon::parse($request->fecha_hora_inicio);
            $fechaFin = Carbon::parse($request->fecha_hora_fin);
            
            Evento::create([
                'organizacion_id' => 1,
                'tipo_evento_id' => 1,
                'titulo' => $request->titulo,
                'slug' => Str::slug($request->titulo) . '-' . time(),
                'descripcion' => $request->descripcion ?? 'Evento oficial',
                'ubicacion' => $request->ubicacion,
                'fecha_hora_inicio' => $fechaInicio->format('Y-m-d H:i:s'),
                'fecha_hora_fin' => $fechaFin->format('Y-m-d H:i:s'),
                'costo' => $request->costo,
                'capacidad' => $request->capacidad,
                'fecha_inicio_registro' => now()->toDateString(),
                'fecha_fin_registro' => $fechaInicio->toDateString(),
                'estado' => 'publicado',
                'creado_por' => 1,
                'creado_en' => now()->format('Y-m-d H:i:s'),
                'actualizado_en' => now()->format('Y-m-d H:i:s')
            ]);

            return response()->json(['message' => 'Evento creado exitosamente'], 201);
        } catch (\Exception $e) {
            Log::error('Error creando evento: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // 3. VISTA DEL ESTUDIANTE: Leer mi boleto activo
    public function miBoleto()
    {
        try {
            $usuarioId = 3;
            
            $registro = RegistroEvento::where('usuario_id', $usuarioId)->first();
            
            if (!$registro) {
                return response()->json(['error' => 'No se encontro un boleto'], 404);
            }

            $evento = Evento::where('_id', $registro->evento_id)
                ->where('estado', 'publicado')
                ->first();

            $boleto = [
                'titulo' => $evento->titulo ?? 'Evento no disponible',
                'ubicacion' => $evento->ubicacion ?? 'N/A',
                'fecha_hora_inicio' => $evento->fecha_hora_inicio ?? null,
                'token_qr' => $registro->token_qr,
                'estado_asistencia' => $registro->estado_asistencia,
                'estado_pago' => $registro->estado_pago
            ];

            return response()->json($boleto, 200);
        } catch (\Exception $e) {
            Log::error('Error cargando boleto: ' . $e->getMessage());
            return response()->json(['error' => 'Error al cargar el boleto'], 500);
        }
    }

    // 4. ESCRITURA (POST) - Validar asistencia
    public function checkin(Request $request)
    {
        try {
            $token = $request->input('token_qr');
            $eventoId = $request->input('evento_id');
            
            $registro = RegistroEvento::where('token_qr', $token)
                ->where('evento_id', $eventoId)
                ->first();
            
            if (!$registro) {
                return response()->json(['error' => 'Boleto no valido para este evento en especifico.'], 404);
            }

            if ($registro->estado_asistencia === 'asistio') {
                return response()->json(['error' => 'Este boleto ya fue registrado previamente.'], 400);
            }

            if ($registro->estado_pago !== 'pagado' && $registro->estado_pago !== 'exento') {
                return response()->json(['error' => 'El boleto tiene un pago pendiente.'], 400);
            }

            $registro->update([
                'estado_asistencia' => 'asistio',
                'actualizado_en' => now()->format('Y-m-d H:i:s')
            ]);
            
            return response()->json(['message' => 'Acceso Autorizado'], 200);

        } catch (\Exception $e) {
            Log::error('Error en checkin: ' . $e->getMessage());
            return response()->json(['error' => 'Error interno al procesar el pase.'], 500);
        }
    }
}