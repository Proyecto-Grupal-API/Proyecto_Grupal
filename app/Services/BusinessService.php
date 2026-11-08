<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BusinessService
{
    public function validate(string $businessId): bool
    {
        if (!preg_match('/^BUS-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $businessId)) {
            throw new \InvalidArgumentException('business_id inválido. Formato esperado: BUS-XXXXX.');
        }

        if (!app()->runningInConsole()) {
            $scopedBusinessId = request()->attributes->get('team4_business_id');
            if (is_string($scopedBusinessId) && $scopedBusinessId !== ''
                && !hash_equals($scopedBusinessId, $businessId)) {
                throw new \DomainException('El movimiento no pertenece al negocio autorizado para esta solicitud.');
            }
        }

        $business = DB::connection('mongodb')
            ->getCollection('businesses')
            ->findOne(['business_id' => $businessId]);

        if ($business === null) {
            throw new \DomainException('El negocio indicado no está registrado.');
        }

        if (($business['active'] ?? false) !== true) {
            throw new \DomainException('El negocio indicado está inactivo.');
        }

        return true;
    }
}
