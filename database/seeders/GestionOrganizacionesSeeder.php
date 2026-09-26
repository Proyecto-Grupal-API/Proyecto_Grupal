<?php

namespace Database\Seeders;

use App\Models\PermisoGestionOrganizaciones;
use App\Models\User;
use Illuminate\Database\Seeder;

class GestionOrganizacionesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('La cuenta de demostración solo se crea en local/testing.');
        }
        $u = User::firstOrCreate(['email' => 'gestion@campus.test'], ['name' => 'Gestión de organizaciones', 'matricula' => '20260006', 'password' => 'CampusDemo2026!', 'email_verified_at' => now()]);
        if (! PermisoGestionOrganizaciones::where('usuario_id', (string) $u->id)->exists()) {
            $this->command->call('comunidad:gestor-organizaciones', ['matricula' => $u->matricula, '--operador' => 'Seeder de demostración', '--motivo' => 'Habilitar registro de organizaciones en la demostración local.']);
        }
    }
}
