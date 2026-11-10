<?php

namespace Tests\Unit;

use App\Services\EffectiveRoleAssignments;
use App\Services\IdentityService;
use App\Services\InstitutionalBudgetAuthorizationService;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Executes the real E1 query construction/lifecycle against an in-memory query double. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstitutionalAuthorizationLifecycleTest extends TestCase
{
    public static function states(): iterable
    {
        foreach (['active'=>true,'suspended'=>false,'revoked'=>false,'future'=>false,'expired'=>false,
            'not-current'=>false,'wrong-scope'=>false,'global'=>false,'missing'=>false,'start-boundary'=>true] as $state=>$expected) {
            yield $state => [$state,$expected];
        }
    }

    #[DataProvider('states')]
    public function test_budget_query_keeps_exact_scope_and_existing_effective_lifecycle(string $state, bool $expected): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00 UTC');
        $id='111111111111111111111111'; $budget='12345678-1234-1234-1234-123456789abc';
        $user=Mockery::mock('alias:App\\Models\\User');
        $user->shouldReceive('find')->with($id)->andReturn($user);
        $user->shouldReceive('getKey')->andReturn($id);
        $role=Mockery::mock('alias:App\\Models\\Role');
        $role->shouldReceive('where')->with('name',InstitutionalBudgetAuthorizationService::ROLE)
            ->andReturn(new AuthorizationRows([['name'=>InstitutionalBudgetAuthorizationService::ROLE]]));
        $permission=Mockery::mock('alias:App\\Models\\Permission');
        $permission->shouldReceive('where')->with('key','budget.commit')
            ->andReturn(new AuthorizationRows([['key'=>'budget.commit','domain'=>'financial','active'=>true]]));
        $mapping=Mockery::mock('alias:App\\Models\\RolePermission');
        $mapping->shouldReceive('where')->with('role_key',InstitutionalBudgetAuthorizationService::ROLE)
            ->andReturn(new AuthorizationRows([['role_key'=>InstitutionalBudgetAuthorizationService::ROLE,'permission_key'=>'budget.commit']]));
        $row=['user_id'=>$id,'role_key'=>InstitutionalBudgetAuthorizationService::ROLE,'status'=>'active','is_current'=>true,
            'starts_at'=>null,'ends_at'=>null,'revoked_at'=>null,'scope_type'=>'institutional_budget','scope_id'=>$budget];
        if($state==='suspended')$row['status']='suspended';
        if($state==='revoked')$row['revoked_at']=now();
        if($state==='future')$row['starts_at']=now()->addSecond();
        if($state==='expired')$row['ends_at']=now();
        if($state==='not-current')$row['is_current']=false;
        if($state==='wrong-scope')$row['scope_id']='87654321-1234-1234-1234-123456789abc';
        if($state==='global'){$row['scope_type']=null;$row['scope_id']=null;}
        if($state==='start-boundary')$row['starts_at']=now();
        $assignments=Mockery::mock('alias:App\\Models\\RoleAssignment');
        $assignments->shouldReceive('where')->with('user_id',$id)->andReturnUsing(fn()=>
            (new AuthorizationRows($state==='missing'?[]:[$row]))->where('user_id',$id));
        $identity=Mockery::mock(IdentityService::class);
        $identity->shouldReceive('isEligibleFinancialAccount')->with($id)->once()->andReturn(true);
        $service=new class(new EffectiveRoleAssignments,$identity) extends InstitutionalBudgetAuthorizationService {
            protected function audit(string $actorId,string $budgetId,bool $allowed,string $reason):void {}
        };
        self::assertSame($expected,$service->allows($id,'budget.commit',$budget));
        Mockery::close(); Carbon::setTestNow();
    }
}

final class AuthorizationRows
{
    private array $clauses=[];
    public function __construct(private array $rows) {}
    public function where($key,$operator=null,$value=null):self
    {
        if($key instanceof \Closure){$nested=new self([]);$key($nested);$this->clauses[]=['and',fn($r)=>$nested->matches($r)];return $this;}
        if(func_num_args()===2){$value=$operator;$operator='=';}
        $this->clauses[]=['and',fn($r)=>match($operator){'='=>($r[$key]??null)===$value,'<='=>($r[$key]??null)<=$value,'>'=>($r[$key]??null)>$value}];return $this;
    }
    public function orWhere($key,$operator,$value):self {$this->where($key,$operator,$value);$this->clauses[array_key_last($this->clauses)][0]='or';return $this;}
    public function whereNull($key):self{return $this->where($key,null);}
    public function whereIn($key,$values):self {$this->clauses[]=['and',fn($r)=>in_array($r[$key]??null,$values,true)];return $this;}
    public function whereRaw($filter):self {foreach(array_keys($filter) as $key)$this->clauses[]=['and',fn($r)=>array_key_exists($key,$r)];return $this;}
    private function matches(array $row):bool {$result=true;foreach($this->clauses as [$op,$predicate]){$next=$predicate($row);$result=$op==='or'?($result||$next):($result&&$next);}return $result;}
    public function exists():bool {foreach($this->rows as $row)if($this->matches($row))return true;return false;}
}
