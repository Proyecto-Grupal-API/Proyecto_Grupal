<?php

namespace App\Console\Commands;

use App\Models\AsignacionBeneficio;
use App\Services\ClienteModuloHttp;
use App\Support\BecaFinanceContract;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReconcileScholarshipBonuses extends Command
{
    protected $signature = 'comunidad:conciliar-bonos-beca';

    protected $description = 'Recupera comprobantes de emisión BECA para las asignaciones pendientes.';

    public function handle(ClienteModuloHttp $client): int
    {
        if (config('becas_finanzas.enabled') !== true) {
            return self::SUCCESS;
        }

        $awards = AsignacionBeneficio::where('estado', 'pendiente_integracion')
            ->where('contrato.equipo_destino', 2)->where('contrato.tipo', 'bono')
            ->where(fn ($query) => $query->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', now()))
            ->orderBy('proximo_intento_en')->orderBy('_id')->limit(30)->get();

        foreach ($awards as $award) {
            $pending = AsignacionBeneficio::whereKey($award->getKey())->where('estado', 'pendiente_integracion');
            try {
                $receipt = $this->lookup($client, $award);
                if ($receipt !== null) {
                    $pending->update(['estado' => 'asignada', 'recibo_finanzas' => $receipt, 'entregado_en' => now(),
                        'proximo_intento_en' => null, 'error_integracion' => null]);

                    continue;
                }
                $pending->update(['proximo_intento_en' => now()->addMinutes(5), 'error_integracion' => null]);
            } catch (\Exception) {
                $pending->update(['proximo_intento_en' => now()->addMinutes(5),
                    'error_integracion' => 'Finanzas aún no confirmó el comprobante de esta beca.']);
            }
        }

        return self::SUCCESS;
    }

    private function lookup(ClienteModuloHttp $client, AsignacionBeneficio $award): ?array
    {
        $id = (string) $award->solicitud_id;
        $snapshot = BecaFinanceContract::snapshot($award->contrato, $id);
        $reference = BecaFinanceContract::reference($id);
        if (! $snapshot || ($award->contrato['clave_idempotencia'] ?? null) !== $reference) {
            throw new \RuntimeException('Asignación de Comunidad inválida.');
        }

        $result = $client->para('finanzas_becas_e6', BecaFinanceContract::FINANCIAL_SCOPE)
            ->get('/api/v1/financial/community/becas/'.$id.'/issuance');
        if ($result->notFound()) {
            return null;
        }
        $receipt = $result->throw()->json('data');
        if (! is_array($receipt) || ($receipt['reference'] ?? null) !== $reference
            || ($receipt['fingerprint'] ?? null) !== BecaFinanceContract::fingerprint($snapshot)
            || ! Str::isUuid($receipt['bonus_id'] ?? null) || ! Str::isUuid($receipt['operation_id'] ?? null)) {
            throw new \RuntimeException('Comprobante de emisión inválido.');
        }

        return ['reference' => $reference, 'bonus_id' => strtolower($receipt['bonus_id']),
            'operation_id' => strtolower($receipt['operation_id'])];
    }
}
