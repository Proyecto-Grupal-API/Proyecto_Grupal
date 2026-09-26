<?php

namespace Database\Seeders;

use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class IntegracionEquipo1Seeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('La demostración integrada solo puede prepararse en local/testing.');
        }
        $this->call([DatabaseSeeder::class, EventoSeeder::class, BecaSeeder::class, ComunicacionSeeder::class, ParticipacionSeeder::class, ReporteSeeder::class, StaffEventoSeeder::class, GestionOrganizacionesSeeder::class]);
        $admin = User::firstOrCreate(['email' => 'identidad@campus.test'], ['name' => 'Administración Identidad', 'password' => 'CampusDemo2026!', 'email_verified_at' => now()]);
        $admin->assignRole(Role::ADMIN);
        $campus = Campus::where('code', 'CENTRAL')->firstOrFail();
        $program = AcademicProgram::where('code', 'ISC')->firstOrFail();
        foreach (User::whereNotNull('matricula')->get() as $user) {
            if (! $user->studentProfile) {
                app(UpsertStudentProfile::class)->execute([
                    'name' => $user->name, 'email' => $user->email,
                    'enrollment_number' => $user->matricula,
                    'campus_id' => (string) $campus->id, 'academic_program_id' => (string) $program->id,
                    'current_semester' => 8, 'academic_status' => 'active',
                    'status_reason' => 'Perfil de demostración de integración',
                ], $user, $admin);
                $user->assignRole(Role::ESTUDIANTE);
            }
        }
    }
}
