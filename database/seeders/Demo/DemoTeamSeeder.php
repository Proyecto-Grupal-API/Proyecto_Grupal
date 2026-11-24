<?php

namespace Database\Seeders\Demo;

use App\Actions\Students\UpsertStudentProfile;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use App\Models\AcademicProgram;
use App\Models\BusinessMembership;
use App\Models\Campus;
use App\Models\DelegacionOrganizacion;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\DelegacionesComunidad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use RuntimeException;

/**
 * Base de los seeders de demostración por equipo. Reglas comunes:
 * - sólo local/testing;
 * - alta sólo si la cuenta no existe (una cuenta cerrada no se recrea ni se reabre);
 * - nunca se reescribe contraseña ni 2FA de una cuenta existente;
 * - los permisos son DATOS (asignaciones, membresías, cargos, delegaciones): no se toca código de autorización.
 */
abstract class DemoTeamSeeder extends Seeder
{
    protected function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Las cuentas de demostración por equipo sólo se crean en local/testing.');
        }
    }

    /** Contraseña de las cuentas sintéticas: sólo desde configuración privada (adenda 21.E); nunca escrita en el repositorio. */
    private function password(): string
    {
        return DemoPassword::value();
    }

    /** Devuelve la cuenta, creándola sólo si nunca existió. Null si el usuario la cerró. */
    protected function account(string $alias): ?User
    {
        [$name, $matricula] = DemoTeams::ACCOUNTS[$alias];
        $existing = User::withTrashed()->where('email', DemoTeams::email($alias))->first();
        if ($existing) {
            return $existing->trashed() ? null : $existing;
        }
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => DemoTeams::email($alias),
            'password' => $this->password(), 'email_verified_at' => now(),
            ...($matricula === null ? [] : ['matricula' => $matricula])])->save();

        return $user;
    }

    protected function globalRole(?User $user, string $role): void
    {
        if ($user && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    /**
     * Cargo administrativo acotado a un servicio: el rol `admin` de Identidad en el contexto `service` (módulo 1.3).
     * No convierte a la cuenta en administradora global; el módulo dueño del servicio decide qué le permite.
     */
    protected function serviceRole(?User $user, string $service, string $role = Role::ADMIN): void
    {
        if ($user && ! $user->hasRole($role, 'service', $service)) {
            $user->assignRole($role, 'service', $service);
        }
    }

    /** Corrige cuentas de demo sembradas antes con un rol global que ya no necesitan (queda en el historial como revocado). */
    protected function withoutGlobalRole(?User $user, string $role): void
    {
        if ($user && $user->hasRole($role)) {
            $user->revokeRole($role);
        }
    }

    /** Perfil académico activo y rol `estudiante`. */
    protected function student(?User $user, string $campusCode = 'CENTRAL', string $programCode = 'ISC'): void
    {
        if (! $user) {
            return;
        }
        if (! $user->studentProfile) {
            $campus = Campus::where('code', $campusCode)->firstOrFail();
            $program = AcademicProgram::where('code', $programCode)->firstOrFail();
            app(UpsertStudentProfile::class)->execute([
                'name' => $user->name, 'email' => $user->email, 'enrollment_number' => $user->matricula,
                'campus_id' => (string) $campus->id, 'academic_program_id' => (string) $program->id,
                'current_semester' => 5, 'academic_status' => 'active',
                'status_reason' => 'Perfil de demostración por equipo',
            ], $user, $user);
        }
        $this->globalRole($user->fresh(), Role::ESTUDIANTE);
    }

    /** Membresía y rol contextual de negocio en Identidad (registros inmutables: se comprueba antes de crear). */
    protected function businessRole(?User $user, string $business, string $roleKey, ?User $assignedBy = null): void
    {
        if (! $user) {
            return;
        }
        $id = (string) $user->getKey();
        $by = (string) ($assignedBy ?? $user)->getKey();
        $reason = 'Asignación sintética de la demostración por equipo.';
        if (! BusinessMembership::where('user_id', $id)->where('business_id', $business)->exists()) {
            BusinessMembership::create(['user_id' => $id, 'business_id' => $business, 'status' => 'active', 'assigned_by' => $by, 'reason' => $reason]);
        }
        if (! RoleAssignment::where('user_id', $id)->where('role_key', $roleKey)->where('scope_type', 'business')->where('scope_id', $business)->exists()) {
            RoleAssignment::create(['user_id' => $id, 'role_key' => $roleKey, 'scope_type' => 'business', 'scope_id' => $business,
                'status' => 'active', 'assigned_by' => $by, 'reason' => $reason]);
        }
    }

    /** Organización de Comunidad con identificador fijo, integrantes y presidencia (sólo si nunca hubo una). */
    protected function organization(string $id, string $slug, string $name, ?User $president, array $members): ?Organizacion
    {
        if (! $president) {
            return Organizacion::find($id);
        }
        $org = Organizacion::find($id);
        if (! $org) {
            $org = new Organizacion;
            $org->forceFill(['_id' => new ObjectId($id), 'slug' => $slug, 'nombre' => $name, 'tipo' => 'asociacion',
                'descripcion' => 'Organización sintética de la demostración por equipo.', 'email' => $president->email,
                'telefono' => '', 'estado' => 'activa'])->save();
        }
        foreach ([$president, ...$members] as $member) {
            if ($member) {
                MiembroOrganizacion::firstOrCreate(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $member->id],
                    ['estado' => 'activo', 'fecha_inicio' => today()->subMonth(), 'eliminado_en' => null]);
            }
        }
        if (! RolOrganizacion::where('organizacion_id', (string) $org->id)->where('slug_rol', 'presidencia')->exists()) {
            RolOrganizacion::create(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $president->id, 'slug_rol' => 'presidencia',
                'fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->addYear(), 'otorgado_por' => (string) $president->id, 'eliminado_en' => null]);
        }

        return $org;
    }

    /** Delegación temporal otorgada por la presidencia con el servicio de Comunidad (firma y auditoría reales). */
    protected function delegate(?Organizacion $org, ?User $from, ?User $to, array $capabilities): void
    {
        if (! $org || ! $from || ! $to) {
            return;
        }
        $covered = DelegacionOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('destinatario_id', (string) $to->id)->get()
            ->contains(fn ($d) => array_diff($capabilities, (array) $d->capacidades) === []);
        if ($covered) {
            return;
        }
        $zone = 'America/Mexico_City';
        app(DelegacionesComunidad::class)->otorgar($from, $org, [
            'destinatario_id' => (string) $to->id, 'capacidades' => $capabilities,
            'inicio' => now($zone)->format('Y-m-d\TH:i'), 'fin' => now($zone)->addDays(300)->format('Y-m-d\TH:i'),
            'motivo' => 'Delegación sintética de la demostración por equipo.', 'clave_idempotencia' => (string) Str::uuid(),
        ]);
    }

    protected function wallet(string $ownerType, string $ownerId, WalletType $type): Wallet
    {
        $wallets = app(WalletService::class);

        return $wallets->findByOwner($ownerType, $ownerId, $type) ?? $wallets->create($ownerType, $ownerId, $type);
    }

    protected function personalWallet(?User $user): ?Wallet
    {
        return $user ? $this->wallet('USER', strtolower((string) $user->getKey()), WalletType::USUARIO) : null;
    }

    /** Saldo inicial sintético, una sola vez y siempre por el ledger (el saldo cuadra con sus asientos). */
    protected function openingBalance(?Wallet $wallet, int $cents): void
    {
        if (! $wallet) {
            return;
        }
        $key = 'demo-seed:'.$wallet->public_id;
        if (! FinancialTransaction::where('idempotency_key', $key)->exists()) {
            app(LedgerService::class)->credit($wallet, $cents, MovementType::AJUSTE_CREDITO, $key, 'DEMO_SEED', (string) $wallet->public_id);
        }
    }
}
