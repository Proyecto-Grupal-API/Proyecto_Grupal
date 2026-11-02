<?php

namespace App\Services;

use App\Contracts\FinancialGrantAuthority;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\SecurityEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Dormant writer: no route/binding; production locked until institutional designation. */
final class FinancialRoleGrantService
{
    public function __construct(private FinancialGrantAuthority $authority, private BusinessAuthorizationService $identities) {}

    private function authorize(?User $actor, ?User $subject, string $operation, string $role, ?string $type, ?string $id, string $reason): array
    {
        // Even manual construction with a policy cannot unlock production in this batch.
        if (! app()->environment('testing') || config('database.default') !== 'mongodb'
            || config('database.connections.mongodb.database') !== 'campus_virtual_testing'
            || DB::connection('mongodb')->getDatabaseName() !== 'campus_virtual_testing') {
            throw new AuthorizationException('Financial grant authority has not been designated.');
        }
        $actor = $this->identities->validUser($actor);
        $subject = $this->identities->validUser($subject);
        if (! $actor || ! $subject || (string) $actor->getKey() === (string) $subject->getKey()
            || ! array_key_exists($role, Role::FINANCIAL_ROLE_SCOPES)
            || Role::FINANCIAL_ROLE_SCOPES[$role] !== $type || (($type === null) !== ($id === null))
            || trim($reason) === '' || strlen($reason) > 1000) {
            throw new AuthorizationException('Financial role operation denied.');
        }
        $id = $type === null ? null : $this->identities->businessId($id);
        if ($type !== null && $id === null) {
            throw new AuthorizationException('Invalid financial context.');
        }
        if ($type === 'academic_program') {
            $program = AcademicProgram::whereKey($id)->first();
            if ($operation === 'grant' && (! $program || ! $program->is_active
                || ! Campus::whereKey($program->campus_id)->where('is_active', true)->exists())) {
                throw new AuthorizationException('Invalid financial context.');
            }
            // Inactive/removed resources must not prevent retirement of an existing grant.
            $id = $program ? (string) $program->getKey() : $id;
        }
        if ($operation === 'grant' && $type === 'business' && ! $this->identities->hasMembership($subject, $id)) {
            throw new AuthorizationException('A current business membership is required.');
        }
        if (! $this->authority->allows($actor, $subject, $operation, $role, $type, $id)
            || trim($this->authority->reference()) === '' || strlen($this->authority->reference()) > 150) {
            throw new AuthorizationException('Financial role operation denied.');
        }

        return [$actor, $subject, $id];
    }

    public function grant(?User $actor, ?User $subject, string $role, ?string $type, ?string $id, string $reason, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): RoleAssignment
    {
        [$actor, $subject, $id] = $this->authorize($actor, $subject, 'grant', $role, $type, $id, $reason);
        if (($endsAt && $endsAt->lte(now())) || ($startsAt && $endsAt && $startsAt->gte($endsAt))) {
            throw ValidationException::withMessages(['ends_at' => 'Invalid financial validity window.']);
        }

        return DB::connection('mongodb')->transaction(function () use ($actor, $subject, $role, $type, $id, $reason, $startsAt, $endsAt) {
            // Revalidate inside the same transaction as the record and audit.
            $this->authorize($actor, $subject, 'grant', $role, $type, $id, $reason);
            $capability = RolePermission::FINANCIAL_MAPPINGS[$role][0];
            if (! Role::where('name', $role)->exists()
                || ! Permission::where('key', $capability)->where('domain', 'financial')->where('active', true)->exists()
                || ! RolePermission::where('role_key', $role)->where('permission_key', $capability)->exists()) {
                throw new AuthorizationException('The approved financial catalog must be persisted first.');
            }
            if ($this->identity($subject, $role, $type, $id)->exists()) {
                throw ValidationException::withMessages(['role_key' => 'An existing current generation cannot be replaced or reactivated.']);
            }
            $record = RoleAssignment::create([
                'user_id' => (string) $subject->getKey(), 'role_key' => $role, 'scope_type' => $type, 'scope_id' => $id,
                'status' => 'active', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'assigned_by' => (string) $actor->getKey(), 'reason' => $reason, 'origin' => 'financial_role_management',
            ]);
            $this->audit($actor, $record, 'financial.role.granted', $reason);

            return $record;
        });
    }

    public function revoke(?User $actor, ?User $subject, string $role, ?string $type, ?string $id, string $reason): bool
    {
        [$actor, $subject, $id] = $this->authorize($actor, $subject, 'revoke', $role, $type, $id, $reason);

        return DB::connection('mongodb')->transaction(function () use ($actor, $subject, $role, $type, $id, $reason) {
            $this->authorize($actor, $subject, 'revoke', $role, $type, $id, $reason);
            $record = $this->identity($subject, $role, $type, $id)->first();
            if (! $record) {
                return false;
            }
            $record->transitionTo('revoked', (string) $actor->getKey(), $reason);
            $this->audit($actor, $record, 'financial.role.revoked', $reason);

            return true;
        });
    }

    private function identity(User $subject, string $role, ?string $type, ?string $id)
    {
        return RoleAssignment::where('user_id', (string) $subject->getKey())->where('role_key', $role)
            ->where('scope_type', $type)->where('scope_id', $id)->where('is_current', true);
    }

    private function audit(User $actor, RoleAssignment $record, string $type, string $reason): void
    {
        SecurityEvent::log(['user_id' => (string) $record->user_id, 'type' => $type, 'severity' => 'info',
            'metadata' => ['actor_id' => (string) $actor->getKey(), 'authority_reference' => $this->authority->reference(),
                'assignment_id' => (string) $record->getKey(), 'role_key' => $record->role_key,
                'scope_type' => $record->scope_type, 'scope_id' => $record->scope_id,
                'generation' => $record->generation, 'revision' => $record->revision, 'reason' => $reason]]);
    }
}
