<?php

namespace Database\Seeders\Demo;

use App\Domains\Financial\Enums\WalletType;
use App\Models\PermisoGestionOrganizaciones;
use Illuminate\Support\Facades\Artisan;

/**
 * E6 Comunidad: organización propia con presidencia e integrantes, y una cuenta de gestión de organizaciones.
 * Eventos, becas, campañas y consultas se crean en el recorrido con fechas del día (los datos fechados caducan).
 */
class DemoE6Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        $president = $this->account('demo.e6');
        $members = [$this->account('demo.e6.integrante'), $this->account('demo.e6.revisor'), $this->account('demo.e6.staff')];
        $manager = $this->account('demo.e6.gestor');
        foreach ([$president, ...$members, $manager] as $user) {
            $this->student($user);
        }
        $this->organization(DemoTeams::E6_ORGANIZATION_ID, 'demo-e6', DemoTeams::E6_ORGANIZATION_NAME, $president, $members);
        // Coordinación de comunicaciones (aviso general a todos los usuarios): cargo exacto de Identidad, sin administrador global.
        $this->serviceRole($this->account('demo.e6.comunicaciones'), (string) config('comunidad.avisos_generales.servicio'));
        // Cobro de eventos por Finanzas: cuenta receptora de la asociación (la crea el servicio de monederos de E2, con saldo
        // cero) y monedero con saldo sintético de las cuentas que compran boletos.
        $this->wallet('ORGANIZATION', DemoTeams::E6_ORGANIZATION_ID, WalletType::ORGANIZACION);
        foreach ([$members[0], $members[2]] as $payer) {   // integrante y personal de acceso: quienes reservan boletos en la demostración
            $this->openingBalance($this->personalWallet($payer), DemoTeams::E6_MEMBER_OPENING_CENTS);
        }

        // La gestión de organizaciones no es un rol: es un registro que crea la orden oficial de Comunidad (y lo audita).
        if ($manager && ! PermisoGestionOrganizaciones::where('usuario_id', (string) $manager->id)->exists()) {
            Artisan::call('comunidad:gestor-organizaciones', ['matricula' => $manager->matricula,
                '--operador' => 'Seeder de demostración por equipo', '--motivo' => 'Habilitar la gestión de organizaciones en la demostración de E6.']);
        }
    }
}
