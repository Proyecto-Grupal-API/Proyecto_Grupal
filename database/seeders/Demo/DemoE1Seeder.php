<?php

namespace Database\Seeders\Demo;

use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Role;

/** E1 Identidad: administración (rol global `admin`, con 2FA por interfaz) y un alumno. */
class DemoE1Seeder extends DemoTeamSeeder
{
    public function run(): void
    {
        $this->guard();
        // Segundo campus con su carrera: permite demostrar el rechazo «la carrera no pertenece al campus».
        $campus = Campus::firstOrCreate(['code' => DemoTeams::SECOND_CAMPUS], ['name' => 'Campus Norte', 'is_active' => true]);
        AcademicProgram::firstOrCreate(['code' => DemoTeams::SECOND_PROGRAM], ['campus_id' => (string) $campus->id, 'name' => 'Arquitectura', 'is_active' => true]);

        $this->globalRole($this->account('demo.e1'), Role::ADMIN);
        $this->student($this->account('demo.e1.alumno'));
    }
}
