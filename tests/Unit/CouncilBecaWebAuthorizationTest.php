<?php

namespace Tests\Unit;

use App\Domains\Financial\Contracts\ContextualBonusAuthorizationProvider;
use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Models\User;
use App\Services\{CouncilBecaDesignationService, CouncilBecaWebAuthorization, EffectiveRoleAssignments, IdentityService, RecentTwoFactor};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No kernel, driver, HTTP, fixtures or persistence. */
final class CouncilBecaWebAuthorizationTest extends TestCase
{
    private const ACTOR='111111111111111111111111', COUNCIL='222222222222222222222222', TREASURY='333333333333333333333333';
    private const BUDGET='12345678-1234-1234-1234-123456789abc';
    private Container $previous;
    private Request $request;
    private object $state;
    private MemoryCouncilWebAuthorization $service;

    protected function setUp(): void
    {
        $this->previous=Container::getInstance(); $container=new Container; Container::setInstance($container);
        $container->instance('config',new Repository(['becas_finanzas'=>['enabled'=>true,'convocatorias'=>[['organization_id'=>self::COUNCIL,'budget_id'=>self::BUDGET]]]]));
        $user=new User; $user->setRawAttributes(['_id'=>self::ACTOR]); $user->exists=true;
        $this->request=Request::create('/finanzas/comunidad/becas/synthetic/emitir','POST');
        $this->request->setUserResolver(fn($guard=null)=>$guard==='web'?$user:null);
        $this->state=(object)['fresh'=>true,'eligible'=>true,'budget'=>true,'issuer'=>true,'failure'=>false,'active'=>true];
        $factor=Mockery::mock(RecentTwoFactor::class);
        $factor->shouldReceive('hasActiveSession')->with($this->request)->andReturnUsing(fn()=>$this->state->active);
        $factor->shouldReceive('isRecent')->with($this->request)->andReturnUsing(fn()=>$this->state->fresh);
        $identity=Mockery::mock(IdentityService::class);
        $identity->shouldReceive('isEligibleFinancialAccount')->with(self::ACTOR)->andReturnUsing(fn()=>$this->state->eligible);
        $budgets=Mockery::mock(InstitutionalBudgetAuthorization::class);
        $budgets->shouldReceive('allows')->with(self::TREASURY,'budget.commit',self::BUDGET)->andReturnUsing(function(){
            if($this->state->failure)throw new \RuntimeException('Synthetic dependency outage');return $this->state->budget;
        });
        $issuers=Mockery::mock(ContextualBonusAuthorizationProvider::class);
        $issuers->shouldNotReceive('canIssueBonus');
        $issuers->shouldReceive('canIssueInContext')->with('USER',self::ACTOR,BonusType::BECA,
            Mockery::on(fn($context)=>$context instanceof BonusIssuerContext && $context->scopeType==='council' && $context->scopeId===self::COUNCIL))
            ->andReturnUsing(fn()=>$this->state->issuer);
        $this->service=new MemoryCouncilWebAuthorization($this->request,$factor,$identity,new EffectiveRoleAssignments,
            new CouncilBecaDesignationService($identity),$budgets,$issuers);
    }
    protected function tearDown(): void {Mockery::close();Container::setInstance($this->previous);}
    private function allowed():bool{return $this->service->allows(self::ACTOR,self::COUNCIL,self::BUDGET,self::TREASURY);}

    private function configurePanel(): void
    {
        config(['becas_finanzas.convocatorias' => [
            'aaaaaaaaaaaaaaaaaaaaaaaa' => ['organization_id' => self::COUNCIL, 'budget_id' => self::BUDGET, 'business_id' => 'BUS-TEST'],
        ]]);
    }

    public function test_designated_issuer_can_open_the_panel_before_confirming_recent_factor(): void
    {
        $this->configurePanel();
        $this->state->fresh = false;
        self::assertTrue($this->service->canOpen());
        self::assertFalse($this->allowed());
    }

    public function test_panel_requires_an_active_session_and_effective_council_designation(): void
    {
        $this->configurePanel();
        $this->state->active = false;
        self::assertFalse($this->service->canOpen());
        self::assertSame([], $this->service->designationCalls);
        $this->state->active = true;
        $this->service->designationCurrent = false;
        self::assertFalse($this->service->canOpen());
    }

    public function test_panel_does_not_accept_browser_supplied_context_or_missing_configuration(): void
    {
        $this->request->merge(['council_id' => self::COUNCIL, 'budget_id' => self::BUDGET, 'actor_id' => self::ACTOR]);
        config(['becas_finanzas.convocatorias' => []]);
        self::assertFalse($this->service->canOpen());
        $this->configurePanel();
        config(['becas_finanzas.enabled' => false]);
        self::assertFalse($this->service->canOpen());
        self::assertSame([], $this->service->designationCalls);
    }

