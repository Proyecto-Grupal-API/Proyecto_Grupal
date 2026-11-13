<?php
namespace Tests\Unit;

use App\Models\{Role,RoleAssignment,OfficialRoleAssignment,RolePermission,Permission,User};
use App\Services\{DesignationAuthorization,RecentTwoFactor,EffectiveRoleAssignments,CouncilBecaDesignationService,IdentityService,BudgetAdministrationAuthorization};
use App\Services\Directory\{AcademicAudienceInput,DirectoryPolicy};
use App\Exceptions\DirectoryFailure;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class E6ClosureTest extends TestCase
{
    private Container $previous;
    private Request $request;
    private User $actor;
    protected function setUp(): void
    {
        $this->previous=Container::getInstance();$app=new Application;
        $app->detectEnvironment(fn()=>'local');$app->instance('config',new Repository(['identity_designations'=>['enabled'=>true]]));
        $this->actor=new User;$this->actor->setRawAttributes(['_id'=>'111111111111111111111111']);
        $this->request=Request::create('/internal','POST');$this->request->setUserResolver(fn()=>$this->actor);$app->instance('request',$this->request);
        Facade::clearResolvedInstances();Facade::setFacadeApplication($app);CarbonImmutable::setTestNow('2026-11-11T12:00:00Z');
    }
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();Facade::clearResolvedInstances();Container::setInstance($this->previous);Facade::setFacadeApplication($this->previous);
    }
    private function authority(bool $session=true,bool $president=true,bool $delegated=true): DesignationAuthorization
    {
        $factor=$this->createMock(RecentTwoFactor::class);$factor->method('isRecent')->willReturn($session);
        $councils=$this->createMock(CouncilBecaDesignationService::class);$councils->method('isCurrentPresident')->willReturn($president);
        $identity=$this->createMock(IdentityService::class);$identity->method('isEligibleFinancialAccount')->willReturn(true);
        return new class($factor,new EffectiveRoleAssignments,$councils,$identity,$delegated) extends DesignationAuthorization {
            public function __construct($a,$b,$c,$d,private bool $allowed){parent::__construct($a,$b,$c,$d);}
            public function has(User $actor,string $role,string $permission,?string $type,?string $id):bool{return $this->allowed;}
        };
    }
    private function record(string $role='bonus_beca_council_issuer',?string $scope='council',?string $id='222222222222222222222222'): OfficialRoleAssignment
    {
        return new OfficialRoleAssignment(['user_id'=>'333333333333333333333333','role_key'=>$role,'scope_type'=>$scope,'scope_id'=>$id,
            'assigned_by'=>'111111111111111111111111','reason'=>'Propuesta acotada','origin'=>'official_designation','starts_at'=>'2026-11-12T00:00:00Z','ends_at'=>'2026-11-13T00:00:00Z']);
    }
    public function test_valid_future_council_designation_uses_real_guard_path_outside_testing():void
    {
        $this->authority()->assertRecord($this->request,$this->record());self::assertTrue(app()->environment('local'));
    }
    public function test_raw_pilot_writer_still_rejects_local_environment():void
    {
        $this->expectException(AuthorizationException::class);RoleAssignment::assertPilotGrantEnvironment();
    }
    public static function invalidTuples():iterable
    {
        yield ['bonus_beca_council_issuer',null,null];yield ['bonus_beca_issuer',null,null];
        yield ['financial_refund_reviewer','business','12345678-1234-1234-1234-123456789abc'];
        yield ['financial_refund_executor','wallet','222222222222222222222222'];
        yield ['operador_servicios','wallet','12345678-1234-1234-1234-123456789abc'];yield ['admin',null,null];
        yield ['institutional_budget_approver','institutional_budget','12345678-1234-1234-1234-123456789abc'];
    }
    #[DataProvider('invalidTuples')]
    public function test_scope_and_global_escalation_are_rejected($role,$scope,$id):void
    {self::assertFalse(DesignationAuthorization::tuple($role,$scope,$id));}
    public function test_official_writer_keeps_original_collection():void
    { self::assertSame('role_assignments',$this->record()->getTable()); }
    public function test_authorized_retirement_does_not_require_historical_validity_dates():void
    {
        $r=$this->record();$r->exists=true;$r->starts_at=null;$r->ends_at=null;$r->reason=null;
        $this->authority()->assertRecord($this->request,$r);self::assertTrue(true);
    }
    public function test_self_designation_is_rejected():void
    {
        $r=$this->record();$r->user_id='111111111111111111111111';
        $this->expectException(AuthorizationException::class);$this->authority()->assertRecord($this->request,$r);
    }
    public function test_exact_bootstrap_plan_is_required_and_can_authorize_one_tuple():void
    {
        $r=$this->record('financial_refund_designator','wallet','12345678-1234-1234-1234-123456789abc');
        config(['identity_designations.bootstrap'=>['actor_id'=>'111111111111111111111111','until'=>now()->addHour()->toISOString(),
            'grants'=>[['user_id'=>$r->user_id,'role_key'=>$r->role_key,'scope_type'=>$r->scope_type,'scope_id'=>$r->scope_id,'ends_at'=>$r->ends_at->toISOString()]]]]);
        $this->authority()->assertRecord($this->request,$r);self::assertTrue(true);
    }
    public function test_official_transition_cannot_renew_or_expand_an_existing_grant():void
    {
        $this->expectException(AuthorizationException::class);$this->record()->transitionTo('active','111111111111111111111111','renew');
    }

    public function test_no_president_no_designation():void
    {$this->expectException(AuthorizationException::class);$this->authority(president:false)->assertRecord($this->request,$this->record());}
    public function test_no_recent_totp_no_designation():void
    {$this->expectException(AuthorizationException::class);$this->authority(session:false)->assertRecord($this->request,$this->record());}
    public function test_wrong_assigned_by_cannot_forge_audit():void
    {$r=$this->record();$r->assigned_by='444444444444444444444444';$this->expectException(AuthorizationException::class);$this->authority()->assertRecord($this->request,$r);}
    public function test_expired_new_designation_rejected():void
    {$r=$this->record();$r->starts_at=now()->subDays(2);$r->ends_at=now()->subDay();$this->expectException(AuthorizationException::class);$this->authority()->assertRecord($this->request,$r);}
    public function test_missing_delegate_is_denied_without_admin_fallback():void
    {$this->expectException(AuthorizationException::class);$this->authority(delegated:false)->assertRecord($this->request,$this->record('financial_refund_reviewer','wallet','12345678-1234-1234-1234-123456789abc'));}
    public function test_delegation_activation_is_closed_by_default():void
    {$this->expectException(AuthorizationException::class);$this->authority()->assertRecord($this->request,$this->record('financial_refund_designator','wallet','12345678-1234-1234-1234-123456789abc'));}
    public function test_review_and_execution_and_budget_capabilities_stay_separate():void
    {
        self::assertSame(['refunds.review'],RolePermission::approvedPermissionsForRole('financial_refund_reviewer'));
        self::assertSame(['refunds.execute'],RolePermission::approvedPermissionsForRole('financial_refund_executor'));
        foreach(BudgetAdministrationAuthorization::ROLES as $permission=>$role) self::assertSame([$permission],RolePermission::approvedPermissionsForRole($role));
        self::assertSame(['budget.commit'],RolePermission::approvedPermissionsForRole('institutional_budget_committer'));
    }
    public function test_administration_service_denies_global_or_wrong_scope_before_dependencies():void
    {
        $a=(new \ReflectionClass(BudgetAdministrationAuthorization::class))->newInstanceWithoutConstructor();
        self::assertFalse($a->allows('111111111111111111111111','budget.approve',null));
        self::assertFalse($a->allows('111111111111111111111111','budget.approve','12345678-1234-1234-1234-123456789abc'));
        self::assertFalse($a->allows('111111111111111111111111','budget.commit','12345678-1234-1234-1234-123456789abc'));
    }
    public function test_academic_filters_do_not_invent_groups_or_allow_global_search():void
    {
        $this->expectException(DirectoryFailure::class);AcademicAudienceInput::parse(['academic_program_id'=>'222222222222222222222222','group'=>'invented']);
    }
    public function test_academic_page_limits_and_context():void
    {
        $id='222222222222222222222222';$input=AcademicAudienceInput::parse(['academic_program_id'=>$id,'semester'=>2,'limit'=>20]);
        self::assertSame(20,$input['limit']);self::assertSame(2,$input['semester']);
        self::assertSame(100,AcademicAudienceInput::authorize('e6',$id,['e6'=>['active'=>true,'academic_program_ids'=>[$id],'daily_reference_limit'=>100]])['daily_reference_limit']);
    }
    public function test_audience_revoked_consumer_denied():void
    {$this->expectException(DirectoryFailure::class);AcademicAudienceInput::authorize('e6','222222222222222222222222',['e6'=>['active'=>false,'academic_program_ids'=>['222222222222222222222222'],'daily_reference_limit'=>100]]);}
    public function test_directory_exact_context_grant_rejects_other_organization():void
    {
        $input=['operation'=>'batch','purpose'=>'historical_reference','context'=>['type'=>'organization','id'=>'other'],'projection'=>['identity'],'include_enrollment'=>false];
        $clients=['e6'=>['daily_reference_limit'=>100,'batch'=>['purposes'=>['historical_reference'=>['projections'=>['identity'],'context_types'=>['organization'],'context_ids'=>['allowed'],'enrollment'=>false]]]]];
        $this->expectException(DirectoryFailure::class);DirectoryPolicy::authorize('e6',[DirectoryPolicy::READ],$input,$clients);
    }
}
