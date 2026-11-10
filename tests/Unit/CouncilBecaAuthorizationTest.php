<?php

namespace Tests\Unit;

use App\Contracts\BonusAuthorization;
use App\Http\Requests\FinancialAuthorizationRequest;
use App\Models\RoleAssignment;
use App\Services\CouncilBecaDesignationService;
use App\Services\FinancialAuthorizationPolicy;
use App\Services\IdentityService;
use App\Support\BecaFinanceContract;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class CouncilBecaAuthorizationTest extends TestCase
{
    private Container $previous;
    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new \Illuminate\Foundation\Application;
        $container->detectEnvironment(fn () => 'production');
        $container->instance('config', new Repository);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    }
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    public function test_council_is_checked_exactly_without_global_or_academic_branch(): void
    {
        $exact = new class implements BonusAuthorization {
            public array $calls = [];
            public bool $active = true;
            public function allows(?string $id, string $type, string $operation, ?string $scope = null, ?string $context = null): bool
            {
                $this->calls[] = [$id, $type, $operation, $scope, $context];
                return $scope === null || ($this->active && $scope === 'council' && $context === '111111111111111111111111');
            }
        };
        $policy = new FinancialAuthorizationPolicy($exact);
        self::assertTrue($policy->allows('actor', 'BECA', 'issue', 'council', '111111111111111111111111'));
        self::assertFalse($policy->allows('actor', 'BECA', 'issue', 'council', '222222222222222222222222'));
        $exact->active = false;
        self::assertFalse($policy->allows('actor', 'BECA', 'issue', 'council', '111111111111111111111111'));
        self::assertCount(3, $exact->calls);
        self::assertSame(['council', 'council', 'council'], array_column($exact->calls, 3));
        self::assertFalse($policy->allows('actor', 'BECA', 'issue', 'academic_program', '111111111111111111111111'));
        self::assertFalse($policy->allows('actor', 'BECA', 'issue', 'council', 'invented'));
    }

    public function test_request_contract_only_accepts_beca_with_canonical_council(): void
    {
        $factory = new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'));
        foreach ([['BECA','council','111111111111111111111111',true], ['CAMPANIA','council','111111111111111111111111',false],
            ['BECA','academic_program','111111111111111111111111',false], ['BECA','council','invented',false]] as [$type,$scope,$id,$valid]) {
            $request = FinancialAuthorizationRequest::create('/', 'POST', ['subject_id'=>'actor','bonus_type'=>$type,'operation'=>'issue','scope_type'=>$scope,'scope_id'=>$id]);
            $validator = $factory->make($request->all(), $request->rules());
            foreach ($request->after() as $after) $validator->after($after);
            self::assertSame($valid, $validator->passes());
        }
    }

    public function test_only_e2_configuration_establishes_council_budget_relation(): void
    {
        $council = '111111111111111111111111'; $budget='12345678-1234-1234-1234-123456789abc';
        self::assertFalse(BecaFinanceContract::councilBudgetMatches($council,$budget));
        config(['becas_finanzas.convocatorias' => [['organization_id'=>$council,'budget_id'=>$budget]]]);
        self::assertTrue(BecaFinanceContract::councilBudgetMatches($council,$budget));
        self::assertFalse(BecaFinanceContract::councilBudgetMatches('222222222222222222222222',$budget));
        self::assertFalse(BecaFinanceContract::councilBudgetMatches($council,'87654321-1234-1234-1234-123456789abc'));
    }

    public function test_every_designation_entry_point_remains_blocked_in_production_before_drivers(): void
    {
        $service = new CouncilBecaDesignationService(new IdentityService);
        $user = new \App\Models\User;
        $record = new RoleAssignment;
        foreach ([fn () => $service->assign($user,$user,'111111111111111111111111','Synthetic'),
            fn () => $service->revoke($user,$record,'111111111111111111111111','Synthetic'),
            fn () => $service->assignBudget($user,$user,'111111111111111111111111','12345678-1234-1234-1234-123456789abc','Synthetic'),
            fn () => $service->revokeBudget($user,$record,'111111111111111111111111','12345678-1234-1234-1234-123456789abc','Synthetic')] as $call) {
            try { $call(); self::fail('Productive designation accepted'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
        }
    }
}
