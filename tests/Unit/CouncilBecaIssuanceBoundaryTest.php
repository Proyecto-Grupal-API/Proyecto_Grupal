<?php

namespace Tests\Unit;

use App\Domains\Financial\Adapters\PendingFinancialWebAuthorizer;
use App\Domains\Financial\Contracts\{BonusAuthorizationProvider,InstitutionalBudgetAuthorization};
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Services\{BonusAdministrationService,BonusService,InstitutionalBecaIssuanceService,InstitutionalBudgetService};
use App\Domains\Financial\Support\FinancialJobLock;
use App\Services\CouncilBecaWebAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;

/** Checks actual E2 entry points fail before locks/drivers and cannot fall back to generic permissions. */
final class CouncilBecaIssuanceBoundaryTest extends TestCase
{
    private Container $previous;
    private const ACTOR='111111111111111111111111', COUNCIL='222222222222222222222222', TREASURY='333333333333333333333333';
    private const BUDGET='12345678-1234-1234-1234-123456789abc';
    protected function setUp():void
    {
        $this->previous=Container::getInstance();$c=new Container;Container::setInstance($c);
        $c->instance('config',new \Illuminate\Config\Repository(['becas_finanzas'=>['convocatorias'=>[['organization_id'=>self::COUNCIL,'budget_id'=>self::BUDGET]]]]));
        $factory=new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'));
        $c->instance(\Illuminate\Contracts\Validation\Factory::class,$factory);
    }
    protected function tearDown():void{Mockery::close();Container::setInstance($this->previous);}
    private function admin(CouncilBecaWebAuthorization $special):BonusAdministrationService
    {
        $bonus=Mockery::mock(BonusService::class);$bonus->shouldNotReceive('issue');
        $issuers=Mockery::mock(BonusAuthorizationProvider::class);$issuers->shouldNotReceive('canIssueBonus');
        $locks=Mockery::mock(FinancialJobLock::class);$locks->shouldNotReceive('acquire');
        return new BonusAdministrationService($bonus,new PendingFinancialWebAuthorizer,$issuers,$locks,$special);
    }
    private function payload():array
    {
        return ['beneficiary_id'=>'444444444444444444444444','type'=>'BECA','amount_cents'=>100,
            'valid_from'=>'2026-11-10T00:00:00Z','expires_at'=>'2027-11-10T00:00:00Z',
            'combinable'=>false,'allows_partial_use'=>true,'reason'=>'Synthetic authorization boundary'];
    }
    public function test_ordinary_bonus_issue_still_uses_the_pending_generic_authorizer():void
    {
        $special=Mockery::mock(CouncilBecaWebAuthorization::class);$special->shouldNotReceive('allows');
        $this->expectException(AuthorizationException::class);$this->admin($special)->issue(self::ACTOR,[],'synthetic');
    }
    public function test_contextual_admin_issue_calls_special_authority_without_generic_fallback():void
    {
        $special=Mockery::mock(CouncilBecaWebAuthorization::class);
        $special->shouldReceive('allows')->once()->with(self::ACTOR,self::COUNCIL,self::BUDGET,self::TREASURY)->andReturn(false);
        $this->expectException(AuthorizationException::class);
        $this->admin($special)->issue(self::ACTOR,$this->payload(),'synthetic',null,new BonusIssuerContext('council',self::COUNCIL),self::BUDGET,self::TREASURY);
    }
    public function test_partial_internal_context_is_denied_before_special_authority_or_lock():void
    {
        $special=Mockery::mock(CouncilBecaWebAuthorization::class);$special->shouldNotReceive('allows');
        $this->expectException(AuthorizationException::class);
        $this->admin($special)->issue(self::ACTOR,$this->payload(),'synthetic',null,new BonusIssuerContext('council',self::COUNCIL));
    }
    public function test_institutional_issue_revalidates_treasury_separately_then_special_native_authority():void
    {
        $special=Mockery::mock(CouncilBecaWebAuthorization::class);
        $special->shouldReceive('allows')->once()->with(self::ACTOR,self::COUNCIL,self::BUDGET,self::TREASURY)->andReturn(false);
        $authority=Mockery::mock(InstitutionalBudgetAuthorization::class);
        $authority->shouldReceive('allows')->once()->with(self::TREASURY,'budget.commit',self::BUDGET)->andReturn(true);
        $locks=Mockery::mock(FinancialJobLock::class);$locks->shouldNotReceive('acquire');
        $issuers=Mockery::mock(BonusAuthorizationProvider::class);$issuers->shouldNotReceive('canIssueBonus');
        // Never used on a denied entry path; no constructor or database is invoked.
        $budget=(new \ReflectionClass(InstitutionalBudgetService::class))->newInstanceWithoutConstructor();
        $service=new InstitutionalBecaIssuanceService($authority,new PendingFinancialWebAuthorizer,$issuers,$this->admin($special),$budget,$locks,$special);
        $this->expectException(AuthorizationException::class);
        $service->issue(self::ACTOR,self::BUDGET,1,[],'synthetic',null,str_repeat('a',64),self::TREASURY,self::COUNCIL);
    }
}
