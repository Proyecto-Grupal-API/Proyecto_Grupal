<?php
namespace App\Services;

use App\Models\{User, Role, RoleAssignment, Permission, RolePermission};
use App\Support\BecaFinanceContract;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Carbon\CarbonImmutable;

/** Privilege-minimum proposal. No admin/OAuth fallback. */
class DesignationAuthorization
{
    public function __construct(protected RecentTwoFactor $factor, protected EffectiveRoleAssignments $effective,
        protected CouncilBecaDesignationService $councils, protected IdentityService $identity) {}

    public function assertRecord(Request $request, RoleAssignment $record): void
    {
        $actor = $request->user('web');
        if (config('identity_designations.enabled') !== true || ! $actor instanceof User
            || ! $this->factor->isRecent($request) || ! $this->identity->isEligibleFinancialAccount((string)$actor->getKey())
            || (! $record->exists && (string)$record->user_id===(string)$actor->getKey())
            || (! $record->exists && ! $this->identity->isEligibleFinancialAccount((string)$record->user_id))
            || (! $record->exists && ((string)$record->assigned_by!==(string)$actor->getKey() || $record->origin!=='official_designation'))
            || (! $record->exists && (! is_string($record->reason) || trim($record->reason) === '' || mb_strlen($record->reason)>1000
                || ! $record->starts_at || ! $record->ends_at || $record->starts_at->gte($record->ends_at)
                || $record->ends_at->lte(now()) || $record->ends_at->gt(now()->addYear())))) {
            throw new AuthorizationException('Invalid administrative designation.');
        }
        $role=$record->role_key; $type=$record->scope_type; $id=$record->scope_id;
        if (!self::tuple($role,$type,$id)) throw new AuthorizationException('Incompatible designation scope.');
        $actorId=(string)$actor->getKey();
        if (in_array($role,['financial_refund_designator','service_damage_designator','budget_administration_designator'],true)) {
            // A new delegation cannot be created by another delegate. Explicit exact deployment plan only.
            $plan=config('identity_designations.bootstrap',[]);
            if ($record->exists && ($plan['actor_id']??null)===$actorId && (string)$record->assigned_by===$actorId) return;
            $until=isset($plan['until']) ? CarbonImmutable::parse($plan['until']) : null;
            $match=['user_id'=>(string)$record->user_id,'role_key'=>$role,'scope_type'=>$type,'scope_id'=>$id,
                'ends_at'=>$record->ends_at->toISOString()];
            if (($plan['actor_id']??null)!==$actorId || !$until || $until->lte(now()) || $until->gt(now()->addDay())
                || !$this->matchesBootstrapGrant($match,$plan['grants']??[])) throw new AuthorizationException('Delegation activation not approved.');
            return;
        }
        if ($role==='bonus_beca_council_issuer') {
            if (!$this->councils->isCurrentPresident($actorId,$id)) throw new AuthorizationException();
            return;
        }
        if ($role==='institutional_budget_committer') {
            $council=$this->councilForBudget($id);
            if (!$council || !$this->councils->isCurrentPresident($actorId,$council)
                || (!$record->exists && (!\App\Models\MiembroOrganizacion::activos()->where('organizacion_id',$council)->where('usuario_id',$record->user_id)->exists()
                    || !\App\Models\RolOrganizacion::vigentes()->where('organizacion_id',$council)->where('usuario_id',$record->user_id)->where('slug_rol','tesoreria')->exists()))) throw new AuthorizationException();
            return;
        }
        [$delegate,$permission,$delegateType,$delegateId]=match($role){
            'financial_refund_reviewer','financial_refund_executor'=>['financial_refund_designator','identity.refunds.designate','wallet',$id],
            'operador_servicios'=>['service_damage_designator','identity.services.designate',null,null],
            'institutional_budget_approver'=>['budget_administration_designator','identity.budgets.designate','council',$id],
            default=>['budget_administration_designator','identity.budgets.designate','council',$this->councilForBudget($id)],
        };
        if (($delegateType!==null && !$delegateId) || !$this->has($actor,$delegate,$permission,$delegateType,$delegateId)) throw new AuthorizationException();
    }

    /** BSON stores milliseconds: truncate both ends, never round or extend a grant. */
    protected function matchesBootstrapGrant(array $match, mixed $grants): bool
    {
        if (!is_array($grants)) return false;
        foreach ($grants as $grant) {
            if (!is_array($grant) || array_diff_key($grant,$match) || array_diff_key($match,$grant)
                || !is_string($grant['ends_at'])
                || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $grant['ends_at'])) continue;
            try {
                $grant['ends_at']=CarbonImmutable::parse($grant['ends_at'])->utc()->floorMillisecond()->toISOString();
            } catch (\Carbon\Exceptions\InvalidFormatException) { continue; }
            if ($grant === $match) return true;
        }
        return false;
    }

    public static function tuple(mixed $role,mixed $type,mixed $id): bool
    {
        $scopes=[...Role::PILOT_ROLE_SCOPES,...Role::ADMINISTRATIVE_ROLE_SCOPES,'bonus_beca_council_issuer'=>'council'];
        if (!is_string($role) || !array_key_exists($role,$scopes) || $type!==$scopes[$role]) return false;
        return $type===null ? $id===null : (is_string($id) && preg_match($type==='council' ? '/\A[0-9a-f]{24}\z/' : '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/',$id)===1);
    }

    public function has(User $actor,string $role,string $permission,?string $type,?string $id): bool
    {
        return Role::where('name',$role)->exists()
            && Permission::where('key',$permission)->where('active',true)->exists()
            && in_array($permission,RolePermission::approvedPermissionsForRole($role),true)
            && RolePermission::where('role_key',$role)->where('permission_key',$permission)->exists()
            && $this->effective->queryForRoles($actor,[$role])->where('scope_type',$type)->where('scope_id',$id)->whereNull('revoked_at')->exists();
    }

    public function councilForBudget(string $budget): ?string
    {
        $ids=[];
        foreach(config('becas_finanzas.convocatorias',[]) as $map) {
            if (is_array($map) && ($map['budget_id']??null)===$budget && is_string($map['organization_id']??null)
                && BecaFinanceContract::councilBudgetMatches($map['organization_id'],$budget)) $ids[]=$map['organization_id'];
        }
        $ids=array_unique($ids);
        return count($ids)===1 ? array_values($ids)[0] : null;
    }
}
