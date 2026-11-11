<?php

namespace Tests\Unit;

use App\Domains\Financial\Notifications\FinancialCommunityNotice;
use App\Domains\Financial\Notifications\FinancialCommunityNotices;
use App\Services\ClienteModuloHttp;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

/** No Laravel bootstrap, .env, MongoDB, external SQL or network. */
final class FinancialCommunityNoticeTest extends TestCase
{
    private const PAYMENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const REFUND = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OPERATION = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const BONUS = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const USER = '507f1f77bcf86cd799439011';

    private Container $previousContainer;
    private mixed $previousFacade;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private mixed $previousClock;
    private Capsule $db;
    private FinancialCommunityNotices $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousClock = Carbon::getTestNow();
        Model::clearBootedModels();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['financial_community_notices' => [
            'enabled' => true, 'since' => '2026-11-10T00:00:00Z',
        ]]));
        $this->db = new Capsule($container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
        $this->db->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $container->instance('db', $this->db->getDatabaseManager());
        $container->instance('events', new Dispatcher($container));
        $this->db->bootEloquent();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-10 12:00:00 UTC');
        (require dirname(__DIR__, 2).'/database/migrations/financial/2026_11_10_000100_create_financial_community_notices.php')->up();
        foreach ([
            'event_payment_requests' => ['public_id', 'status', 'transaction_id', 'student_id', 'event_id', 'organization_id', 'source_reference', 'confirmed_at', 'amount_cents'],
            'financial_transactions' => ['public_id', 'status', 'reference_type', 'reference_id', 'idempotency_key', 'original_transaction_id', 'metadata'],
            'financial_refund_requests' => ['public_id', 'status', 'original_transaction_id', 'financial_transaction_id', 'completed_at', 'amount_cents'],
            'bonus_budget_commitments' => ['public_id', 'award_reference', 'issue_operation_id', 'bonus_id', 'budget_id', 'amount_cents', 'currency', 'status', 'created_at'],
            'institutional_budget_operations' => ['public_id', 'action', 'idempotency_key', 'budget_id', 'after_data'],
            'bonuses' => ['public_id', 'type', 'beneficiary_type', 'beneficiary_id', 'external_reference', 'institutional_budget_id', 'original_amount_cents', 'currency'],
        ] as $table => $columns) {
            $this->db->getConnection('sqlsrv')->getSchemaBuilder()->create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) {
                    if (str_ends_with($column, '_cents')) { $t->bigInteger($column)->nullable(); }
                    else { $t->text($column)->nullable(); }
                }
            });
        }
        $http = $this->createStub(ClienteModuloHttp::class);
        $http->method('para')->willReturnCallback(function ($name, $scope) {
            self::assertSame('financial_community_notices', $name);
            self::assertContains($scope, ['notifications:read', 'notifications:send']);
            return Http::baseUrl('http://e6.test')->acceptJson();
        });
        $this->service = new FinancialCommunityNotices($http);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow($this->previousClock);
        $this->db->getDatabaseManager()->purge('sqlsrv');
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function table(string $table) { return $this->db->getConnection('sqlsrv')->table($table); }

    private function payment(string $uuid = self::PAYMENT, string $status = 'CONFIRMED'): void
    {
        $request = 'eeeeeeee-eeee-4eee-8eee-'.substr(str_replace('-', '', $uuid), 0, 12);
        $this->table('event_payment_requests')->insert([
            'public_id' => $request, 'status' => $status, 'transaction_id' => $uuid,
            'student_id' => self::USER, 'event_id' => str_repeat('a', 24), 'organization_id' => str_repeat('b', 24),
            'source_reference' => 'event:'.$uuid, 'amount_cents' => 12345, 'confirmed_at' => '2026-11-10T10:00:00.000Z',
        ]);
        $this->table('financial_transactions')->insert([
            'public_id' => $uuid, 'status' => 'COMPLETADA', 'reference_type' => 'COMMUNITY_EVENT',
            'reference_id' => 'event:'.$uuid, 'idempotency_key' => 'event-payment:'.$request,
            'metadata' => json_encode(['student_id' => self::USER]),
        ]);
    }

    private function refund(): void
    {
        $this->table('financial_refund_requests')->insert([
            'public_id' => self::OPERATION, 'status' => 'COMPLETADA', 'original_transaction_id' => self::PAYMENT,
            'financial_transaction_id' => self::REFUND, 'completed_at' => '2026-11-10 11:00:00', 'amount_cents' => 12345,
        ]);
        $this->table('financial_transactions')->insert([
            'public_id' => self::REFUND, 'status' => 'COMPLETADA', 'reference_type' => 'DEVOLUCION',
            'reference_id' => self::OPERATION, 'original_transaction_id' => self::PAYMENT,
            'metadata' => json_encode(['amount_cents' => 12345]),
        ]);
    }

    private function beca(): void
    {
        $reference = 'beca:'.str_repeat('a', 24);
        $this->table('bonuses')->insert([
            'public_id' => self::BONUS, 'type' => 'BECA', 'beneficiary_type' => 'USER', 'beneficiary_id' => self::USER,
            'external_reference' => $reference, 'institutional_budget_id' => self::PAYMENT,
            'original_amount_cents' => 12345, 'currency' => 'MXN',
        ]);
        $this->table('bonus_budget_commitments')->insert([
            'public_id' => self::REFUND, 'award_reference' => $reference, 'issue_operation_id' => self::OPERATION,
            'bonus_id' => self::BONUS, 'budget_id' => self::PAYMENT, 'amount_cents' => 12345,
            'currency' => 'MXN', 'status' => 'COMMITTED', 'created_at' => '2026-11-10 10:00:00',
        ]);
        $this->table('institutional_budget_operations')->insert([
            'public_id' => self::OPERATION, 'action' => 'ISSUE_BECA', 'idempotency_key' => $reference, 'budget_id' => self::PAYMENT,
            'after_data' => json_encode(['award_reference' => $reference, 'bonus_id' => self::BONUS,
                'committed_delta_cents' => 12345, 'community_award_fingerprint' => str_repeat('a', 64)]),
        ]);
    }

    public function test_disabled_and_missing_since_never_touch_sql_or_http(): void
    {
        config(['financial_community_notices.enabled' => false]);
        $this->db->getDatabaseManager()->purge('sqlsrv'); // A fresh connection has no outbox tables.
        self::assertSame(0, $this->service->scan(30)['queued']);
        self::assertSame(0, $this->service->deliver(30)['attempted']);
        config(['financial_community_notices.enabled' => true, 'financial_community_notices.since' => null]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->scan(30);
    }

    public function test_scans_three_proven_facts_with_frozen_payloads_and_stable_keys(): void
    {
        $this->payment(); $this->refund(); $this->beca();
        self::assertSame(3, $this->service->scan(30)['queued']);
        $rows = $this->table('financial_community_notices')->orderBy('id')->get();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('financial-community:'.$row->tipo.':'.$row->operation_id, $row->idempotency_key);
            self::assertLessThanOrEqual(128, strlen($row->idempotency_key));
            self::assertSame($row->idempotency_key, $payload['clave_idempotencia']);
            self::assertSame(self::USER, $payload['destinatario_id']);
            self::assertSame('normal', $payload['importancia']);
            self::assertStringContainsString('123.45 MXN', $payload['texto']);
            self::assertStringNotContainsString('QR', $payload['texto']);
            self::assertStringNotContainsString('saldo', $payload['texto']);
            self::assertSame($row->tipo === 'beca_emitida' ? self::BONUS : $row->operation_id, $payload['accion']['id']);
        }
        $this->table('event_payment_requests')->update(['amount_cents' => 99999]);
        self::assertSame(0, $this->service->scan(30)['queued']);
        self::assertSame($rows->pluck('payload')->all(), $this->table('financial_community_notices')->orderBy('id')->pluck('payload')->all());
        Http::assertNothingSent();
    }

    public function test_bounded_cursor_passes_bad_evidence_and_revisits_late_confirmation(): void
    {
        $this->payment(self::PAYMENT, 'PENDING');
        $this->payment(self::REFUND);
        $this->table('financial_transactions')->where('public_id', self::REFUND)->update(['reference_id' => 'wrong']);
        $this->payment(self::OPERATION);
        self::assertSame(1, $this->service->scan(1)['invalid']);
        self::assertSame(1, $this->service->scan(1)['queued']);
        $this->table('event_payment_requests')->where('transaction_id', self::PAYMENT)->update(['status' => 'CONFIRMED']);
        self::assertSame(1, $this->service->scan(1)['queued']);
        self::assertSame(2, $this->table('financial_community_notices')->count());
    }

    public function test_rejects_uncompleted_payments_refund_mismatch_and_unproven_beca(): void
    {
        $this->payment(); $this->refund(); $this->beca();
        $this->table('financial_transactions')->where('public_id', self::PAYMENT)->update(['status' => 'PENDIENTE']);
        $this->table('institutional_budget_operations')->update(['after_data' => '{}']);
        self::assertSame(0, $this->service->scan(30)['queued']);
        $this->table('financial_transactions')->where('public_id', self::PAYMENT)->update(['status' => 'COMPLETADA']);
        $this->table('financial_transactions')->where('public_id', self::REFUND)->update(['metadata' => '{"amount_cents":123.45}']);
        self::assertSame(1, $this->service->scan(30)['queued']);
        self::assertSame('pago_evento_confirmado', $this->table('financial_community_notices')->sole()->tipo);
    }

    public function test_lost_post_response_is_recovered_by_get_without_another_post(): void
    {
        $this->payment(); $this->service->scan(30);
        Http::fakeSequence()->push([], 404)->pushStatus(503)->push(['notificacion_id' => 'notice-1', 'estado' => 'entregada']);
        self::assertSame(1, $this->service->deliver(30)['pending']);
        $row = $this->table('financial_community_notices')->sole();
        self::assertSame(1, $row->attempts);
        self::assertSame('POST_HTTP_503', $row->last_error);
        self::assertSame(0, $this->service->deliver(30)['attempted']);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        self::assertSame(1, $this->service->deliver(30)['delivered']);
        self::assertSame(0, $this->service->deliver(30)['attempted']);
        $sent = Http::recorded();
        self::assertSame(['GET', 'POST', 'GET'], $sent->map(fn ($pair) => $pair[0]->method())->all());
        self::assertSame($row->idempotency_key, $sent[1][0]->header('Idempotency-Key')[0]);
        self::assertSame(json_decode($row->payload, true), $sent[1][0]->data());
    }

    public function test_non404_get_and_malformed_ack_stay_pending_without_post(): void
    {
        $this->payment(); $this->service->scan(30);
        Http::fakeSequence()->pushStatus(401)->push(['estado' => 'entregada']);
        self::assertSame(1, $this->service->deliver(30)['pending']);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        self::assertSame(1, $this->service->deliver(30)['pending']);
        Http::assertSentCount(2);
        self::assertSame('GET_INVALID_RESPONSE', $this->table('financial_community_notices')->sole()->last_error);
    }

    public function test_omitted_is_terminal_and_stale_leases_can_be_recovered(): void
    {
        $this->payment(); $this->service->scan(30);
        $this->table('financial_community_notices')->update(['locked_until' => '2026-11-10 12:01:00', 'lease_token' => self::BONUS]);
        Http::fakeSequence()->push(['notificacion_id' => 'notice-2', 'estado' => 'omitida']);
        self::assertSame(0, $this->service->deliver(30)['attempted']);
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        self::assertSame(1, $this->service->deliver(30)['omitted']);
        self::assertSame(0, $this->service->deliver(30)['attempted']);
    }

    public function test_transport_error_never_persists_credentials(): void
    {
        $this->payment(); $this->service->scan(30);
        Http::fake(fn () => throw new \RuntimeException('client_secret=fixture-do-not-store Bearer fixture-token'));
        self::assertSame(1, $this->service->deliver(30)['pending']);
        self::assertSame('TRANSPORT_UNAVAILABLE', $this->table('financial_community_notices')->sole()->last_error);
    }

    public function test_delivery_inside_a_transaction_cannot_send_http(): void
    {
        $this->db->getConnection('sqlsrv')->beginTransaction();
        try {
            $this->service->deliver(30);
            self::fail('Delivery must reject an ambient transaction.');
        } catch (\LogicException) {
            Http::assertNothingSent();
        } finally {
            $this->db->getConnection('sqlsrv')->rollBack();
        }
    }
    public function test_since_keeps_exact_cutoff_and_recent_refund_of_an_older_payment(): void
    {
        $this->payment(); $this->refund(); $this->beca();
        config(['financial_community_notices.since' => '2026-11-10T10:00:00.000001Z']);
        self::assertSame(1, $this->service->scan(30)['queued']);
        self::assertSame('devolucion_confirmada', $this->table('financial_community_notices')->sole()->tipo);
    }

    public function test_since_includes_facts_exactly_at_the_cutoff(): void
    {
        $this->payment(); $this->refund(); $this->beca();
        config(['financial_community_notices.since' => '2026-11-10T04:00:00-06:00']);
        self::assertSame(3, $this->service->scan(30)['queued']);
    }

    public function test_post_success_uses_exact_scopes_path_key_and_body(): void
    {
        $this->payment(); $this->service->scan(30);
        Http::fakeSequence()->pushStatus(404)->push(['notificacion_id' => 'notice-new', 'estado' => 'entregada'], 201);
        self::assertSame(1, $this->service->deliver(30)['delivered']);
        $requests = Http::recorded();
        self::assertSame('http://e6.test/api/v1/comunidad/notificaciones', $requests[1][0]->url());
        self::assertStringContainsString('clave_idempotencia=', $requests[0][0]->url());
        self::assertSame('GET', $requests[0][0]->method());
        self::assertSame('POST', $requests[1][0]->method());
    }

    public function test_outbox_content_cannot_be_changed_or_duplicated(): void
    {
        $this->payment(); $this->service->scan(30);
        $notice = FinancialCommunityNotice::firstOrFail();
        try {
            $duplicate = $notice->replicate();
            $duplicate->idempotency_key .= ':other';
            $duplicate->save();
            self::fail('The type and operation must be unique.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            self::assertSame(1, $this->table('financial_community_notices')->count());
        }
        $notice->payload = ['changed' => true];
        $this->expectException(\LogicException::class);
        $notice->save();
    }

    public function test_large_cent_amount_is_formatted_without_floating_point(): void
    {
        $this->payment();
        $this->table('event_payment_requests')->update(['amount_cents' => 9007199254740991]);
        self::assertSame(1, $this->service->scan(30)['queued']);
        $payload = json_decode($this->table('financial_community_notices')->sole()->payload, true);
        self::assertStringContainsString('90071992547409.91 MXN', $payload['texto']);
        self::assertLessThanOrEqual(180, mb_strlen($payload['asunto']));
        self::assertLessThanOrEqual(5000, mb_strlen($payload['texto']));
        self::assertSame(strip_tags($payload['texto']), $payload['texto']);
    }

    public function test_invalid_iso_dates_and_limits_fail_before_database_access(): void
    {
        $connection = $this->db->getConnection('sqlsrv');
        $connection->enableQueryLog();
        foreach (['', '2026-11-10', '2026-11-10T00:00:00', '2026-02-31T00:00:00Z', 'yesterday'] as $since) {
            config(['financial_community_notices.since' => $since]);
            try { $this->service->scan(30); self::fail('Invalid SINCE must fail.'); }
            catch (\InvalidArgumentException) { self::assertSame([], $connection->getQueryLog()); }
        }
        config(['financial_community_notices.since' => '2026-11-10T00:00:00Z']);
        foreach ([0, -1, 501] as $limit) {
            try { $this->service->scan($limit); self::fail('Invalid limit must fail.'); }
            catch (\InvalidArgumentException) { self::assertSame([], $connection->getQueryLog()); }
        }
        Http::assertNothingSent();
    }

}
