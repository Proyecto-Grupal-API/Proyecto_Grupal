<?php

namespace App\Http\Controllers\Api\Community;

use App\Models\AsignacionBeneficio;
use App\Models\SolicitudBeca;
use App\Support\BecaFinanceContract;
use Illuminate\Http\Request;

final class BecaAwardController
{
    public function __invoke(Request $request, string $solicitud)
    {
        BecaFinanceContract::authorizeService($request, 'e2');
        $approved = SolicitudBeca::whereKey($solicitud)->where('estado', 'aprobada')->firstOrFail();
        $award = AsignacionBeneficio::where('solicitud_id', $solicitud)
            ->where('contrato.equipo_destino', 2)->where('contrato.tipo', 'bono')->firstOrFail();
        $data = BecaFinanceContract::snapshot($award->contrato, $solicitud);

        abort_unless($data && $data['beneficiario_id'] === $approved->usuario_id
            && $data['organizacion_id'] === $approved->organizacion_id && $data['convocatoria_id'] === $approved->convocatoria_id, 409);
        abort_unless(($award->contrato['clave_idempotencia'] ?? null) === BecaFinanceContract::reference($solicitud)
            && in_array($award->estado, ['pendiente_integracion', 'asignada'], true), 409);

        return response()->json(['data' => [...$data, 'aval_tesoreria' => $award->aval_tesoreria]])->header('Cache-Control', 'private, no-store');
    }
}
