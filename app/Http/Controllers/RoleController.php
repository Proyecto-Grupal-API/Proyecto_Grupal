<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RoleController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $canAssignRoles = $user->hasRole(Role::ADMIN);

        return Inertia::render('Roles/Index', [
            'assignableRoles' => array_map(static fn (string $name): array => [
                'name' => $name,
                'display_name' => $name,
            ], Role::VALID_ROLES),
            'assignableScopes' => Role::VALID_SCOPE_TYPES,
            'assignableUsers' => $canAssignRoles
                ? User::query()->orderBy('name')->orderBy('email')->get()->map(static fn (User $target): array => [
                    'id' => (string) $target->getKey(),
                    'name' => $target->name,
                    'email' => $target->email,
                    'roles' => $target->roles ?? [],
                ])->values()->all()
                : [],
            'userRoles' => $user->roles ?? [],
            'twoFactorEnabled' => $user->two_factor_enabled,
            'twoFactorConfigurationPending' => ! is_null($user->two_factor_secret)
                && is_null($user->two_factor_confirmed_at),
            'canAssignRoles' => $canAssignRoles,
        ]);
    }

    /**
     * Asignar un rol a un usuario.
     *
     * P0 corregido: antes este endpoint solo comprobaba "¿esta
     * autenticado?" y asignaba el rol solicitado al propio usuario que
     * hacia la peticion, sin validar el nombre del rol contra ningun
     * catalogo. Eso permitia que cualquier usuario autenticado se
     * autoasignara el rol "admin" (escalacion de privilegios).
     *
     * Ahora:
     *  - Solo un usuario con rol "admin" puede llegar aqui
     *    (RolePolicy::assign, reforzado ademas por el middleware
     *    'role.context:admin' en routes/web.php).
     *  - El rol a asignar debe existir en el catalogo Role::VALID_ROLES.
     *  - El admin debe identificar expresamente al usuario destinatario.
     */
    public function assign(Request $request)
    {
        $this->authorize('assign', Role::class);

        $validated = $this->validateRoleOperation($request);
        $target = User::findOrFail($validated['user_id']);

        $target->assignRole(
            $validated['role_name'],
            $validated['scope_type'] ?? null,
            $validated['scope_id'] ?? null
        );

        return redirect()->back()->with('success', 'Rol asignado correctamente.');
    }

    public function revoke(Request $request)
    {
        $this->authorize('revoke', Role::class);

        $validated = $this->validateRoleOperation($request);
        $target = User::findOrFail($validated['user_id']);
        $revoked = $target->revokeRole(
            $validated['role_name'],
            $validated['scope_type'] ?? null,
            $validated['scope_id'] ?? null
        );

        return redirect()->back()->with('success', $revoked
            ? 'Rol revocado correctamente.'
            : 'La asignación indicada ya no existe.');
    }

    private function validateRoleOperation(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'string', 'exists:users,_id'],
            'role_name' => ['required', 'string', Rule::in(Role::VALID_ROLES)],
            'scope_type' => ['nullable', 'string', Rule::in(Role::VALID_SCOPE_TYPES), 'required_with:scope_id'],
            'scope_id' => [
                'nullable',
                'string',
                'max:100',
                'required_with:scope_type',
                Rule::prohibitedIf(fn () => ! $request->filled('scope_type')),
            ],
        ]);
    }
}
