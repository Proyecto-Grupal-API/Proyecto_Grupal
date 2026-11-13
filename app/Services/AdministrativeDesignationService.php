<?php
namespace App\Services;

use App\Models\{OfficialRoleAssignment, RoleAssignment, SecurityEvent};
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;

/** Internal web contract: actor always comes from the current backend request. */
final class AdministrativeDesignationService
{
    public function __construct(private DesignationAuthorization $authority) {}

    /** Explicit activation only, no boot/seeder hook and no user assignments here. */
    public function prepareCatalog(Request $request): void
    {
        $plan=config('identity_designations.bootstrap',[]);
        $until=isset($plan['until']) ? \Carbon\CarbonImmutable::parse($plan['until']) : null;
        if ($request!==request() || config('identity_designations.enabled')!==true
            || !$request->user('web') || ($plan['actor_id']??null)!==(string)$request->user('web')->getKey()
            || !$until || $until->lte(now()) || $until->gt(now()->addDay()) || empty($plan['grants'])
            || !app(RecentTwoFactor::class)->isRecent($request)) throw new AuthorizationException();
        $event=SecurityEvent::log(['user_id'=>(string)$request->user('web')->getKey(),'type'=>'identity_designation_catalog_requested']);
        if (!$event->exists || !$event->wasRecentlyCreated) throw new AuthorizationException('Audit unavailable.');
        $roles=[...array_keys(\App\Models\Role::PILOT_ROLE_SCOPES),...array_keys(\App\Models\Role::ADMINISTRATIVE_ROLE_SCOPES),'bonus_beca_council_issuer'];
        foreach ($roles as $role) {
            \App\Models\Role::firstOrCreate(['name'=>$role],['display_name'=>$role]);
            foreach (\App\Models\RolePermission::approvedPermissionsForRole($role) as $permission) {
                [$name,$domain]=\App\Models\Permission::definitions()[$permission];
                \App\Models\Permission::firstOrCreate(['key'=>$permission],['display_name'=>$name,'domain'=>$domain,'active'=>true]);
                \App\Models\RolePermission::firstOrCreate(['role_key'=>$role,'permission_key'=>$permission]);
            }
        }
    }

    public function grant(Request $request,array $data): OfficialRoleAssignment
    {
        if ($request !== request() || array_diff(array_keys($data),['user_id','role_key','scope_type','scope_id','starts_at','ends_at','reason'])) throw new AuthorizationException();
        $actor=$request->user('web');
        $record=new OfficialRoleAssignment([...$data,'assigned_by'=>$actor?->getKey(),'status'=>'active','origin'=>'official_designation']);
        $record->setAttribute('_id',new \MongoDB\BSON\ObjectId);
        $record->setAttribute('grant_reason',trim((string)($data['reason']??'')));
        $this->authority->assertRecord($request,$record);
        if (RoleAssignment::where('user_id',$record->user_id)->where('role_key',$record->role_key)
            ->where('scope_type',$record->scope_type)->where('scope_id',$record->scope_id)->where('is_current',true)->exists()) throw new AuthorizationException('Revoke the current generation first.');
        $this->assertIndexes();
        $this->audit($request,$record,'grant_requested');
        if (!$record->save() || !$record->exists) throw new AuthorizationException('Designation was not persisted.'); // Revalidates authority and stores assigned_by/reason/time in the lifecycle itself.
        return $record;
    }

    public function revoke(Request $request,string $assignmentId,string $reason): void
    {
        if ($request!==request() || trim($reason)==='') throw new AuthorizationException();
        $record=OfficialRoleAssignment::findOrFail($assignmentId);
        $this->authority->assertRecord($request,$record);
        $this->audit($request,$record,'revoke_requested',$reason);
        $record->transitionTo('revoked',(string)$request->user('web')->getKey(),$reason);
    }

    private function assertIndexes(): void
    {
        $found=[];
        foreach (\Illuminate\Support\Facades\DB::connection('mongodb')->getCollection('role_assignments')->listIndexes() as $index) {
            $name=$index->getName();
            if ($name==='current_identity_unique' && $index->isUnique()
                && $index->getKey()===['user_id'=>1,'role_key'=>1,'scope_type'=>1,'scope_id'=>1]
                && ($index['partialFilterExpression']['is_current']??null)===true) $found[]=$name;
            if ($name==='identity_generation_unique' && $index->isUnique()
                && $index->getKey()===['user_id'=>1,'role_key'=>1,'scope_type'=>1,'scope_id'=>1,'generation'=>1]) $found[]=$name;
        }
        if (count(array_unique($found))!==2) throw new AuthorizationException('Authorization integrity indexes unavailable.');
    }

    private function audit(Request $request,RoleAssignment $record,string $operation,?string $reason=null): void
    {
        $event=SecurityEvent::log(['user_id'=>(string)$request->user('web')->getKey(),'type'=>'identity_designation',
            'metadata'=>['operation'=>$operation,'assignment_id'=>(string)$record->getKey(),'beneficiary'=>$record->user_id,'role'=>$record->role_key,
                'scope_type'=>$record->scope_type,'scope_id'=>$record->scope_id,'reason'=>$reason??$record->reason]]);
        if (!$event->exists || !$event->wasRecentlyCreated) throw new AuthorizationException('Designation audit unavailable.');
    }
}
