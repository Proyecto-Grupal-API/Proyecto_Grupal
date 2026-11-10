<?php

namespace App\Services;

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RoleAssignment;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** Synthetic-only designation of council issuers. No global grants, budgets or public endpoint. */
class CouncilBecaDesignationService
{
    public function __construct(private readonly IdentityService $identity) {}

    public function assign(User $president, User $issuer, string $councilId, string $reason): RoleAssignment
    {
        RoleAssignment::assertPilotGrantEnvironment();
        $this->authorize($president, $councilId, $reason);
        if (! $this->identity->isEligibleFinancialAccount((string) $issuer->getKey())) {
            throw new AuthorizationException('El emisor debe ser una identidad elegible.');
        }
        // Existing lifecycle rejects a duplicate current tuple; never silently reactivate a grant.
        return RoleAssignment::create(['user_id' => (string) $issuer->getKey(),
            'role_key' => 'bonus_beca_council_issuer', 'scope_type' => 'council', 'scope_id' => $councilId,
            'status' => 'active', 'assigned_by' => (string) $president->getKey(),
            'reason' => trim($reason), 'origin' => 'synthetic_council_presidency_designation']);
    }

    public function revoke(User $president, RoleAssignment $assignment, string $councilId, string $reason): void
    {
        RoleAssignment::assertPilotGrantEnvironment();
        $this->authorize($president, $councilId, $reason);
        if ($assignment->role_key !== 'bonus_beca_council_issuer' || $assignment->scope_type !== 'council'
            || $assignment->scope_id !== $councilId) {
            throw new AuthorizationException('La designación debe pertenecer al Consejo exacto.');
        }
        $assignment->transitionTo('revoked', (string) $president->getKey(), trim($reason));
    }

    public function assignBudget(User $president, User $treasury, string $councilId, string $budgetId, string $reason): RoleAssignment
    {
        RoleAssignment::assertPilotGrantEnvironment();
        $this->authorize($president, $councilId, $reason);
        $this->budget($councilId, $budgetId);
        $id = (string) $treasury->getKey();
        if (! $this->identity->isEligibleFinancialAccount($id)
            || ! MiembroOrganizacion::activos()->where('organizacion_id', $councilId)->where('usuario_id', $id)->exists()
            || ! RolOrganizacion::vigentes()->where('organizacion_id', $councilId)->where('usuario_id', $id)
                ->where('slug_rol', 'tesoreria')->exists()) {
            throw new AuthorizationException('Se requiere la Tesorería vigente del Consejo.');
        }

        return RoleAssignment::create(['user_id' => $id, 'role_key' => 'institutional_budget_committer',
            'scope_type' => 'institutional_budget', 'scope_id' => $budgetId, 'status' => 'active',
            'assigned_by' => (string) $president->getKey(), 'reason' => trim($reason),
            'origin' => 'synthetic_council_treasury_designation']);
    }

    public function revokeBudget(User $president, RoleAssignment $assignment, string $councilId, string $budgetId, string $reason): void
    {
        RoleAssignment::assertPilotGrantEnvironment();
        $this->authorize($president, $councilId, $reason);
        $this->budget($councilId, $budgetId);
        if ($assignment->role_key !== 'institutional_budget_committer' || $assignment->scope_type !== 'institutional_budget'
            || $assignment->scope_id !== $budgetId) {
            throw new AuthorizationException('La designación debe pertenecer al presupuesto exacto.');
        }
        $assignment->transitionTo('revoked', (string) $president->getKey(), trim($reason));
    }

    private function budget(string $councilId, string $budgetId): void
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $budgetId)
            || ! \App\Support\BecaFinanceContract::councilBudgetMatches($councilId, $budgetId)) {
            throw new AuthorizationException('Se requiere la relación Consejo-presupuesto configurada por E2.');
        }
    }

    private function authorize(User $president, string $councilId, string $reason): void
    {
        if (trim($reason) === '') throw new InvalidArgumentException('El motivo es obligatorio.');
        if (! $this->isCurrentPresident((string) $president->getKey(), $councilId)) {
            throw new AuthorizationException('Se requiere la Presidencia vigente del Consejo acreditado por E6.');
        }
    }

    /** Read-only accreditation, also used to invalidate new issuance after the grantor loses authority. */
    public function isCurrentPresident(string $id, string $councilId): bool
    {
        return preg_match('/\A[0-9a-f]{24}\z/', $id) === 1
            && preg_match('/\A[0-9a-f]{24}\z/', $councilId) === 1
            && $this->identity->isEligibleFinancialAccount($id)
            && Organizacion::whereKey($councilId)->where('tipo', 'consejo')->where('estado', 'activa')->exists()
            && MiembroOrganizacion::activos()->where('organizacion_id', $councilId)->where('usuario_id', $id)->exists()
            && RolOrganizacion::vigentes()->where('organizacion_id', $councilId)->where('usuario_id', $id)
                ->where('slug_rol', 'presidencia')->exists();
    }
}
