<?php
namespace App\Services;

use App\Models\{User,Organizacion,SecurityEvent};
use Throwable;

/** Human-session administration, independently of the treasury budget.commit capability. */
class BudgetAdministrationAuthorization
{
    public const ROLES=['budget.approve'=>'institutional_budget_approver','budget.adjust'=>'institutional_budget_adjuster',
        'budget.suspend'=>'institutional_budget_suspender','budget.resume'=>'institutional_budget_resumer'];
    public function __construct(protected RecentTwoFactor $factor,protected DesignationAuthorization $designations) {}

    public function allows(string $actor,string $action,?string $scope,?string $institution=null): bool
    {
        if (!isset(self::ROLES[$action]) || !preg_match('/\A[0-9a-f]{24}\z/',$actor) || !is_string($scope)) return false;
        $type=$action==='budget.approve'?'council':'institutional_budget';
        if (!DesignationAuthorization::tuple(self::ROLES[$action],$type,$scope)) return false;
        try {
            $allowed=$this->effective($actor,$action,$scope,$institution);
        } catch(Throwable) {
            // A dependency failure is a denial; still attempt the independent audit sink.
            $allowed=false;
        }
        try {
            $this->audit($actor,$action,$scope,$allowed);
        } catch(Throwable) { return false; }
        return $allowed;
    }
    protected function effective(string $actor,string $action,string $scope,?string $institution): bool
    {
        $request=request();$human=$request->user('web');
        if (!$human instanceof User || (string)$human->getKey()!==$actor || !$this->factor->isRecent($request)) return false;
        if ($action==='budget.approve') {
            $map=config('financial_budget_administration.institutions',[]);
            if (!is_array($map) || !is_string($institution) || ($map[$scope]??null)!==$institution
                || count(array_keys($map,$institution,true))!==1
                || !Organizacion::whereKey($scope)->where('tipo','consejo')->where('estado','activa')->exists()) return false;
        }
        return $this->designations->has($human,self::ROLES[$action],$action,
            $action==='budget.approve'?'council':'institutional_budget',$scope);
    }
    protected function audit(string $actor,string $action,string $scope,bool $allowed): void
    {
        $event=SecurityEvent::log(['user_id'=>$actor,'type'=>'budget_administration_authorization',
            'metadata'=>['operation'=>$action,'scope_id'=>$scope,'authorized'=>$allowed]]);
        if (!$event->exists || !$event->wasRecentlyCreated) throw new \RuntimeException('Audit unavailable.');
    }
}
