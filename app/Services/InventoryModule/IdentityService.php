<?php
namespace App\Services\InventoryModule;

use App\Exceptions\InventoryModule\IdentityUnavailable;
use App\Services\ClienteModuloHttp;

/** E1 is authoritative. Only access-token caching is shared between requests. */
class IdentityService
{
    public function resolveCurrentUserId(): string
    {
        return (string) (request()->user()?->getAuthIdentifier() ?? '');
    }

    private function once(string $key, callable $load): mixed
    {
        $values = request()->attributes->get('inventory_identity', []);
        if (array_key_exists($key, $values)) { return $values[$key]; }
        $value = $load();
        // A token request may resolve another scope; retain all values loaded in this request.
        $values = request()->attributes->get('inventory_identity', []);
        $values[$key] = $value;
        request()->attributes->set('inventory_identity', $values);
        return $value;
    }

    public function getUser(string $userId): ?array
    {
        if ($userId === '' || $userId !== $this->resolveCurrentUserId()) { return null; }
        $business = app(BusinessContext::class)->id();
        return $this->once('roles:'.$userId.':'.$business, function () use ($userId, $business) {
            try {
                $response = app(ClienteModuloHttp::class)->para('inventario_identidad', 'identity:assignments:read')
                    ->get('/api/v1/identity/assignments', ['subject_id' => $userId, 'scope_type' => 'business', 'scope_id' => $business])->throw()->json();
            } catch (\Throwable $e) { throw new IdentityUnavailable('No fue posible consultar los permisos con Identidad.', 0, $e); }
            if (!is_array($response) || ($response['subject_id'] ?? null) !== $userId
                || ($response['scope_type'] ?? null) !== 'business' || ($response['scope_id'] ?? null) !== $business) {
                throw new IdentityUnavailable('Identidad devolvió un contexto incompatible.');
            }
            if (($response['membership_status'] ?? null) !== 'active') { return null; }
            $roles = [];
            foreach ((array) ($response['roles'] ?? []) as $row) {
                if (!is_array($row) || ($row['status'] ?? '') !== 'active') { continue; }
                $key = $row['role_key'] ?? null;
                if (!is_string($key)) { throw new IdentityUnavailable('Registro de autorización inválido.'); }
                $role = config('team4.role_map_business.'.$key);
                if (is_string($role)) { $roles[] = $role; }
            }
            $roles = array_values(array_unique($roles));
            if ($roles === []) { return null; }
            $actor = request()->user();
            return ['id' => $userId, 'name' => $actor->name, 'email' => $actor->email,
                'initials' => mb_strtoupper(mb_substr($actor->name, 0, 2)), 'role' => $roles[0], 'roles' => $roles,
                'role_scope' => 'business', 'role_business_id' => $business];
        });
    }

    private function capability(string $userId, string $capability): bool
    {
        $business = app(BusinessContext::class)->id();
        return $this->once('allow:'.$userId.':'.$business.':'.$capability, function () use ($userId, $business, $capability) {
            try {
                $answer = app(ClienteModuloHttp::class)->para('inventario_identidad', 'identity:authorization:check')
                    ->post('/api/v1/identity/authorization/check', ['subject_id' => $userId,
                        'scope_type' => 'business', 'scope_id' => $business, 'capability' => $capability])->throw()->json();
            } catch (\Throwable $e) { throw new IdentityUnavailable('No fue posible validar la autorización con Identidad.', 0, $e); }
            if (!is_array($answer) || !is_bool($answer['authorized'] ?? null)) { throw new IdentityUnavailable('Respuesta de autorización inválida.'); }
            return $answer['authorized'];
        });
    }

    public function getPermissions(string|array|null $roles): array
    {
        $result = [];
        foreach ((array) $roles as $role) {
            $perms = config('team4_permissions.roles.'.$role.'.permissions', []);
            if (in_array('*', $perms, true)) { return config('team4_permissions.all', []); }
            $result = array_merge($result, $perms);
        }
        return array_values(array_unique($result));
    }

    public function userCan(string $userId, string $permission): bool
    {
        $roles = $this->getUser($userId)['roles'] ?? [];
        if (!in_array($permission, $this->getPermissions($roles), true)) { return false; }
        // Approving/cancelling purchases or deleting catalogue entries requires the owner's capability.
        if (in_array($permission, ['productos.delete', 'souvenirs.delete', 'compras.authorize', 'compras.cancel'], true)) {
            return $this->capability($userId, 'business.manage');
        }
        if ($permission === 'dashboard.view' || str_ends_with($permission, '.view')) {
            return $this->capability($userId, 'business.inventory.operate') || $this->capability($userId, 'business.operate');
        }
        if ($roles === ['inventory_manager']) { return $this->capability($userId, 'business.inventory.operate'); }
        $purchase = str_starts_with($permission, 'proveedores.') || str_starts_with($permission, 'compras.')
            || $permission === 'recepciones.create' || $permission === 'devoluciones.storeSupplier' || $permission === 'costos.register';
        return $this->capability($userId, $purchase ? 'business.operate' : 'business.inventory.operate');
    }

    public function effectivePermissions(string $userId): array
    {
        return array_values(array_filter($this->getPermissions($this->getRoles($userId)), fn ($p) => $this->userCan($userId, $p)));
    }

    public function getRoles(string $id): array { return $this->getUser($id)['roles'] ?? []; }
    public function getRole(string $id): ?string { return $this->getUser($id)['role'] ?? null; }
    public function getSharedUser(string $id): ?array { return $this->getUser($id); }
    public function isAuthorized(string|array|null $roles): bool { return $this->getPermissions($roles) !== []; }
    public function getDashboardKpis(string|array|null $roles): array
    {
        $result=[]; foreach ((array) $roles as $role) { $result=array_merge($result, config('team4_permissions.dashboard_kpis.'.$role, [])); }
        return array_values(array_unique($result));
    }
}
