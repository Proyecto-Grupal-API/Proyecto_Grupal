<?php

namespace Database\Seeders\Demo;

use App\Models\PlatformModule\Catalog;
use App\Models\PlatformModule\EarningRule;
use App\Models\PlatformModule\PointCampaign;
use App\Models\PlatformModule\Reward;
use App\Models\PlatformModule\RewardProgramMembership;
use App\Models\Role;

/**
 * E7 Recompensas: tres cargos administrativos por contexto de servicio (ninguno es administrador global), un profesor
 * que valida y entrega canjes y un alumno.
 *  - `demo.e7`: administra el programa (`admin` en el servicio `recompensas`).
 *  - `demo.e7.plataforma`: aprueba configuraciones y administra catálogos del módulo (`admin` en `plataforma`).
 *  - `demo.e7.auditor`: sólo consulta (`admin` en `auditoria`).
 * Siembra catálogos mínimos; los puntos se acreditan en el recorrido.
 */
class DemoE7Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $program = $this->account('demo.e7');
        $this->withoutGlobalRole($program, Role::ADMIN);
        $this->serviceRole($program, 'recompensas');
        $this->serviceRole($this->account('demo.e7.plataforma'), 'plataforma');
        $this->serviceRole($this->account('demo.e7.auditor'), 'auditoria');
        $this->globalRole($this->account('demo.e7.profesor'), Role::MAESTRO);
        $student = $this->account('demo.e7.alumno');
        $this->student($student);
        // Integración con Tienda (contrato «Acumular puntos por venta»): el alumno puede comprar con su monedero en la
        // tienda de demostración, que participa en el programa con la regla del ejemplo del diseño (§18).
        $this->openingBalance($this->personalWallet($student), DemoTeams::E7_STUDENT_OPENING_CENTS);
        RewardProgramMembership::firstOrCreate(['business_id' => DemoTeams::E3_BUSINESS_ID], ['business_name' => 'Tienda Demo E3', 'category' => 'Servicios',
            'contact_email' => 'tienda.demo@campus.test', 'status' => 'active', 'compensation_model' => 'points', 'accepted_terms_at' => now(), 'joined_at' => now()]);
        EarningRule::firstOrCreate(['business_id' => DemoTeams::E3_BUSINESS_ID, 'name' => DemoTeams::E7_STORE_RULE], ['type' => 'amount_spent', 'points_per_unit' => 1,
            'unit_amount' => 2, 'multiplier' => 1, 'max_points_per_operation' => 500, 'status' => 'active', 'active_from' => now()->subDay()]);

        RewardProgramMembership::firstOrCreate(['business_id' => 'BUS-DEMO-E7'], ['business_name' => 'Cafetería Demo E7', 'category' => 'Alimentos',
            'contact_email' => 'cafeteria.demo@campus.test', 'status' => 'active', 'compensation_model' => 'points', 'accepted_terms_at' => now(), 'joined_at' => now()]);
        EarningRule::firstOrCreate(['business_id' => 'BUS-DEMO-E7', 'name' => 'Puntos por compra (demo)'], ['type' => 'amount_spent', 'points_per_unit' => 1,
            'unit_amount' => 10, 'multiplier' => 1, 'status' => 'active', 'active_from' => now()->subDay()]);
        PointCampaign::firstOrCreate(['name' => 'Campaña de bienvenida (demo)'], ['description' => 'Campaña sintética de demostración.', 'type' => 'multiplier',
            'multiplier' => 2, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(180), 'status' => 'active', 'scope' => 'global']);
        Catalog::firstOrCreate(['code' => 'DEMO-E7-CATEGORIAS'], ['name' => 'Categorías de recompensa (demo)', 'items' => ['Souvenirs', 'Alimentos', 'Servicios'],
            'description' => 'Catálogo sintético de demostración.']);
        Reward::firstOrCreate(['name' => 'Termo Demo E7'], ['business_id' => 'BUS-DEMO-E7', 'description' => 'Recompensa sintética de demostración.',
            'points_required' => 100, 'stock' => 20, 'reserved_stock' => 0, 'is_physical' => true, 'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(180), 'status' => 'active']);
    }
}
