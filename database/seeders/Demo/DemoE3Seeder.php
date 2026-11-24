<?php

namespace Database\Seeders\Demo;

use App\Domains\Financial\Enums\WalletType;
use App\Models\InventoryModule\InventoryBusiness;
use App\Models\Marketplace\Business;
use App\Models\Marketplace\Product;
use App\Models\Marketplace\Storefront;
use App\Models\Role;

/**
 * E3 Tienda: negocio ya aprobado con tienda publicada (dueño y cajero por asignación de Identidad), un revisor
 * institucional con el cargo propio de Tienda (rol `admin` de Identidad en el contexto de servicio `tienda`, sin
 * administrador global) y un comprador con monedero y saldo sintético.
 */
class DemoE3Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $owner = $this->account('demo.e3');
        $cashier = $this->account('demo.e3.cajero');
        $buyer = $this->account('demo.e3.comprador');
        foreach ([$owner, $cashier, $buyer] as $user) {
            $this->student($user);
        }
        $reviewer = $this->account('demo.e3.revisor');
        $this->serviceRole($reviewer, (string) config('marketplace.reviewer_context'));
        $this->withoutGlobalRole($reviewer, Role::ADMIN);

        $id = DemoTeams::E3_BUSINESS_ID;
        if ($owner) {
            $business = Business::firstOrCreate(['_id' => $id], ['name' => 'Tienda Demo E3', 'slug' => 'tienda-demo-e3', 'type' => 'service',
                'owner_id' => (string) $owner->getKey(), 'organization_id' => null, 'description' => 'Negocio sintético de la demostración de Tienda.',
                'status' => 'approved', 'visibility' => 'public', 'verified' => true, 'inventory_ready' => true,
                'payment_methods' => ['wallet', 'spei'], 'spei_instructions' => 'Transferencia de demostración: no realices ningún pago real.', 'tags' => []]);
            Storefront::firstOrCreate(['business_id' => $id], ['name' => $business->name, 'brand' => 'Demo E3', 'description' => $business->description,
                'published' => true, 'policies' => [], 'delivery_points' => ['Biblioteca Central']]);
            foreach ([['asesoria-demo-e3', 'Asesoría de cálculo (demo)', 'Asesorías', '45.00'], ['revision-demo-e3', 'Revisión de proyecto (demo)', 'Asesorías', '120.00']] as [$slug, $name, $category, $price]) {
                Product::firstOrCreate(['business_id' => $id, 'slug' => $slug], ['name' => $name, 'kind' => 'service', 'category' => $category,
                    'description' => 'Servicio sintético de demostración.', 'price' => $price, 'currency' => 'MXN', 'active' => true, 'available' => true,
                    'inventory_required' => false]);
            }
            // Registro del negocio en Inventarios (lo que la aprobación hace por HTTP firmado).
            InventoryBusiness::firstOrCreate(['business_id' => $id], ['name' => $business->name, 'active' => true]);
            $this->businessRole($owner, $id, 'business_owner');
            $this->businessRole($cashier, $id, 'cashier', $owner);
            // Monedero receptor del negocio y monedero del comprador con saldo inicial (para el cobro con monedero).
            $this->wallet('BUSINESS', $id, WalletType::NEGOCIO);
        }
        $this->openingBalance($this->personalWallet($buyer), DemoTeams::E3_BUYER_OPENING_CENTS);
    }
}
