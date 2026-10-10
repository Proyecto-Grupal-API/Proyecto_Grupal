<?php
namespace App\Domains\Identity\Services;
use App\Domains\Identity\Authorization\FinancialRoleCatalog as Catalog;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Domains\Identity\Exceptions\AuthorizationDependencyUnavailable;
use App\Models\AuthorizationLifecycleRecord;
use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Domains\Identity\Services\EffectiveBonusRoleAssignments;
use Illuminate\Validation\ValidationException;
final class FinancialCapabilityAuthorization implements ScopedCapabilityAuthorization
{
    public function __construct(private readonly EffectiveBonusRoleAssignments $assignments) {}
    public function authorized(string $userId, string $capability, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        if (($scopeType === null) !== ($scopeId === null) || ($scopeType !== null && !in_array($scopeType, Catalog::SCOPES, true))) { return false; }
        if ($scopeType !== null) {
            try { $scopeId = AuthorizationLifecycleRecord::normalizeIdentifier($scopeId, 'scope_id'); }
            catch (ValidationException) { return false; }
        }
        foreach ($this->contexts($userId, $capability) as $context) {
            if (($context['scope_type'] === null && ($scopeType === null || str_starts_with($capability, 'bonus.issue.'))) || ($context['scope_type'] === $scopeType && $context['scope_id'] === $scopeId)) { return true; }
        }
        return false;
    }
    public function contexts(string $userId, string $capability): array
    {
        if (!config('financial.bonus_roles.enabled') || !preg_match('/\A[a-f0-9]{24}\z/i', $userId) || !isset(Catalog::PERMISSIONS[$capability])) { return []; }
        try {
            $user = User::whereKey(strtolower($userId))->whereNull('deleted_at')->first();
            if (!$user || $user->account_activation_pending === true || (bool)$user->must_change_password || !$user->hasVerifiedEmail()
                || !Permission::where('key', $capability)->where('domain', Catalog::PERMISSIONS[$capability][1])->where('active', true)->exists()) { return []; }
            $roles = Role::whereIn('name', array_keys(Catalog::ROLES))->pluck('name')->all(); $result = [];
            foreach ($this->assignments->queryForRoles($user, $roles)->get() as $assignment) {
                $role = $assignment->role_key; $type = Catalog::ROLES[$role][1];
                if (!in_array($capability, Catalog::MAPPINGS[$role] ?? [], true) || $assignment->scope_type !== $type
                    || (($type === null) !== ($assignment->scope_id === null))) { continue; }
                if ($type !== null) {
                    try { $id = AuthorizationLifecycleRecord::normalizeIdentifier($assignment->scope_id, 'scope_id'); }
                    catch (ValidationException) { continue; }
                } else { $id = null; }
                if ($type === 'business' && !$this->assignments->activeCurrent(BusinessMembership::where('user_id', (string)$user->getKey())->where('business_id', $id))->exists()) { continue; }
                if (!RolePermission::where('role_key', $role)->where('permission_key', $capability)->exists()) { continue; }
                $result[json_encode([$type, $id])] = ['scope_type' => $type, 'scope_id' => $id];
            }
            return array_values($result);
        } catch (\Throwable $e) { throw new AuthorizationDependencyUnavailable('La autorización de identidad no está disponible.', 0, $e); }
    }
}
