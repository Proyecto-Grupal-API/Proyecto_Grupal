<?php

namespace Tests\Feature;

use App\Models\PlatformModule\CommunityNotice;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\RedemptionEvent;
use App\Services\PlatformModule\CommunityNotices;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

/** Persisted Mongo fixtures; run only through the repository's protected test profile. */
class PlatformCommunityNoticesTest extends TestCase
{
    private const STUDENT = '507f1f77bcf86cd799439011';
    private const RECEIPT = '507f1f77bcf86cd799439012';
    private const URL = 'https://platform-notices.test';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('campus_virtual_testing', DB::connection('platform')->getDatabaseName());
        (require database_path('migrations/2026_11_11_100000_create_platform_community_notices.php'))->up();
        config(['platform.notices' => ['enabled' => true, 'url' => self::URL,
            'client_id' => 'e7-notices-only', 'client_secret' => 'fixture-secret',
            'since' => '2026-11-11T00:00:00-06:00']]);
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(12, 0));
        Http::preventStrayRequests();
    }

    private function ledger(array $attributes = []): PointsLedger
    {
        // Raw insert deliberately bypasses amount's integer cast to exercise malformed durable facts.
        $id = new ObjectId;
        DB::connection('platform')->getCollection((new PointsLedger)->getTable())->insertOne(array_replace([
            '_id' => $id, 'student_id' => self::STUDENT, 'type' => 'earn', 'amount' => 35,
            'created_at' => new UTCDateTime(now()), 'updated_at' => new UTCDateTime(now()),
            'description' => '<b>private@example.test</b>',
            'metadata' => ['student_id' => '507f1f77bcf86cd799439099', 'secret' => 'private-token'],
        ], $attributes));

        return PointsLedger::findOrFail((string) $id);
    }

    private function event(string $status, ?Redemption $redemption = null): RedemptionEvent
    {
        $redemption ??= Redemption::create(['student_id' => self::STUDENT, 'status' => 'cancelled',
            'points_spent' => 20, 'redemption_code' => 'PRIVATE-CODE', 'cancellation_reason' => 'private-reason']);

        return RedemptionEvent::create(['redemption_id' => (string) $redemption->id,
            'to_status' => $status, 'note' => 'private-note', 'actor_id' => 'private-actor',
            'metadata' => ['student_id' => '507f1f77bcf86cd799439099', 'secret' => 'private-token']]);
    }

    private function fakeDelivery(?callable $handler = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($handler) {
            if (str_ends_with($request->url(), '/api/oauth/token')) {
                return Http::response(['access_token' => 'fixture-token', 'expires_in' => 300]);
            }
            if ($handler) {
                return $handler($request);
            }

            return $request->method() === 'GET'
                ? Http::response(['codigo' => 'no_encontrada'], 404)
                : Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada'], 201);
        });
    }

    public function test_disabled_by_default_has_no_database_writes_or_http(): void
    {
        $this->ledger();
        config(['platform.notices' => []]);
        Http::fake();
        self::assertSame(0, app(CommunityNotices::class)->scan()['queued']);
        self::assertSame(0, app(CommunityNotices::class)->deliver()['attempted']);
        $this->artisan('plataforma:entregar-avisos-comunidad')->assertExitCode(0);
        self::assertSame(0, CommunityNotice::count());
        self::assertSame(0, DB::connection('platform')->getCollection('platform_community_notice_scans')->countDocuments());
        Http::assertNothingSent();
    }

    public function test_only_own_durable_facts_produce_exact_private_and_idempotent_payloads(): void
    {
        $ledger = $this->ledger();
        $events = array_map(fn ($status) => $this->event($status), ['confirmed', 'delivered', 'cancelled']);
        $this->event('reserved');
        $this->ledger(['type' => 'refund']);
        $this->ledger(['created_at' => new UTCDateTime(new \DateTimeImmutable('2026-11-11T05:59:59Z'))]);
        $before = DB::connection('platform')->getCollection((new PointsLedger)->getTable())->findOne(['_id' => new ObjectId($ledger->id)]);
        self::assertSame(4, app(CommunityNotices::class)->scan()['queued']);
        self::assertSame(0, app(CommunityNotices::class)->scan()['queued']);
        self::assertSame(4, CommunityNotice::count());
        $notice = CommunityNotice::where('source_id', (string) $ledger->id)->firstOrFail();
        self::assertSame('platform-community:puntos_acreditados:'.$ledger->id, $notice->idempotency_key);
        self::assertSame(['destinatario_id' => self::STUDENT, 'tipo' => 'puntos_acreditados',
            'asunto' => 'Puntos acreditados',
            'texto' => 'Se acreditaron 35 puntos a tu actividad de recompensas. Consulta el movimiento para ver los detalles.',
            'accion' => ['destino' => 'mi_actividad_recompensas', 'id' => (string) $ledger->id],
            'importancia' => 'normal'], $notice->payload);
        foreach ($events as $i => $event) {
            $notice = CommunityNotice::where('source_id', (string) $event->id)->firstOrFail();
            self::assertSame(['canje_confirmado', 'canje_entregado', 'canje_cancelado'][$i], $notice->payload['tipo']);
            self::assertSame(['destino' => 'mi_canje_recompensas', 'id' => $event->redemption_id], $notice->payload['accion']);
        }
        foreach (CommunityNotice::all() as $notice) {
            $json = json_encode($notice->payload, JSON_THROW_ON_ERROR);
            foreach (['private', 'PRIVATE', '<b>', 'saldo', 'devuelt', 'categoria', 'obligatoria', '439099'] as $secret) {
                self::assertStringNotContainsString($secret, $json);
            }
            self::assertLessThanOrEqual(128, strlen($notice->idempotency_key));
            self::assertLessThanOrEqual(180, mb_strlen($notice->payload['asunto']));
            self::assertLessThanOrEqual(5000, mb_strlen($notice->payload['texto']));
        }
        self::assertEquals($before, DB::connection('platform')->getCollection((new PointsLedger)->getTable())->findOne(['_id' => new ObjectId($ledger->id)]));
        Http::assertNothingSent();
    }

    public function test_raw_integer_and_real_student_are_required_and_metadata_cannot_repair_them(): void
    {
        foreach ([0, -1, 2.5, 2.0, '3', true, null] as $amount) {
            $this->ledger(['amount' => $amount]);
        }
        $this->ledger(['student_id' => 'student-1']);
        $this->event('confirmed', Redemption::create(['student_id' => 'bad-id', 'status' => 'confirmed']));
        RedemptionEvent::create(['redemption_id' => (string) new ObjectId, 'to_status' => 'delivered',
            'metadata' => ['student_id' => self::STUDENT]]);
        $stats = app(CommunityNotices::class)->scan();
        self::assertSame(0, $stats['queued']);
        self::assertSame(10, $stats['invalid']);
        self::assertSame(0, CommunityNotice::count());
    }

    public function test_since_compares_the_timezone_aware_inclusive_instant(): void
    {
        $old = $this->ledger(['created_at' => new UTCDateTime(new \DateTimeImmutable('2026-11-11T05:59:59.999Z'))]);
        $boundary = $this->ledger(['created_at' => new UTCDateTime(new \DateTimeImmutable('2026-11-11T06:00:00Z'))]);
        self::assertSame(1, app(CommunityNotices::class)->scan()['queued']);
        self::assertFalse(CommunityNotice::where('source_id', (string) $old->id)->exists());
        self::assertTrue(CommunityNotice::where('source_id', (string) $boundary->id)->exists());
    }

    public function test_scanning_skips_queued_first_page_and_advances_past_invalid_read_cap(): void
    {
        $this->ledger();
        self::assertSame(1, app(CommunityNotices::class)->scan(1)['queued']);
        for ($i = 0; $i < 105; ++$i) {
            $this->ledger(['amount' => 0]);
        }
        $last = $this->ledger();
        $queued = 0;
        for ($round = 0; $round < 4; ++$round) {
            $stats = app(CommunityNotices::class)->scan(1);
            self::assertLessThanOrEqual(200, $stats['scanned']); // 100 reads per source.
            $queued += $stats['queued'];
        }
        self::assertSame(1, $queued);
        self::assertTrue(CommunityNotice::where('source_id', (string) $last->id)->exists());
    }

    public function test_lookup_precedes_post_and_uses_only_the_dedicated_clients_two_scopes(): void
    {
        $ledger = $this->ledger();
        app(CommunityNotices::class)->scan();
        $notice = CommunityNotice::firstOrFail();
        $this->fakeDelivery();
        self::assertSame(1, app(CommunityNotices::class)->deliver()['delivered']);
        self::assertSame(0, app(CommunityNotices::class)->deliver()['attempted']);
        $calls = Http::recorded()->map(fn ($pair) => $pair[0]);
        self::assertSame(['POST', 'GET', 'POST', 'POST'], $calls->map(fn ($r) => $r->method())->all());
        self::assertSame('notifications:read', $calls[0]['scope']);
        self::assertSame('notifications:send', $calls[2]['scope']);
        self::assertSame('e7-notices-only', $calls[0]['client_id']);
        self::assertSame('e7-notices-only', $calls[2]['client_id']);
        self::assertSame($notice->idempotency_key, $calls[1]['clave_idempotencia']);
        self::assertSame([$notice->idempotency_key], $calls[3]->header('Idempotency-Key'));
        self::assertSame($notice->payload, $calls[3]->data());
        self::assertSame((string) $ledger->id, CommunityNotice::firstOrFail()->payload['accion']['id']);
    }

    public function test_ambiguous_lookup_including_timeout_never_posts_and_backs_off(): void
    {
        foreach ([503, 401, 403, 429, 302, 404, 200, 'timeout'] as $status) {
            $this->ledger();
            app(CommunityNotices::class)->scan();
            $this->fakeDelivery(function () use ($status) {
                if ($status === 'timeout') {
                    throw new ConnectionException('private-secret');
                }

                return Http::response(['codigo' => 'otro_error'], $status);
            });
            self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
            self::assertSame(0, app(CommunityNotices::class)->deliver()['attempted']);
            Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/api/oauth/token'));
        }
    }

    public function test_lost_post_response_is_recovered_by_get_without_second_post(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $notice = CommunityNotice::firstOrFail();
        $posts = 0;
        $this->fakeDelivery(function (Request $request) use (&$posts, $notice) {
            if ($request->method() === 'POST') {
                ++$posts;
                self::assertSame($notice->payload, $request->data());
                throw new ConnectionException('private response lost after E6 insert');
            }

            return $posts === 0 ? Http::response(['codigo' => 'no_encontrada'], 404)
                : Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada',
                    'clave_idempotencia' => $notice->idempotency_key, 'metadata' => ['secret' => 'private-response']]);
        });
        self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
        $this->travel(31)->seconds();
        self::assertSame(1, app(CommunityNotices::class)->deliver()['delivered']);
        self::assertSame(1, $posts);
        self::assertSame(2, CommunityNotice::firstOrFail()->attempts);
        self::assertStringNotContainsString('private', json_encode(CommunityNotice::firstOrFail()->toArray()));
    }

    public function test_explicit_not_found_with_wrong_key_does_not_authorize_post(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $this->fakeDelivery(fn () => Http::response(['codigo' => 'no_encontrada', 'clave_idempotencia' => 'other-key'], 404));
        self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/api/oauth/token'));
    }

    public function test_wrong_post_receipt_stays_pending_without_storing_response_metadata(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $this->fakeDelivery(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['codigo' => 'no_encontrada'], 404)
            : Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada',
                'clave_idempotencia' => 'wrong-key', 'metadata' => ['secret' => 'private-response']], 201));
        self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
        self::assertSame('PENDING', CommunityNotice::firstOrFail()->status);
        self::assertStringNotContainsString('private', json_encode(CommunityNotice::firstOrFail()->toArray()));
    }

    public function test_invalid_receipt_or_wrong_echoed_key_never_acknowledges_or_posts(): void
    {
        foreach ([['notificacion_id' => 'not-mongo', 'estado' => 'entregada'],
            ['notificacion_id' => self::RECEIPT, 'estado' => 'retirada'],
            ['notificacion_id' => self::RECEIPT, 'estado' => 'entregada', 'clave_idempotencia' => 'other-key'],
            ['notificacion_id' => self::RECEIPT, 'estado' => 'omitida', 'idempotency_key' => 'other-key'],
        ] as $body) {
            $this->ledger();
            app(CommunityNotices::class)->scan();
            $this->fakeDelivery(fn () => Http::response($body));
            self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
            Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/api/oauth/token'));
        }
    }

    public function test_omitted_is_terminal_and_payload_cannot_be_mutated(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $this->fakeDelivery(fn () => Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'omitida']));
        self::assertSame(1, app(CommunityNotices::class)->deliver()['omitted']);
        self::assertSame(0, app(CommunityNotices::class)->deliver()['attempted']);
        $notice = CommunityNotice::firstOrFail();
        $this->expectException(\LogicException::class);
        $notice->update(['payload' => ['texto' => 'changed']]);
    }

    public function test_unique_index_rejects_duplicate_key(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $copy = DB::connection('platform')->getCollection('platform_community_notices')->findOne([])->getArrayCopy();
        unset($copy['_id']);
        $this->expectException(\MongoDB\Driver\Exception\BulkWriteException::class);
        DB::connection('platform')->getCollection('platform_community_notices')->insertOne($copy);
    }

    public function test_active_lease_and_changed_updated_at_prevent_competing_delivery(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $table = DB::connection('platform')->getCollection('platform_community_notices');
        $table->updateOne([], ['$set' => ['locked_until' => new UTCDateTime(now()->addMinutes(2)), 'lease_token' => 'crashed-worker']]);
        $this->fakeDelivery(fn () => Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada']));
        self::assertSame(0, app(CommunityNotices::class)->deliver()['attempted']);
        Http::assertNothingSent();
        $this->travel(121)->seconds();
        self::assertSame(1, app(CommunityNotices::class)->deliver()['delivered']);
        self::assertSame('DELIVERED', CommunityNotice::firstOrFail()->status);
    }

    public function test_stale_worker_cannot_finish_a_newer_claim(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $table = DB::connection('platform')->getCollection('platform_community_notices');
        $this->fakeDelivery(function () use ($table) {
            $table->updateOne([], ['$set' => ['lease_token' => 'new-worker', 'updated_at' => new UTCDateTime(now()->addSecond())]]);

            return Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada']);
        });
        self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
        self::assertSame('PENDING', CommunityNotice::firstOrFail()->status);
        self::assertSame('new-worker', CommunityNotice::firstOrFail()->lease_token);
    }

    public function test_changed_updated_at_alone_prevents_stale_acknowledgement(): void
    {
        $this->ledger();
        app(CommunityNotices::class)->scan();
        $table = DB::connection('platform')->getCollection('platform_community_notices');
        $this->fakeDelivery(function () use ($table) {
            $table->updateOne([], ['$set' => ['updated_at' => new UTCDateTime(now()->addSecond())]]);

            return Http::response(['notificacion_id' => self::RECEIPT, 'estado' => 'entregada']);
        });
        self::assertSame(1, app(CommunityNotices::class)->deliver()['pending']);
        self::assertSame('PENDING', CommunityNotice::firstOrFail()->status);
        self::assertNotNull(CommunityNotice::firstOrFail()->lease_token);
    }

    public function test_since_is_mandatory_strict_and_timezone_aware(): void
    {
        $this->ledger();
        foreach ([null, '', 'yesterday', '2026-11-11', '2026-11-11T00:00:00', '2026-02-30T00:00:00Z', '2026-11-11T00:00:00+25:00'] as $since) {
            config(['platform.notices.since' => $since]);
            try {
                app(CommunityNotices::class)->scan();
                self::fail('Invalid SINCE was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, CommunityNotice::count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_command_limits_and_transaction_guard(): void
    {
        foreach (['0', '101', '1.5', '-1', 'abc'] as $limit) {
            $this->artisan('plataforma:entregar-avisos-comunidad', ['--limit' => $limit])->assertExitCode(2);
        }
        $this->ledger();
        $this->fakeDelivery();
        $this->artisan('plataforma:entregar-avisos-comunidad', ['--limit' => '1'])->assertExitCode(0);
        DB::connection('platform')->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            app(CommunityNotices::class)->deliver();
        } finally {
            DB::connection('platform')->rollBack();
        }
    }
}
