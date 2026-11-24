<?php

namespace Database\Seeders\Demo;

use App\Models\InventoryModule\InventoryBusiness;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * E4 Inventarios: el negocio de inventario por defecto con su catálogo de ejemplo y tres papeles separados
 * (propietario que autoriza, gerente que solicita y recibe, responsable de almacén, comprador) más un alumno sin rol y una
 * cuenta de consulta transversal de sólo lectura (todas las tiendas).
 */
class DemoE4Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $business = (string) config('team4.business_id');
        InventoryBusiness::firstOrCreate(['business_id' => $business], ['name' => 'Souvenirs del Campus', 'active' => true]);
        if (Artisan::call('inventario:demo') !== 0) {
            throw new RuntimeException('No se pudo cargar el catálogo de ejemplo de Inventarios.');
        }

        $owner = $this->account('demo.e4');
        $manager = $this->account('demo.e4.gerente');
        $warehouse = $this->account('demo.e4.almacen');
        $buyer = $this->account('demo.e4.comprador');
        foreach ([$owner, $manager, $warehouse, $buyer, $this->account('demo.e4.alumno')] as $user) {
            $this->student($user); // quienes operan un negocio del campus son alumnos
        }
        $this->businessRole($owner, $business, 'business_owner');
        $this->businessRole($manager, $business, 'business_manager', $owner);
        $this->businessRole($warehouse, $business, 'inventory_manager', $owner);
        $this->businessRole($buyer, $business, 'buyer', $owner);
        // Consulta transversal de sólo lectura (requerimiento E4, 20-nov-2026): cargo exacto de Identidad, sin rol de negocio.
        $this->serviceRole($this->account('demo.e4.consulta'), (string) config('team4.global_viewer_service'));
    }
}
