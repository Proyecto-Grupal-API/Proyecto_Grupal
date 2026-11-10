<?php

namespace App\Services;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Contracts\ContextualBonusAuthorizationProvider;
use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\BecaFinanceContract;
use Illuminate\Http\Request;
use Throwable;

/** Only the trusted Council BECA flow. Never implements or replaces FinancialWebAuthorizer. */
class CouncilBecaWebAuthorization
{
    public function __construct(private readonly Request $request, private readonly RecentTwoFactor $factor,
        private readonly IdentityService $identity, private readonly EffectiveRoleAssignments $assignments,
        private readonly CouncilBecaDesignationService $designations,
        private readonly InstitutionalBudgetAuthorization $budgets,
        private readonly BonusAuthorizationProvider $issuers) {}

    /** Panel visibility only. Issuance rechecks the complete authority and recent factor. */
    public function canOpen(): bool
    {
        try {
            $user = $this->request->user('web');
            if (config('becas_finanzas.enabled') !== true || ! $user instanceof User
                || ! $this->factor->hasActiveSession($this->request)) {
                return false;
            }

            $mappings = config('becas_finanzas.convocatorias', []);
            if (! is_array($mappings)) {
                return false;
            }

            foreach ($mappings as $callId => $mapping) {
                $councilId = is_array($mapping) ? ($mapping['organization_id'] ?? null) : null;
                if (is_string($councilId) && preg_match('/\A[0-9a-f]{24}\z/', $councilId)
                    && BecaFinanceContract::budgetMapping(['convocatoria_id' => $callId, 'organizacion_id' => $councilId]) !== null
                    && $this->hasCurrentDesignation($user, 'bonus_beca_council_issuer', 'council', $councilId, $councilId)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** Council, budget and treasury are derived by E2 from the validated E6 award, not request input. */
    public function allows(string $actorId, string $councilId, string $budgetId, string $treasuryActor): bool
    {
        $allowed = false;
        $reason = 'not-authorized';
        try {
            $user = $this->request->user('web');
            $allowed = config('becas_finanzas.enabled') === true && $user instanceof User && (string) $user->getKey() === $actorId
                && preg_match('/\A[0-9a-f]{24}\z/', $actorId)
                && preg_match('/\A[0-9a-f]{24}\z/', $councilId)
                && preg_match('/\A[0-9a-f]{24}\z/', $treasuryActor)
                && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $budgetId)
                && $this->identity->isEligibleFinancialAccount($actorId)
                && $this->factor->isRecent($this->request)
                && BecaFinanceContract::councilBudgetMatches($councilId, $budgetId)
                && $this->hasCurrentDesignation($user, 'bonus_beca_council_issuer', 'council', $councilId, $councilId)
                && $this->treasuryIsCurrent($treasuryActor, $councilId, $budgetId)
                && $this->budgets->allows($treasuryActor, 'budget.commit', $budgetId)
                && $this->issuers instanceof ContextualBonusAuthorizationProvider
                && $this->issuers->canIssueInContext('USER', $actorId, BonusType::BECA, new BonusIssuerContext('council', $councilId));
            $reason = $allowed ? 'authorized' : $reason;
        } catch (Throwable) {
            $allowed = false;
            $reason = 'dependency-unavailable';
        }
        try {
            $this->audit($actorId, $councilId, $budgetId, $treasuryActor, $allowed, $reason);
        } catch (Throwable) {
            return false;
        }

        return $allowed;
    }

    protected function hasCurrentDesignation(User $user, string $role, string $type, string $id, string $councilId): bool
    {
        foreach ($this->assignments->queryForRoles($user, [$role])->where('scope_type', $type)->where('scope_id', $id)
            ->whereNull('revoked_at')->get() as $assignment) {
            if (is_string($assignment->assigned_by)
                && $this->designations->isCurrentPresident($assignment->assigned_by, $councilId)) {
                return true;
            }
        }

        return false;
    }

    protected function treasuryIsCurrent(string $actor, string $councilId, string $budgetId): bool
    {
        if (! $this->identity->isEligibleFinancialAccount($actor)) return false;
        $user = User::find($actor);

        return $user !== null && $this->hasCurrentDesignation($user, 'institutional_budget_committer', 'institutional_budget', $budgetId, $councilId);
    }

    protected function audit(string $actor, string $council, string $budget, string $treasury, bool $allowed, string $reason): void
    {
        SecurityEvent::log(['user_id' => $actor,
            'session_id' => $this->request->hasSession() ? $this->request->session()->get('cd_session_id') : null,
            'type' => 'council_beca_web_authorization', 'severity' => $allowed ? 'info' : 'warning',
            'metadata' => ['operation' => 'bonuses.beca.issue', 'council_id' => $council, 'budget_public_id' => $budget,
                'treasury_actor_id' => $treasury, 'authorized' => $allowed, 'reason' => $reason]]);
    }
}
