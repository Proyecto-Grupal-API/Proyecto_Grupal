<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BusinessService
{
    public function validate(string $businessId): bool
    {
        if (empty($businessId)) {
            throw new \InvalidArgumentException('business_id no puede estar vacío.');
        }

        if (app()->environment(['local', 'development', 'testing'])) {
            // En dev, solo validamos formato.
            if (!str_starts_with($businessId, 'BUS-') || strlen($businessId) < 10) {
                throw new \InvalidArgumentException(
                    'business_id inválido. Formato esperado: BUS-XXXXX.'
                );
            }
            return true;
        }

        // En producción: consumir API del Eq. 3.
        // PENDIENTE: cuando el Eq. 3 exponga el endpoint de validación
        // de negocios, implementar aquí:
        // $response = Http::withHeaders([...])->get(env('TEAM3_API_URL') . '/businesses/' . $businessId);
        // return $response->successful() && $response->json('active') === true;

        Log::warning('BusinessService::validate en producción sin API del Eq. 3 configurada.', [
            'business_id' => $businessId,
        ]);

        // Validación temporal: mismo formato que en dev.
        if (!str_starts_with($businessId, 'BUS-') || strlen($businessId) < 10) {
            throw new \InvalidArgumentException('business_id inválido.');
        }

        return true;
    }
}
