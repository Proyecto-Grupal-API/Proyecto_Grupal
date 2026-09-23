<?php

namespace Database\Seeders;

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Los usuarios de demostración solo se crean en local o testing.');
        }
        $this->call(TipoBeneficioSeeder::class);
        $personas = [
            ['name' => 'Presidencia Sistemas', 'email' => 'presidencia@campus.test', 'matricula' => '20260001'],
            ['name' => 'Estudiante Demo', 'email' => 'estudiante@campus.test', 'matricula' => '20260002'],
            ['name' => 'Presidencia Consejo', 'email' => 'consejo@campus.test', 'matricula' => '20260003'],
            ['name' => 'Andrea Demo', 'email' => 'andrea@campus.test', 'matricula' => '20260004'],
        ];
        $users = collect($personas)->map(fn ($p) => User::firstOrCreate(['email' => $p['email']], [...$p, 'password' => 'CampusDemo2026!', 'email_verified_at' => now()]));
        foreach ([['sistemas', 'Asociación de Sistemas', 'asociacion', 0], ['consejo', 'Consejo Estudiantil', 'consejo', 2]] as [$slug, $nombre, $tipo, $presidente]) {
            $org = Organizacion::firstOrCreate(['slug' => $slug], ['nombre' => $nombre, 'tipo' => $tipo, 'descripcion' => 'Organización de demostración de Campus Digital.', 'email' => $users[$presidente]->email, 'telefono' => '', 'estado' => 'activa']);
            foreach ([$presidente, 1] as $i) {
                MiembroOrganizacion::firstOrCreate(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $users[$i]->id], ['estado' => 'activo', 'fecha_inicio' => today()->subMonth(), 'eliminado_en' => null]);
            }
            // No restablecer responsables después de una transferencia o baja manual.
            if (! RolOrganizacion::where('organizacion_id', (string) $org->id)->where('slug_rol', 'presidencia')->exists()) {
                RolOrganizacion::create(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $users[$presidente]->id, 'slug_rol' => 'presidencia', 'fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->addYear(), 'otorgado_por' => (string) $users[$presidente]->id, 'eliminado_en' => null]);
            }
        }
    }
}
