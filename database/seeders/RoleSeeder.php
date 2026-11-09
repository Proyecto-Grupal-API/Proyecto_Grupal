<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Catálogo de roles vigente para esta etapa del proyecto académico.
     * Debe mantenerse en sincronía con App\Models\Role::VALID_ROLES,
     * que es la fuente de verdad que valida las asignaciones de rol.
     */
    public function run(): void
    {
        $roles = [
            ['name' => Role::ADMIN, 'display_name' => 'Administrador de Plataforma', 'description' => 'Gestión y auditoría global: roles, credenciales NFC/QR y perfiles de estudiante.'],
            ['name' => Role::MAESTRO, 'display_name' => 'Maestro', 'description' => 'Personal académico con capacidad de consulta y gestión de estudiantes.'],
            ['name' => Role::ESTUDIANTE, 'display_name' => 'Estudiante', 'description' => 'Acceso general al campus: su perfil, wallet, credenciales y servicios.'],
            ['name' => Role::SERVICIO_CAFETERIA, 'display_name' => 'Servicio de Cafetería', 'description' => 'Personal autorizado del negocio de cafetería.'],
            ['name' => Role::CONSEJO_ESTUDIANTIL, 'display_name' => 'Consejo Estudiantil', 'description' => 'Integrantes del Consejo Estudiantil: becas, beneficios y comunicación.'],
            ['name' => Role::STUDENT_MANAGER, 'display_name' => 'Gestor de estudiantes', 'description' => 'Administra perfiles y condición académica'],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(['name' => $roleData['name']], $roleData);
        }
    }
}
