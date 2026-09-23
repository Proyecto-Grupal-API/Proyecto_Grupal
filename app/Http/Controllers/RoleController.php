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

        return Inertia::render('Roles/Index', [
            'availableRoles' => Role::all(),
            'userRoles' => $user->roles ?? [],
            'twoFactorEnabled' => $user->two_factor_enabled,
            'twoFactorConfigurationPending' => ! is_null($user->two_factor_secret)
                && is_null($user->two_factor_confirmed_at),
            'canAssignRoles' => $user->hasRole(Role::ADMIN),
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
     *  - El admin puede asignar el rol a si mismo o a otro usuario
     *    (user_id opcional); nadie mas puede asignarse roles.
     */
    public function assign(Request $request)
    {
        $this->authorize('assign', Role::class);

        $validated = $request->validate([
            'role_name' => ['required', 'string', Rule::in(Role::VALID_ROLES)],
            'user_id' => ['nullable', 'string', 'exists:users,_id'],
            'scope_type' => [
                'nullable',
                'string',
                Rule::in(Role::VALID_SCOPE_TYPES),
                Rule::requiredIf(fn () => $request->filled('scope_id')),
            ],
            'scope_id' => [
                'nullable',
                'string',
                'max:100',
                Rule::requiredIf(fn () => $request->filled('scope_type')),
            ],
        ]);

        $target = ($validated['user_id'] ?? null)
            ? User::findOrFail($validated['user_id'])
            : $request->user();

        $target->assignRole(
            $validated['role_name'],
            $validated['scope_type'] ?? null,
            $validated['scope_id'] ?? null
        );

        return redirect()->back()->with('success', 'Rol asignado correctamente.');
    }
}
