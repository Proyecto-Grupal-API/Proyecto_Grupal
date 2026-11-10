<?php

namespace Tests\Unit;

use App\Domains\Financial\Adapters\IdentityInstitutionalBudgetAuthorization;
use App\Services\EffectiveRoleAssignments;
use App\Services\IdentityService;
use App\Services\InstitutionalBudgetAuthorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstitutionalBudgetAuthorizationTest extends TestCase
{
    private const ACTOR = '111111111111111111111111';
    private const BUDGET = '12345678-1234-1234-1234-123456789abc';

    public static function invalid(): iterable
    {
        yield 'OAuth actor' => ['service:e6', 'budget.commit', self::BUDGET];
        yield 'browser role name' => ['tesoreria', 'budget.commit', self::BUDGET];
        yield 'missing scope' => [self::ACTOR, 'budget.commit', null];
        yield 'non canonical UUID' => [self::ACTOR, 'budget.commit', strtoupper(self::BUDGET)];
        yield 'partial UUID' => [self::ACTOR, 'budget.commit', substr(self::BUDGET, 1)];
        yield 'global administration' => [self::ACTOR, 'budget.approve', self::BUDGET];
        yield 'BECA issuance independent' => [self::ACTOR, 'bonuses.beca.issue', self::BUDGET];
    }

    #[DataProvider('invalid')]
    public function test_invalid_requests_never_query_or_authorize(string $actor, string $action, ?string $budget): void
    {
        $service = new MemoryBudgetAuthorization(new EffectiveRoleAssignments, new IdentityService);
        self::assertFalse($service->allows($actor, $action, $budget));
        self::assertSame([], $service->calls);
    }

    public function test_adapter_revalidates_each_actor_and_exact_budget_without_cached_authority(): void
    {
        $service = new MemoryBudgetAuthorization(new EffectiveRoleAssignments, new IdentityService);
        $adapter = new IdentityInstitutionalBudgetAuthorization($service);
        self::assertTrue($adapter->allows(self::ACTOR, 'budget.commit', self::BUDGET));
        self::assertFalse($adapter->allows(self::ACTOR, 'budget.commit', '87654321-1234-1234-1234-123456789abc'));
        $service->active = false;
        self::assertFalse($adapter->allows(self::ACTOR, 'budget.commit', self::BUDGET));
        self::assertCount(3, $service->calls);
        self::assertSame([true, false, false], array_column($service->audits, 'allowed'));
    }

    public function test_dependency_failure_is_audited_as_denial(): void
    {
        $service = new MemoryBudgetAuthorization(new EffectiveRoleAssignments, new IdentityService);
        $service->dependencyFailure = true;
        self::assertFalse($service->allows(self::ACTOR, 'budget.commit', self::BUDGET));
        self::assertSame('dependency-unavailable', $service->audits[0]['reason']);
    }

    public function test_audit_failure_never_returns_positive_authorization(): void
    {
        $service = new MemoryBudgetAuthorization(new EffectiveRoleAssignments, new IdentityService);
        $service->auditFailure = true;
        self::assertFalse($service->allows(self::ACTOR, 'budget.commit', self::BUDGET));
    }
}

final class MemoryBudgetAuthorization extends InstitutionalBudgetAuthorizationService
{
    public array $calls = [], $audits = [];
    public bool $active = true, $dependencyFailure = false, $auditFailure = false;
    protected function effective(string $actorId, string $budgetId): bool
    {
        $this->calls[] = [$actorId, $budgetId];
        if ($this->dependencyFailure) throw new RuntimeException('synthetic unavailable');
        return $this->active && $budgetId === '12345678-1234-1234-1234-123456789abc';
    }
    protected function audit(string $actorId, string $budgetId, bool $allowed, string $reason): void
    {
        if ($this->auditFailure) throw new RuntimeException('synthetic audit failure');
        $this->audits[] = compact('actorId', 'budgetId', 'allowed', 'reason');
    }
}
