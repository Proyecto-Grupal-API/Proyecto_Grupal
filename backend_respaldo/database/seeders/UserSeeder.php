<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed de usuarios para desarrollo y pruebas (idempotente vía firstOrCreate).
     * En producción, el administrador debe crearse con:
     * php artisan make:admin
     */
    public function run(): void
    {
        $defaultPassword = config('app.env') === 'production'
            ? null
            : (env('DEMO_USER_PASSWORD') ?: 'Campus2026!');

        if (! $defaultPassword) {
            $this->command->warn('Saltando UserSeeder en producción: no se definen contraseñas de demostración.');
            return;
        }

        $users = [
            [
                'email'        => 'alumno@campus.edu',
                'name'         => 'Valeria Morales Santos',
                'role'         => User::ROLE_STUDENT,
                'student_code' => 'ALU-2026-0842',
                'department'   => 'Ingeniería de Software',
            ],
            [
                'email'        => 'profesor@campus.edu',
                'name'         => 'Dr. Carlos Mendoza Ruiz',
                'role'         => User::ROLE_PROFESSOR,
                'student_code' => 'DOC-1029',
                'department'   => 'Ciencias de la Computación',
            ],
            [
                'email'        => 'admin@campus.edu',
                'name'         => 'Lic. Laura Domínguez',
                'role'         => User::ROLE_ADMIN,
                'student_code' => 'ADM-0045',
                'department'   => 'Coordinación de Recompensas',
            ],
            [
                'email'        => 'platform.admin@campus.edu',
                'name'         => 'Ing. Roberto Silva',
                'role'         => User::ROLE_PLATFORM_ADMIN,
                'student_code' => 'SYS-0001',
                'department'   => 'Tecnologías de la Información',
            ],
            [
                'email'        => 'auditor@campus.edu',
                'name'         => 'Mtra. Elena Gómez',
                'role'         => User::ROLE_AUDITOR,
                'student_code' => 'AUD-0012',
                'department'   => 'Órgano Interno de Control',
            ],
            [
                'email'        => 'system.marketplace@campus.edu',
                'name'         => 'Servicio Integración Marketplace (Equipo 3)',
                'role'         => User::ROLE_SYSTEM,
                'student_code' => 'SRV-MKP',
                'department'   => 'Servicios de Plataforma',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                array_merge($userData, [
                    'password' => Hash::make($defaultPassword),
                    'status'   => 'active',
                ])
            );
        }

        $this->command->info('Usuarios de demostración inicializados con contraseña: ' . $defaultPassword);
    }
}
