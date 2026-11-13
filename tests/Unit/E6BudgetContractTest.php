<?php
namespace Tests\Unit;

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization,InstitutionalBudgetApprovalAuthorization};
use App\Domains\Financial\Services\InstitutionalBudgetService;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Services\Directory\AcademicAudienceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class E6BudgetContractTest extends TestCase
{
    public function test_e2_supplies_preexisting_council_and_institution_and_revalidates_without_sql():void
    {
        $authority=new class implements InstitutionalBudgetAuthorization,InstitutionalBudgetApprovalAuthorization {
            public array $calls=[];public bool $allowed=true;
            public function allows(string $actorId,string $action,?string $budgetId=null):bool{throw new \RuntimeException('General boundary must not approve');}
            public function allowsApproval(string $actorId,string $councilId,string $institutionReference):bool{
                $this->calls[]=[$actorId,$councilId,$institutionReference];return $this->allowed;
            }
        };
        $service=new InstitutionalBudgetService($authority,new FinancialJobLock);
        $method=new \ReflectionMethod($service,'revalidate');
        $context=['council_id'=>'222222222222222222222222','institution_reference'=>'E6-COUNCIL-EXACT'];
        $method->invoke($service,'111111111111111111111111','APPROVE',null,$context);
        self::assertSame([['111111111111111111111111','222222222222222222222222','E6-COUNCIL-EXACT']],$authority->calls);
        $authority->allowed=false;
        $this->expectException(AuthorizationException::class);
        $method->invoke($service,'111111111111111111111111','APPROVE',null,$context);
    }
    public function test_no_council_fails_before_financial_locks_or_database():void
    {
        $authority=new class implements InstitutionalBudgetAuthorization,InstitutionalBudgetApprovalAuthorization {
            public function allows(string $a,string $b,?string $c=null):bool{return true;}
            public function allowsApproval(string $a,string $b,string $c):bool{throw new \RuntimeException('Missing context must not reach verifier');}
        };
        $service=new InstitutionalBudgetService($authority,new FinancialJobLock);
        $this->expectException(AuthorizationException::class);
        $service->approve('111111111111111111111111',['institution_reference'=>'untrusted'],'attempt');
    }
    public function test_community_consumer_uses_internal_authenticated_request_contract():void
    {
        $previous=Container::getInstance();$app=new Application;$app->instance('config',new Repository(['identity_directory'=>['community_academic_client'=>'e6-reviewed']]));
        Facade::clearResolvedInstances();Facade::setFacadeApplication($app);
        try {
            $fake=$this->createMock(AcademicAudienceService::class);$request=\Illuminate\Http\Request::create('/comunidad');$filters=['academic_program_id'=>'222222222222222222222222'];
            $fake->expects(self::once())->method('select')->with($request,'e6-reviewed',$filters)->willReturn(['items'=>[]]);
            $app->instance(AcademicAudienceService::class,$fake);
            self::assertSame(['items'=>[]],(new \App\Services\IdentidadComunidadApi)->seleccionarAudienciaAcademica($request,$filters));
        } finally {Facade::clearResolvedInstances();Container::setInstance($previous);Facade::setFacadeApplication($previous);}
    }
}
