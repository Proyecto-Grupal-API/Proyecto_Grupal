<?php

use App\Models\AuditoriaComunidad;
use App\Services\CommunityPlatformDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['platform.delivery.enabled' => true, 'platform.delivery.url' => 'http://app', 'platform.ingest.secrets.community' => 'transport-test-secret-20261027']);
    (require database_path('migrations/2026_10_27_000001_create_community_platform_deliveries.php'))->up();
    $this->audit = AuditoriaComunidad::create(['organizacion_id' => 'org-1', 'usuario_id' => 'user-1', 'accion' => 'attendance.recorded', 'entidad_id' => 'event-1', 'antes' => ['email' => 'private@example.test'], 'despues' => ['expediente' => 'private-file']]);
    Http::preventStrayRequests();
});
it('projects only canonical references and retries a lost response under the same identity', function () {
    $service = app(CommunityPlatformDelivery::class);
    $first = $service->envelope($this->audit);
    expect(json_encode($first))->not->toContain('private@example.test', 'private-file', 'expediente');
    Http::fake(['http://app/*' => Http::sequence()->push([], 503)->push(['estado' => 'aceptado', 'event_id' => $first['event_id']], 202)]);
    expect($service->deliver()['failed'])->toBe(1);
    expect($service->envelope($this->audit))->toBe($first);
    expect($service->deliver()['sent'])->toBe(1);
    Http::assertSent(function ($r) use ($first) {
        $time = $r->header('X-Platform-Timestamp')[0];

        return $r['event_id'] === $first['event_id'] && hash_equals($r->header('X-Platform-Signature')[0], hash_hmac('sha256', $time.'|POST|/api/v1/plataforma/auditoria|'.hash('sha256', $r->body()), config('platform.ingest.secrets.community')));
    });
    expect($service->deliver()['sent'])->toBe(0);
    Http::assertSentCount(2);
});
it('does not acknowledge a wrong receipt or send when disabled', function () {
    Http::fake(['http://app/*' => Http::response(['estado' => 'aceptado', 'event_id' => 'another-id'], 202)]);
    expect(app(CommunityPlatformDelivery::class)->deliver()['failed'])->toBe(1);
    expect(DB::connection('mongodb')->getCollection('community_platform_deliveries')->findOne(['audit_id' => (string) $this->audit->getKey()])['status'])->toBe('failed');
    config(['platform.delivery.enabled' => false]);
    expect(app(CommunityPlatformDelivery::class)->deliver()['enabled'])->toBeFalse();
    Http::assertSentCount(1);
});
