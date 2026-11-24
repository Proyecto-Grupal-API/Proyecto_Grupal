<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Models\Role;
use App\Models\User;

/**
 * Autoriza los controles financieros de la interfaz web con los roles de Identidad que cada instalación declara en
 * `financial.access` (`view_roles` y `manage_roles`).
 *
 * Aplica la política que esa configuración ya describe y nada más:
 *  - vacío = nadie tiene acceso (se deniega por defecto);
 *  - view: consultar límites, alertas y conciliaciones;
 *  - manage: crear/modificar límites, cambiar alertas y ejecutar/resolver conciliaciones (incluye view);
 *  - cualquier otra acción (reversos, devoluciones, retenciones, políticas, ajustes, bonos) sigue denegada: Finanzas no
 *    ha definido qué autoridad las habilita y este adaptador no la inventa;
 *  - no concede nada acotado a un monedero: estos controles son institucionales.
 *
 * Cada entrada es un rol de Identidad vigente del usuario: `rol` (global) o `rol@tipo:id` (rol asignado en un contexto,
 * por ejemplo `admin@service:finanzas`). Un rol autodeclarado o heredado de datos antiguos no cuenta: se consulta
 * `User::hasRole`, que sólo reconoce asignaciones vigentes de Identidad.
 */
final class ConfiguredFinancialWebAuthorizer implements FinancialWebAuthorizer
{
    private const VIEW = ['limits.view', 'alerts.view', 'reconciliation.view'];

    private const MANAGE = ['limits.manage', 'limits.evaluate', 'alerts.review', 'reconciliation.run', 'reconciliation.resolve'];

    public function allows(string $userId, string $action, ?string $walletId = null): bool
    {
        if ($walletId !== null) {
            return false;
        }
        $view = in_array($action, self::VIEW, true);
        if (! $view && ! in_array($action, self::MANAGE, true)) {
            return false;
        }
        $declared = (array) config('financial.access.manage_roles', []);
        if ($view) {
            $declared = [...$declared, ...(array) config('financial.access.view_roles', [])];
        }
        $grants = array_filter(array_map(fn ($entry) => $this->parse($entry), $declared));
        if ($grants === []) {
            return false;
        }
        $user = preg_match('/\A[a-f0-9]{24}\z/i', $userId) === 1 ? User::find($userId) : null;
        if (! $user) {
            return false;
        }
        foreach ($grants as [$role, $scopeType, $scopeId]) {
            if ($user->hasRole($role, $scopeType, $scopeId)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: ?string, 2: ?string}|null */
    private function parse(mixed $entry): ?array
    {
        if (! is_string($entry) || preg_match('/\A([a-z_]+)(?:@([a-z_]+):([A-Za-z0-9_.-]+))?\z/', trim($entry), $m) !== 1) {
            return null;
        }
        if (! in_array($m[1], Role::VALID_ROLES, true)) {
            return null;
        }
        if (! isset($m[2])) {
            return [$m[1], null, null];
        }

        return in_array($m[2], Role::VALID_SCOPE_TYPES, true) ? [$m[1], $m[2], $m[3]] : null;
    }
}
