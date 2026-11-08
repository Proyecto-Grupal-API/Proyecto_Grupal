<?php

namespace App\Http\Controllers;

use App\Models\PagoReservaEvento;
use App\Services\PagosEvento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagoEventoController extends Controller
{
    public function iniciar(Request $request, string $id, PagosEvento $pagos): JsonResponse
    {
        $generacion = $this->generacion($request);

        return $this->respuesta($pagos->iniciar($id, (string) $request->user()->getKey(), $generacion));
    }

    public function consultar(Request $request, string $id, PagosEvento $pagos): JsonResponse
    {
        $generacion = $this->generacion($request);
        $pago = PagoReservaEvento::where('evento_id', strtolower($id))->where('usuario_id', (string) $request->user()->getKey())
            ->where('generacion', $generacion)->firstOrFail();

        return $this->respuesta(['estado' => $pagos->recuperar($pago)]);
    }

    private function generacion(Request $request): int
    {
        abort_unless(config('comunidad.eventos_pagos.enabled') === true, 404);
        abort_if(array_diff(array_keys($request->all()), ['generacion']), 422);
        $data = $request->validate(['generacion' => ['required', 'integer', 'min:1', 'max:999999999']]);

        return (int) $data['generacion'];
    }

    private function respuesta(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
