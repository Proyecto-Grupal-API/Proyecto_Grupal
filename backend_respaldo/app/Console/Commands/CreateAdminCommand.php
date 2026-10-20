<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    protected $signature = 'make:admin {--email= : Correo electrónico del administrador} {--name= : Nombre completo} {--password= : Contraseña inicial (si no se especifica, se genera una segura)} {--platform : Asignar rol platform_admin en vez de admin normal}';

    protected $description = 'Crea o actualiza de forma segura un usuario administrador del sistema sin contraseñas predeterminadas';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Correo electrónico del administrador');
        $name = $this->option('name') ?: $this->ask('Nombre completo', 'Administrador del Sistema');
        $isPlatform = $this->option('platform');
        $role = $isPlatform ? User::ROLE_PLATFORM_ADMIN : User::ROLE_ADMIN;

        $password = $this->option('password');
        $isGenerated = false;

        if (! $password) {
            if ($this->confirm('¿Deseas generar una contraseña aleatoria segura?', true)) {
                $password = Str::password(16);
                $isGenerated = true;
            } else {
                $password = $this->secret('Escribe la contraseña deseada');
            }
        }

        $user = User::where('email', strtolower(trim($email)))->first();

        if ($user) {
            if (! $this->confirm("El usuario [{$email}] ya existe con rol [{$user->role}]. ¿Deseas actualizarlo a [{$role}] y restablecer su contraseña?")) {
                $this->info('Operación cancelada.');
                return 0;
            }

            $user->update([
                'name'     => $name,
                'role'     => $role,
                'status'   => 'active',
                'password' => Hash::make($password),
            ]);

            $this->info("Usuario [{$email}] actualizado a [{$role}].");
        } else {
            $user = User::create([
                'email'        => strtolower(trim($email)),
                'name'         => $name,
                'role'         => $role,
                'status'       => 'active',
                'student_code' => 'ADM-' . strtoupper(Str::random(4)),
                'password'     => Hash::make($password),
            ]);

            $this->info("Administrador creado exitosamente con rol [{$role}].");
        }

        AuditLog::create([
            'actor_id'      => 'console',
            'actor_role'    => 'system',
            'action'        => 'admin.bootstrapped',
            'entity_type'   => 'User',
            'entity_id'     => (string) $user->getKey(),
            'after'         => ['email' => $email, 'role' => $role],
            'source_domain' => 'auth',
        ]);

        if ($isGenerated) {
            $this->warn('====================================================');
            $this->warn("Credenciales generadas para: {$email}");
            $this->warn("Contraseña: {$password}");
            $this->warn('Guarda esta contraseña en un gestor seguro. No se volverá a mostrar.');
            $this->warn('====================================================');
        }

        return 0;
    }
}
