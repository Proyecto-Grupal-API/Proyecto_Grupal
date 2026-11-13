<?php
namespace Tests\Unit;

use App\Exceptions\DirectoryFailure;
use App\Models\{OfficialRoleAssignment,User};
use App\Services\{BudgetAdministrationAuthorization,DesignationAuthorization,RecentTwoFactor,InstitutionalAuthorizationService};
use App\Services\Directory\{AcademicAudienceService,DirectoryQuota,DirectoryQuotaStore,IdentityDirectoryService};
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class E6DefectRegressionTest extends TestCase
{
    private Container $previous;
    private Request $request;
    protected function setUp():void
    {
        $this->previous=Container::getInstance();$app=new Application;
        $app->instance('config',new Repository(['identity_directory'=>['academic_clients'=>[
            'e6'=>['active'=>true,'academic_program_ids'=>['222222222222222222222222'],'daily_reference_limit'=>50]],
            'enrollment_integrity_verified'=>true]]));
        $this->request=Request::create('/internal');$app->instance('request',$this->request);
        Facade::clearResolvedInstances();Facade::setFacadeApplication($app);
    }
    protected function tearDown():void
    {
        Facade::clearResolvedInstances();Container::setInstance($this->previous);Facade::setFacadeApplication($this->previous);
    }
    public function test_reported_microsecond_bootstrap_via_public_authorization():void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-11T12:00:00Z');
        try {
            $actor=new User;$actor->setRawAttributes(['_id'=>'111111111111111111111111']);$this->request->setUserResolver(fn()=>$actor);
            $end='2026-11-11T23:01:02.123456Z';
            $grant=['user_id'=>'333333333333333333333333','role_key'=>'financial_refund_designator','scope_type'=>'wallet',
                'scope_id'=>'12345678-1234-1234-1234-123456789abc','ends_at'=>$end];
            $record=new OfficialRoleAssignment([...$grant,'starts_at'=>'2026-11-11T13:00:00Z',
                'assigned_by'=>(string)$actor->getKey(),'origin'=>'official_designation','reason'=>'Synthetic precision regression']);
            config(['identity_designations.enabled'=>true,'identity_designations.bootstrap'=>[
                'actor_id'=>(string)$actor->getKey(),'until'=>'2026-11-11T13:00:00Z','grants'=>[$grant]]]);
            $factor=$this->createMock(RecentTwoFactor::class);$factor->method('isRecent')->willReturn(true);
            $identity=$this->createMock(\App\Services\IdentityService::class);$identity->method('isEligibleFinancialAccount')->willReturn(true);
            $authority=new DesignationAuthorization($factor,new \App\Services\EffectiveRoleAssignments,
                $this->createMock(\App\Services\CouncilBecaDesignationService::class),$identity);
            $authority->assertRecord($this->request,$record);
            self::assertSame('123000',$record->ends_at->format('u'));
        } finally {\Illuminate\Support\Carbon::setTestNow();}
    }
    public static function instants():iterable
    {
        yield 'exact reported defect'=>['2026-11-11T23:01:02.123456Z','2026-11-11T23:01:02.123456Z',true];
        yield 'same instant offset'=>['2026-11-11T17:01:02.123456-06:00','2026-11-11T23:01:02.123456Z',true];
        yield 'seconds'=>['2026-11-11T23:01:02Z','2026-11-11T23:01:02.000000Z',true];
        yield 'truncation not round'=>['2026-11-11T23:01:02.123999Z','2026-11-11T23:01:02.123000Z',true];
        yield 'one millisecond extension'=>['2026-11-11T23:01:02.123456Z','2026-11-11T23:01:02.124000Z',false];
        yield 'different expiry'=>['2026-11-11T23:01:02.123456Z','2026-11-11T23:01:03.123000Z',false];
        yield 'relative text not deployment plan'=>['tomorrow','2026-11-11T23:01:02Z',false];
    }
    #[DataProvider('instants')]
    public function test_bootstrap_matches_bson_precision_without_extending_grant(string $plan,string $input,bool $expected):void
    {
        $r=new OfficialRoleAssignment(['ends_at'=>$input]);
        $match=['user_id'=>'111111111111111111111111','role_key'=>'financial_refund_designator','scope_type'=>'wallet',
            'scope_id'=>'12345678-1234-1234-1234-123456789abc','ends_at'=>$r->ends_at->toISOString()];
        $grant=$match;$grant['ends_at']=$plan;
        $authority=(new \ReflectionClass(DesignationAuthorization::class))->newInstanceWithoutConstructor();
        self::assertSame($expected,(new \ReflectionMethod($authority,'matchesBootstrapGrant'))->invoke($authority,$match,[$grant]));
        self::assertFalse((new \ReflectionMethod($authority,'matchesBootstrapGrant'))->invoke($authority,$match,[[...$grant,'scope_id'=>'87654321-4321-4321-4321-cba987654321']]));
    }
    public static function decisions():iterable
    {
        yield 'allow'=>[true,false,false,true];yield 'deny'=>[false,false,false,false];
        yield 'verifier fails'=>[false,true,false,false];yield 'audit fails on allow'=>[true,false,true,false];
        yield 'both fail'=>[false,true,true,false];yield 'audit fails on deny'=>[false,false,true,false];
    }
    #[DataProvider('decisions')]
    public function test_budget_always_attempts_safe_audit(bool $decision,bool $verifierFails,bool $auditFails,bool $expected):void
    {
        $service=new class($decision,$verifierFails,$auditFails) extends BudgetAdministrationAuthorization {
            public array $audits=[];
            public function __construct(private bool $decision,private bool $verifierFails,private bool $auditFails){}
            protected function effective(string $actor,string $action,string $scope,?string $institution):bool
            {if($this->verifierFails)throw new \RuntimeException('sensitive dependency details');return $this->decision;}
            protected function audit(string $actor,string $action,string $scope,bool $allowed):void
            {$this->audits[]=[$actor,$action,$scope,$allowed];if($this->auditFails)throw new \RuntimeException('audit unavailable');}
        };
        self::assertSame($expected,$service->allows('111111111111111111111111','budget.approve','222222222222222222222222','institution'));
        self::assertSame([['111111111111111111111111','budget.approve','222222222222222222222222',$verifierFails?false:$decision]],$service->audits);
    }
    public static function failures():iterable
    {
        yield 'session connection timeout'=>['session',new \MongoDB\Driver\Exception\ConnectionTimeoutException('private endpoint'),true];
        yield 'session programming error'=>['session',new \LogicException('programming defect'),false];
        yield 'session unclassified runtime'=>['session',new \RuntimeException('unclassified'),false];
        yield 'session type error'=>['session',new \TypeError('programming defect'),false];
    }
    private function audience(?\Throwable $fault=null,string $where='session',bool $active=true,bool $allowed=true):AcademicAudienceService
    {
        $factor=$this->createMock(RecentTwoFactor::class);
        if($where==='session' && $fault)$factor->method('hasActiveSession')->willThrowException($fault);
        else $factor->method('hasActiveSession')->willReturn($active);
        // The real final authority rejects the non-persisted subject without a database query.
        $authority=new InstitutionalAuthorizationService(new \App\Services\EffectiveRoleAssignments);
        return new AcademicAudienceService($factor,$authority,new DirectoryQuota(new DirectoryQuotaStore),new IdentityDirectoryService);
    }
    #[DataProvider('failures')]
    public function test_directory_dependency_boundary(string $where,\Throwable $fault,bool $controlled):void
    {
        $u=new User;$u->setRawAttributes(['_id'=>'111111111111111111111111']);$this->request->setUserResolver(fn()=>$u);
        try {$this->audience($fault,$where)->select($this->request,'e6',['academic_program_id'=>'222222222222222222222222']);self::fail('Expected exception');}
        catch(\Throwable $e){
            if($controlled){self::assertInstanceOf(DirectoryFailure::class,$e);self::assertSame(503,$e->status);self::assertStringNotContainsString('private endpoint',$e->getMessage());}
            else self::assertSame($fault,$e);
        }
    }
    public function test_directory_no_session_remains_forbidden():void
    {
        try {$this->audience(active:false)->select($this->request,'e6',['academic_program_id'=>'222222222222222222222222']);self::fail();}
        catch(DirectoryFailure $e){self::assertSame(403,$e->status);}
    }
    public function test_directory_no_authority_remains_forbidden():void
    {
        $this->request->setUserResolver(fn()=>new User);
        try {$this->audience(allowed:false)->select($this->request,'e6',['academic_program_id'=>'222222222222222222222222']);self::fail();}
        catch(DirectoryFailure $e){self::assertSame(403,$e->status);}
    }
    public function test_invalid_filter_stays_validation_error():void
    {
        try {$this->audience()->select($this->request,'e6',['academic_program_id'=>'invalid']);self::fail();}
        catch(DirectoryFailure $e){self::assertSame(422,$e->status);}
    }
    public function test_revoked_consumer_stays_forbidden():void
    {
        config(['identity_directory.academic_clients.e6.active'=>false]);
        try {$this->audience()->select($this->request,'e6',['academic_program_id'=>'222222222222222222222222']);self::fail();}
        catch(DirectoryFailure $e){self::assertSame(403,$e->status);}
    }
}
