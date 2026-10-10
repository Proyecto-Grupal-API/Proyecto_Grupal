<?php
namespace App\Console\Commands;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Console\Command;
final class BootstrapBonusRoleAdministrator extends Command
{
    protected $signature = 'financial:bonus-roles-bootstrap {email} {--reason=} {--confirm}';
    protected $description = 'Inicializa explícitamente el primer administrador de roles de bonos solo en desarrollo local.';
    public function handle(): int
    {
        if (!app()->environment('local') || !config('financial.bonus_roles.enabled') || !$this->option('confirm') || trim((string)$this->option('reason')) === '') {
            $this->error('Requiere entorno local, integración activa, --confirm y --reason.'); return self::FAILURE;
        }
        $lock = fopen(storage_path('app/bonus-role-bootstrap.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $this->error('El bootstrap está en curso o no pudo bloquearse.'); if ($lock) { fclose($lock); } return self::FAILURE; }
        try { return $this->createInitialAdministrator(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function createInitialAdministrator(): int
    {
        if (RoleAssignment::where('role_key','admin')->exists()) { $this->error('Ya existe historial de administración global. Usa el flujo administrativo existente.'); return self::FAILURE; }
        $user = User::where('email', $this->argument('email'))->whereNull('deleted_at')->first();
        if (!$user || !$user->hasVerifiedEmail() || $user->account_activation_pending || $user->must_change_password) { $this->error('La cuenta debe existir, estar activa y tener correo verificado.'); return self::FAILURE; }
        $record = RoleAssignment::create(['user_id' => (string)$user->getKey(), 'role_key' => 'admin', 'scope_type' => null, 'scope_id' => null,
            'status' => 'active', 'assigned_by' => (string)$user->getKey(), 'reason' => trim($this->option('reason')), 'origin' => 'explicit_local_bootstrap']);
        $this->info('Administrador inicial registrado: '.$record->getKey().'. No se modificaron roles embebidos.'); return self::SUCCESS;
    }
}
