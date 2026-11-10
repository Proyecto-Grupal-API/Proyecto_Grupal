<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SecurityEvent;
use App\Models\User;
use Throwable;

/** Read-only E1 capability. E2 must supply the actor from a validated treasury approval. */
class InstitutionalBudgetAuthorizationService
{
    public const ROLE = 'institutional_budget_committer';

    public function __construct(private readonly EffectiveRoleAssignments $assignments,
        private readonly IdentityService $identity) {}

    public function allows(string $actorId, string $action, ?string $budgetId = null): bool
    {
        if ($action !== 'budget.commit' || ! preg_match('/\A[0-9a-f]{24}\z/', $actorId)
            || ! is_string($budgetId)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $budgetId)) {
            return false;
        }
        $allowed = false;
        $reason = 'not-effective';
        try {
            $allowed = $this->effective($actorId, $budgetId);
            $reason = $allowed ? 'effective' : $reason;
        } catch (Throwable) {
            // Never authorize on missing infrastructure, catalogue or malformed persisted data.
            $reason = 'dependency-unavailable';
        }
        try {
            $this->audit($actorId, $budgetId, $allowed, $reason);
        } catch (Throwable) {
            return false;
        }

        return $allowed;
    }

    protected function effective(string $actorId, string $budgetId): bool
    {
        if (! $this->identity->isEligibleFinancialAccount($actorId)
            || ! Role::where('name', self::ROLE)->exists()
            || ! Permission::where('key', 'budget.commit')->where('domain', 'financial')->where('active', true)->exists()
            || ! RolePermission::where('role_key', self::ROLE)->where('permission_key', 'budget.commit')->exists()) {
            return false;
        }
        $user = User::find($actorId);
        if (! $user) {
            return false;
        }

        return $this->assignments->queryForRoles($user, [self::ROLE])
            ->where('scope_type', 'institutional_budget')->where('scope_id', $budgetId)
            ->whereRaw(['scope_type' => ['$exists' => true], 'scope_id' => ['$exists' => true]])
            ->whereNull('revoked_at')->exists();
    }

    protected function audit(string $actorId, string $budgetId, bool $allowed, string $reason): void
    {
        SecurityEvent::log(['user_id' => $actorId, 'type' => 'institutional_budget_authorization',
            'severity' => $allowed ? 'info' : 'warning',
            'metadata' => ['operation' => 'budget.commit', 'budget_public_id' => $budgetId,
                'authorized' => $allowed, 'reason' => $reason]]);
    }
}
