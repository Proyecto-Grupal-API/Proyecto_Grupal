<?php

namespace Database\Seeders\Demo;

use App\Domains\Financial\Enums\WalletType;
use App\Models\Role;
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Rentals\Asset;

/**
 * E5 Servicios: personal con el cargo propio del módulo (rol `admin` de Identidad en el contexto de servicio
 * `servicios-estudiante`, sin administrador global; conserva la 2FA obligatoria) y dos alumnos con perfil activo.
 * Para los cobros por Finanzas: cuenta institucional de Servicios, monedero del alumno con saldo sintético y un apoyo
 * de impresiones vigente. Añade un periodo de lockers vigente con fechas relativas y un equipo sin depósito (fuera de
 * `testing` las rentas con garantía no operan).
 */
class DemoE5Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $staff = $this->account('demo.e5');
        $this->serviceRole($staff, (string) config('integracion_servicios.contexto_personal'));
        $this->withoutGlobalRole($staff, Role::ADMIN);
        $this->student($this->account('demo.e5.alumno'));
        $this->student($this->account('demo.e5.alumno2'));

        LockerPeriod::firstOrCreate(['code' => 'DEMO-E5'], ['name' => 'Periodo de demostración E5',
            'starts_at' => now()->subDays(7)->startOfDay(), 'ends_at' => now()->addDays(180)->endOfDay(),
            'prices_cents' => ['small' => 15000, 'medium' => 22000, 'large' => 30000], 'status' => 'active']);
        // Cobros por Finanzas: cuenta institucional receptora (la crea el servicio de monederos de E2) y monedero del alumno con saldo.
        $this->wallet('ORGANIZATION', DemoTeams::E5_SERVICES_ORGANIZATION, WalletType::ORGANIZACION);
        $student = $this->account('demo.e5.alumno');
        $this->openingBalance($this->personalWallet($student), DemoTeams::E5_STUDENT_OPENING_CENTS);
        // Apoyo de impresiones vigente, para «Usar beca». En operación lo crea Comunidad por la API de beneficios de E5.
        if ($student) {
            BenefitAssignment::firstOrCreate(['client_id' => 'demo-seed', 'idempotency_key' => 'demo-e5-impresiones'], [
                'folio' => 'BEN-DEMO-E5-0001', 'request_hash' => hash('sha256', 'demo-e5-impresiones'), 'benefit' => BenefitAssignment::BENEFIT_PRINTS,
                'student_id' => (string) $student->getKey(), 'organization_id' => DemoTeams::E6_ORGANIZATION_ID, 'call_id' => null, 'application_id' => null,
                'origin_folio' => 'DEMO-E5', 'quantity' => DemoTeams::E5_PRINT_ALLOWANCE_PAGES, 'consumed' => 0, 'remaining' => DemoTeams::E5_PRINT_ALLOWANCE_PAGES,
                'valid_from' => now()->subDay()->startOfDay(), 'valid_until' => now()->addDays(180)->endOfDay(), 'status' => 'active', 'consumptions' => [], 'cancellation' => null]);
        }

        Asset::firstOrCreate(['inventory_item_id' => 'INV-DEMO-E5-001'], ['name' => 'Cargador universal (demo, sin depósito)', 'category' => 'Accesorios',
            'location' => 'Centro de préstamo', 'status' => 'available', 'description' => 'Equipo sintético de demostración sin garantía.', 'deposit_cents' => 0]);
    }
}
