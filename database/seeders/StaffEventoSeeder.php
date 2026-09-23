<?php

namespace Database\Seeders;

use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\StaffEvento;
use App\Models\User;
use App\Services\InscripcionesEvento;
use Illuminate\Database\Seeder;

class StaffEventoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('La demostración de staff solo está disponible en local/testing.');
        }
        $org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        $admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $staff = User::firstOrCreate(['email' => 'staff@campus.test'], ['name' => 'Staff de acceso', 'matricula' => '20260005', 'password' => 'CampusDemo2026!', 'email_verified_at' => now()]);
        MiembroOrganizacion::firstOrCreate(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $staff->id], ['fecha_inicio' => today(), 'estado' => 'activo', 'eliminado_en' => null]);
        $evento = Evento::firstOrCreate(['slug' => 'conferencia-acceso-demo'], [
            'organizacion_id' => (string) $org->id, 'creado_por' => (string) $admin->id,
            'titulo' => 'Conferencia de Sistemas · Demo de acceso', 'descripcion' => 'Demostración del ingreso con personal asignado y boleto individual.',
            'ubicacion' => 'Auditorio de Sistemas', 'estado' => 'publicado', 'capacidad' => 50, 'costo' => 0, 'lista_espera' => true,
            'fecha_inicio_registro' => now()->subHour(), 'fecha_fin_registro' => now()->addMinutes(25),
            'fecha_hora_inicio' => now()->addMinutes(25), 'fecha_hora_fin' => now()->addHours(4),
        ]);
        // No reactivar permisos retirados ni reiniciar fechas/asistencias al repetir el seeder.
        StaffEvento::firstOrCreate(['evento_id' => (string) $evento->id, 'usuario_id' => (string) $staff->id], ['organizacion_id' => (string) $org->id, 'asignado_por' => (string) $admin->id, 'eliminado_en' => null]);
        if ($evento->estado === 'publicado' && $evento->fecha_fin_registro->gt(now())) {
            foreach (['estudiante@campus.test', 'andrea@campus.test'] as $email) {
                $user = User::where('email', $email)->firstOrFail();
                if (! RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', (string) $user->id)->exists()) {
                    app(InscripcionesEvento::class)->inscribir($evento, $user);
                }
            }
        }
    }
}
