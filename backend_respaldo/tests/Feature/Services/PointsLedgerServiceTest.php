<?php

namespace Tests\Feature\Services;

use App\Models\AuditLog;
use App\Models\EarningRule;
use App\Models\FraudFlag;
use App\Models\PointCampaign;
use App\Models\PointsAccount;
use App\Models\PointsLedger;
use App\Services\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PointsLedger\DTO\RedeemPointsRequest;
use App\Services\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PointsLedger\Exception\DailyCapExceededException;
use App\Services\PointsLedger\Exception\InsufficientPointsException;
use App\Services\PointsLedger\Exception\LedgerEntryNotFoundException;
use App\Services\PointsLedger\Exception\NoActiveEarningRuleException;
use App\Services\PointsLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

/**
 * Tests de integración: corren contra un MongoDB real (replica set rs0), configurado
 * vía phpunit.xml para apuntar a una base separada (MONGO_DB_DATABASE=testing), no a
 * laravel_db. Correr con:
 *   docker compose exec app php artisan test --filter=PointsLedgerServiceTest
 */
class PointsLedgerServiceTest extends TestCase
{
    use InteractsWithPointsCollections;

    private PointsLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanPointsCollections();
        $this->service = new PointsLedgerService();
    }

    private function makeRule(array $overrides = []): EarningRule
    {
        return EarningRule::create(array_merge([
            'business_id' => 'biz-souvenirs',
            'status' => 'active',
            'points_per_unit' => 1,
            'unit_amount' => 2.0, // 1 punto por cada $2
            'fixed_points' => null,
            'multiplier' => 1.0,
            'max_points_per_operation' => 500,
            'daily_cap' => null,
            'active_from' => Carbon::now()->subDay(),
            'active_to' => null,
        ], $overrides));
    }

    private function earnRequest(array $overrides = []): EarnPointsRequest
    {
        return new EarnPointsRequest(
            studentId: $overrides['studentId'] ?? 'student-1',
            businessId: $overrides['businessId'] ?? 'biz-souvenirs',
            sourceDomain: $overrides['sourceDomain'] ?? 'marketplace',
            sourceReference: $overrides['sourceReference'] ?? 'order-1',
            saleAmount: $overrides['saleAmount'] ?? 600.0,
            idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
            campaignId: $overrides['campaignId'] ?? null,
            metadata: $overrides['metadata'] ?? [],
        );
    }

    // ------------------------------------------------------------------
    // earn()
    // ------------------------------------------------------------------

    public function test_earn_creates_ledger_entry_and_updates_account_balance(): void
    {
        $this->makeRule();

        $result = $this->service->earn($this->earnRequest(['saleAmount' => 600.0]));

        // 600 / 2 * 1 = 300 puntos
        $this->assertSame(300, $result->ledgerEntry->amount);
        $this->assertSame('earn', $result->ledgerEntry->type);
        $this->assertSame(300, $result->account->balance);
        $this->assertSame(300, $result->account->lifetime_earned);
        $this->assertFalse($result->wasIdempotentReplay);
    }

    public function test_earn_uses_fixed_points_when_set(): void
    {
        $this->makeRule(['fixed_points' => 50, 'points_per_unit' => 999]);

        $result = $this->service->earn($this->earnRequest(['saleAmount' => 10.0]));

        $this->assertSame(50, $result->ledgerEntry->amount);
    }

    public function test_earn_applies_rule_multiplier(): void
    {
        $this->makeRule(['multiplier' => 2.0, 'max_points_per_operation' => null]);

        $result = $this->service->earn($this->earnRequest(['saleAmount' => 600.0]));

        // 300 base * 2.0 = 600
        $this->assertSame(600, $result->ledgerEntry->amount);
    }

    public function test_earn_applies_campaign_multiplier_when_campaign_id_given(): void
    {
        $this->makeRule(['max_points_per_operation' => null]);
        $campaign = PointCampaign::create([
            'name' => 'Campaña de prueba',
            'type' => 'multiplier',
            'sponsor_type' => 'platform',
            'multiplier' => 3.0,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => null,
            'status' => 'active',
            'scope' => 'business',
            'scope_ids' => ['biz-souvenirs'],
        ]);

        $result = $this->service->earn($this->earnRequest([
            'saleAmount' => 600.0,
            'campaignId' => (string) $campaign->_id,
        ]));

        // 300 base * 3.0 = 900
        $this->assertSame(900, $result->ledgerEntry->amount);
    }

    public function test_earn_caps_at_max_points_per_operation(): void
    {
        $this->makeRule(['max_points_per_operation' => 100]);

        $result = $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // sería 300 sin tope

        $this->assertSame(100, $result->ledgerEntry->amount);
    }

    public function test_earn_throws_when_no_active_rule_for_business(): void
    {
        $this->expectException(NoActiveEarningRuleException::class);

        $this->service->earn($this->earnRequest(['businessId' => 'biz-sin-regla']));
    }

    public function test_earn_throws_when_rule_is_outside_its_validity_window(): void
    {
        $this->makeRule([
            'active_from' => Carbon::now()->subDays(10),
            'active_to' => Carbon::now()->subDays(5), // ya venció
        ]);

        $this->expectException(NoActiveEarningRuleException::class);

        $this->service->earn($this->earnRequest());
    }

    public function test_earn_partially_caps_points_when_daily_cap_is_almost_reached(): void
    {
        $rule = $this->makeRule(['daily_cap' => 400]);

        // Primera operación: 300 pts, dentro del tope (quedan 100 disponibles).
        $this->service->earn($this->earnRequest(['saleAmount' => 600.0, 'sourceReference' => 'order-1']));

        // Segunda operación pediría otros 300, pero solo quedan 100 de margen.
        $result = $this->service->earn($this->earnRequest(['saleAmount' => 600.0, 'sourceReference' => 'order-2']));

        $this->assertSame(100, $result->ledgerEntry->amount);
    }

    public function test_earn_throws_when_daily_cap_is_fully_exhausted(): void
    {
        $this->makeRule(['daily_cap' => 300]);

        $this->service->earn($this->earnRequest(['saleAmount' => 600.0, 'sourceReference' => 'order-1']));

        $this->expectException(DailyCapExceededException::class);

        $this->service->earn($this->earnRequest(['saleAmount' => 600.0, 'sourceReference' => 'order-2']));
    }

    public function test_earn_is_idempotent_on_retry_with_same_key(): void
    {
        $this->makeRule();
        $key = (string) Str::uuid();

        $first = $this->service->earn($this->earnRequest(['idempotencyKey' => $key]));
        $second = $this->service->earn($this->earnRequest(['idempotencyKey' => $key]));

        $this->assertTrue($second->wasIdempotentReplay);
        $this->assertEquals($first->ledgerEntry->_id, $second->ledgerEntry->_id);
        $this->assertSame(1, PointsLedger::where('idempotency_key', $key)->count());
        $this->assertSame($first->ledgerEntry->amount, $second->account->balance);
    }

    public function test_earn_writes_an_audit_log_entry(): void
    {
        $this->makeRule();

        $result = $this->service->earn($this->earnRequest());

        $log = AuditLog::where('entity_id', (string) $result->ledgerEntry->_id)->first();

        $this->assertNotNull($log);
        $this->assertSame('points.earned', $log->action);
        $this->assertNotNull($log->correlation_id);
    }

    // ------------------------------------------------------------------
    // redeem()
    // ------------------------------------------------------------------

    public function test_redeem_debits_balance_when_sufficient_points(): void
    {
        $this->makeRule();
        $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // 300 pts

        $result = $this->service->redeem(new RedeemPointsRequest(
            studentId: 'student-1',
            redemptionId: 'redemption-1',
            pointsRequired: 200,
            idempotencyKey: (string) Str::uuid(),
        ));

        $this->assertSame(-200, $result->ledgerEntry->amount);
        $this->assertSame(100, $result->account->balance);
        $this->assertSame(200, $result->account->lifetime_redeemed);
    }

    public function test_redeem_throws_when_insufficient_points(): void
    {
        $this->makeRule();
        $this->service->earn($this->earnRequest(['saleAmount' => 200.0])); // 100 pts

        $this->expectException(InsufficientPointsException::class);

        $this->service->redeem(new RedeemPointsRequest(
            studentId: 'student-1',
            redemptionId: 'redemption-1',
            pointsRequired: 500,
            idempotencyKey: (string) Str::uuid(),
        ));
    }

    public function test_redeem_is_idempotent_on_retry_with_same_key(): void
    {
        $this->makeRule();
        $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // 300 pts
        $key = (string) Str::uuid();

        $first = $this->service->redeem(new RedeemPointsRequest('student-1', 'redemption-1', 100, $key));
        $second = $this->service->redeem(new RedeemPointsRequest('student-1', 'redemption-1', 100, $key));

        $this->assertTrue($second->wasIdempotentReplay);
        $this->assertSame(1, PointsLedger::where('idempotency_key', $key)->count());
        $this->assertSame(200, $second->account->balance); // 300 - 100, no -200
    }

    // ------------------------------------------------------------------
    // reverse()
    // ------------------------------------------------------------------

    public function test_reverse_of_earn_with_full_balance_available_removes_all_points(): void
    {
        $this->makeRule();
        $earnResult = $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // 300 pts

        $result = $this->service->reverse(new ReversePointsRequest(
            relatedLedgerId: (string) $earnResult->ledgerEntry->_id,
            reason: 'Venta cancelada',
            idempotencyKey: (string) Str::uuid(),
        ));

        $this->assertSame('reverse', $result->ledgerEntry->type);
        $this->assertSame(-300, $result->ledgerEntry->amount);
        $this->assertSame(0, $result->account->balance);
        $this->assertSame(0, $result->shortfallAmount);
        $this->assertFalse($result->hasShortfall());
        $this->assertSame(0, FraudFlag::count());
    }

    public function test_reverse_of_earn_already_partially_redeemed_creates_shortfall_and_fraud_flag(): void
    {
        $this->makeRule();
        $earnResult = $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // 300 pts

        // El alumno ya canjeó 250 de esos 300 antes de que la venta se cancele.
        $this->service->redeem(new RedeemPointsRequest('student-1', 'redemption-1', 250, (string) Str::uuid()));

        $result = $this->service->reverse(new ReversePointsRequest(
            relatedLedgerId: (string) $earnResult->ledgerEntry->_id,
            reason: 'Venta cancelada',
            idempotencyKey: (string) Str::uuid(),
        ));

        // Disponible antes del reverso: 300 - 250 = 50. Había que quitar 300 -> faltan 250.
        $this->assertSame(250, $result->shortfallAmount);
        $this->assertTrue($result->hasShortfall());
        $this->assertSame(0, $result->account->balance); // se llevó lo que había disponible (50) a 0
        $this->assertSame(250, $result->account->pending_balance);

        $flag = FraudFlag::where('reason', 'points_reversal_shortfall')->first();
        $this->assertNotNull($flag);
        $this->assertStringContainsString('250', $flag->notes);
    }

    public function test_reverse_throws_when_original_ledger_entry_not_found(): void
    {
        $this->expectException(LedgerEntryNotFoundException::class);

        $this->service->reverse(new ReversePointsRequest(
            relatedLedgerId: (string) new \MongoDB\BSON\ObjectId(),
            reason: 'no existe',
            idempotencyKey: (string) Str::uuid(),
        ));
    }

    public function test_reverse_is_idempotent_on_retry_with_same_key(): void
    {
        $this->makeRule();
        $earnResult = $this->service->earn($this->earnRequest(['saleAmount' => 600.0]));
        $key = (string) Str::uuid();

        $first = $this->service->reverse(new ReversePointsRequest((string) $earnResult->ledgerEntry->_id, 'x', $key));
        $second = $this->service->reverse(new ReversePointsRequest((string) $earnResult->ledgerEntry->_id, 'x', $key));

        $this->assertTrue($second->wasIdempotentReplay);
        $this->assertSame(1, PointsLedger::where('idempotency_key', $key)->count());
    }

    // ------------------------------------------------------------------
    // getAvailableBalance() / reconcileAccount()
    // ------------------------------------------------------------------

    public function test_get_available_balance_sums_directly_from_ledger(): void
    {
        $this->makeRule();
        $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // +300
        $this->service->redeem(new RedeemPointsRequest('student-1', 'r-1', 120, (string) Str::uuid())); // -120

        $this->assertSame(180, $this->service->getAvailableBalance('student-1'));
    }

    public function test_reconcile_account_fixes_a_cache_that_drifted_from_the_ledger(): void
    {
        $this->makeRule();
        $this->service->earn($this->earnRequest(['saleAmount' => 600.0])); // 300 pts reales en el ledger

        // Simula que el cache se corrompió por alguna vía externa al servicio.
        PointsAccount::where('student_id', 'student-1')->update(['balance' => 999999]);

        $account = $this->service->reconcileAccount('student-1');

        $this->assertSame(300, $account->balance);
    }
}