    public function test_panel_never_accepts_a_missing_web_identity_or_malformed_mapping(): void
    {
        $this->configurePanel();
        $this->request->setUserResolver(fn () => null);
        self::assertFalse($this->service->canOpen());
        $user = new User;
        $user->setRawAttributes(['_id' => self::ACTOR]);
        $this->request->setUserResolver(fn () => $user);
        config(['becas_finanzas.convocatorias' => [['organization_id' => self::COUNCIL, 'budget_id' => 'invalid', 'business_id' => 'BUS-TEST']]]);
        self::assertFalse($this->service->canOpen());
    }

    public function test_valid_native_actor_requires_both_independent_exact_authorities_and_audit():void
    {
        self::assertTrue($this->allowed());
        self::assertSame([self::ACTOR,'bonus_beca_council_issuer','council',self::COUNCIL,self::COUNCIL],$this->service->designationCalls[0]);
        self::assertSame([self::TREASURY,self::COUNCIL,self::BUDGET],$this->service->treasuryCalls[0]);
        self::assertTrue($this->service->audits[0]['allowed']);
    }
    public static function denials():iterable
    {
        foreach(['fresh','eligible','budget','issuer'] as $flag)yield $flag=>[$flag];
    }
    #[DataProvider('denials')]
    public function test_each_required_authority_is_mandatory(string $flag):void
    {
        $this->state->$flag=false;self::assertFalse($this->allowed());self::assertFalse($this->service->audits[0]['allowed']);
    }
    public function test_disabled_community_flow_cannot_be_enabled_by_a_context_or_permission():void
    {
        config(['becas_finanzas.enabled'=>false]);self::assertFalse($this->allowed());
        self::assertSame([],$this->service->designationCalls);
    }
    public function test_actor_from_body_or_another_user_cannot_replace_web_guard():void
    {
        $this->request->merge(['actor_id'=>self::ACTOR]);$this->request->setUserResolver(fn()=>null);
        self::assertFalse($this->allowed());
        $user=new User;$user->setRawAttributes(['_id'=>'444444444444444444444444']);
        $this->request->setUserResolver(fn()=>$user);self::assertFalse($this->allowed());
    }
    public function test_unaccredited_council_or_budget_is_rejected_before_permission_calls():void
    {
        self::assertFalse($this->service->allows(self::ACTOR,'444444444444444444444444',self::BUDGET,self::TREASURY));
        self::assertFalse($this->service->allows(self::ACTOR,self::COUNCIL,'87654321-1234-1234-1234-123456789abc',self::TREASURY));
        self::assertSame([],$this->service->designationCalls);
    }
    public function test_revocation_or_loss_of_grantor_authority_between_review_and_confirmation_blocks():void
    {
        self::assertTrue($this->allowed());$this->service->designationCurrent=false;
        self::assertFalse($this->allowed());self::assertCount(2,$this->service->designationCalls);
    }
    public function test_treasury_designation_or_its_grantor_can_be_revoked_independently():void
    {
        self::assertTrue($this->allowed());$this->service->treasuryCurrent=false;self::assertFalse($this->allowed());
    }
    public function test_expiry_between_review_and_confirmation_is_not_cached():void
    {
        self::assertTrue($this->allowed());$this->state->fresh=false;self::assertFalse($this->allowed());
    }
    public function test_dependency_failure_and_audit_failure_never_authorize():void
    {
        $this->state->failure=true;self::assertFalse($this->allowed());
        self::assertSame('dependency-unavailable',$this->service->audits[0]['reason']);
        $this->state->failure=false;$this->service->auditFailure=true;self::assertFalse($this->allowed());
    }
}

final class MemoryCouncilWebAuthorization extends CouncilBecaWebAuthorization
{
    public bool $designationCurrent=true,$treasuryCurrent=true,$auditFailure=false;
    public array $designationCalls=[],$treasuryCalls=[],$audits=[];
    protected function hasCurrentDesignation(User $user,string $role,string $type,string $id,string $councilId):bool
    {$this->designationCalls[]=[(string)$user->getKey(),$role,$type,$id,$councilId];return $this->designationCurrent;}
    protected function treasuryIsCurrent(string $actor,string $councilId,string $budgetId):bool
    {$this->treasuryCalls[]=[$actor,$councilId,$budgetId];return $this->treasuryCurrent;}
    protected function audit(string $actor,string $council,string $budget,string $treasury,bool $allowed,string $reason):void
    {if($this->auditFailure)throw new \RuntimeException('Synthetic audit failure');$this->audits[]=compact('allowed','reason');}
}
