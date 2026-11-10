<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

/** Trusted in-process E2/E5 calls only. No OAuth actor, grant writer or financial persistence. */
class PilotOperationAuthorization
{
    public function __construct(private readonly EffectiveRoleAssignments $assignments,
        private readonly RecentTwoFactor $factor) {}

    public function allowsRefund(Request $request, string $walletPublicId, string $operation): bool
    {
        if (! self::walletId($walletPublicId) || ! in_array($operation, ['approve', 'reject', 'execute'], true)
            || ! $this->factor->hasActiveSession($request)) {
            return false;
        }
        $role = $operation === 'execute' ? 'financial_refund_executor' : 'financial_refund_reviewer';
        $allowed = $this->hasCapability($request->user('web'), $role, 'wallet', $walletPublicId)
            && ($operation === 'reject' || $this->factor->isRecent($request));
        $this->audit($request, ['operation' => 'refund.'.$operation, 'wallet_public_id' => $walletPublicId], $allowed);

        return $allowed;
    }

    /** Read-only scope discovery for the refunds inbox. Actions still require allowsRefund(). */
    public function refundScopes(Request $request): array
    {
        if (! $this->factor->hasActiveSession($request)) {
            return [];
        }

        $scopes = [];
        foreach (['review' => 'financial_refund_reviewer', 'execute' => 'financial_refund_executor'] as $action => $role) {
            if (! $this->hasCatalogCapability($role, 'wallet')) {
                continue;
            }
            foreach ($this->assignments->queryForRoles($request->user('web'), [$role])->where('scope_type', 'wallet')
                ->whereNull('revoked_at')->pluck('scope_id') as $id) {
                if (is_string($id) && self::walletId($id)) {
                    $scopes[$id] ??= ['review' => false, 'execute' => false];
                    $scopes[$id][$action] = true;
                }
            }
        }

        return $scopes;
    }

    /** Positive damage charges only. A zero-charge release retains E5's existing counter policy. */
    public function allowsRentalDamage(Request $request, string $rentalId, string $holdId, mixed $amountCents,
        string $operation = 'rentals.deposit.settle'): bool
    {
        if ($operation !== 'rentals.deposit.settle' || ! is_int($amountCents) || $amountCents <= 0
            || ! $this->reference($rentalId) || ! $this->reference($holdId)
            || ! $this->factor->hasActiveSession($request)) {
            return false;
        }
        $allowed = $this->hasCapability($request->user('web'), 'operador_servicios', null, null)
            && $this->factor->isRecent($request);
        $this->audit($request, ['operation' => $operation, 'rental_id' => $rentalId,
            'hold_id' => $holdId, 'damage_charge_cents' => $amountCents], $allowed);

        return $allowed;
    }

    public function hasRentalDamageAuthority(Request $request): bool
    {
        return $this->factor->hasActiveSession($request)
            && $this->hasCapability($request->user('web'), 'operador_servicios', null, null);
    }

    public static function walletId(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id) === 1;
    }

    private function reference(string $id): bool
    {
        return $id !== '' && strlen($id) <= 100 && ! preg_match('/[\x00-\x20\x7f]/', $id);
    }

    protected function hasCapability(User $user, string $role, ?string $type, ?string $id): bool
    {
        if (! $this->hasCatalogCapability($role, $type)) {
            return false;
        }
        // No global fallback. Reuse E1's inclusive-start/exclusive-end/current lifecycle on every call.
        return $this->assignments->queryForRoles($user, [$role])->where('scope_type', $type)->where('scope_id', $id)
            ->whereRaw(['scope_type' => ['$exists' => true], 'scope_id' => ['$exists' => true]])
            ->whereNull('revoked_at')->exists();
    }

    private function hasCatalogCapability(string $role, ?string $type): bool
    {
        $permission = RolePermission::PILOT_MAPPINGS[$role][0];

        return Role::PILOT_ROLE_SCOPES[$role] === $type
            && Role::where('name', $role)->exists()
            && Permission::where('key', $permission)->where('domain', Permission::TECHNICAL_CATALOG[$permission][1])
                ->where('active', true)->exists()
            && RolePermission::where('role_key', $role)->where('permission_key', $permission)->exists();
    }

    protected function audit(Request $request, array $operation, bool $allowed): void
    {
        SecurityEvent::log(['user_id' => (string) $request->user('web')->getKey(),
            'session_id' => $request->session()->get('cd_session_id'), 'type' => 'pilot_operation_authorization',
            'severity' => $allowed ? 'info' : 'warning', 'metadata' => [...$operation, 'allowed' => $allowed]]);
    }
}
