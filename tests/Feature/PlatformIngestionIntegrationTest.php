<?php

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\PlatformModule\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['platform.ingest.enabled' => true, 'platform.ingest.secrets.community' => 'test-community-secret']);
});
function platformBody(): array
{
    return ['event_id' => (string) Str::uuid(), 'event_name' => 'community.attendance.recorded.v1', 'source_domain' => 'community', 'aggregate_id' => 'event-1', 'occurred_at' => '2026-10-27T10:00:00Z', 'payload' => ['student_id' => 'student-1']];
}
function signedPlatform($test, string $path, array $body, string $domain = 'community', int $offset = 0)
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = (string) (time() + $offset);

    return $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PLATFORM_DOMAIN' => $domain, 'HTTP_X_PLATFORM_TIMESTAMP' => $time, 'HTTP_X_PLATFORM_SIGNATURE' => hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $json), 'test-community-secret')], $json);
}
it('rejects unsigned expired or wrong-source ingestion without storing anything', function () {
    $b = platformBody();
    $p = '/api/v1/plataforma/eventos';
    $this->postJson($p, $b)->assertUnauthorized();
    signedPlatform($this, $p, $b, 'community', -600)->assertUnauthorized();
    signedPlatform($this, $p, $b, 'inventory')->assertUnauthorized();
    signedPlatform($this, $p, [...$b, 'source_domain' => 'wallet'])->assertForbidden();
    expect(AnalyticsEvent::count())->toBe(0);
});
it('stores the same event once and rejects changed content under the same identity', function () {
    $b = platformBody();
    $p = '/api/v1/plataforma/eventos';
    signedPlatform($this, $p, $b)->assertStatus(202);
    signedPlatform($this, $p, $b)->assertOk();
    signedPlatform($this, $p, [...$b, 'payload' => ['student_id' => 'someone-else']])->assertConflict();
    expect(AnalyticsEvent::count())->toBe(1);
});
it('refuses personal information nested in analytics payload', function () {
    $b = platformBody();
    $b['payload'] = ['context' => ['email' => 'private@example.test']];
    signedPlatform($this, '/api/v1/plataforma/eventos', $b)->assertUnprocessable();
    expect(AnalyticsEvent::count())->toBe(0);
});
it('attributes audits to the authenticated domain and checks identical-content retries', function () {
    $b = ['event_id' => (string) Str::uuid(), 'actor_id' => 'user-1', 'actor_role' => 'community_actor', 'action' => 'attendance.recorded', 'entity_type' => 'event', 'entity_id' => 'event-1', 'correlation_id' => 'correlation-1', 'occurred_at' => '2026-10-27T10:00:00Z', 'after' => ['organization_id' => 'org-1']];
    $p = '/api/v1/plataforma/auditoria';
    signedPlatform($this, $p, $b)->assertStatus(202);
    signedPlatform($this, $p, $b)->assertOk();
    signedPlatform($this, $p, [...$b, 'action' => 'another.action'])->assertConflict();
    expect(AuditLog::count())->toBe(1)->and(AuditLog::first()->source_domain)->toBe('community');
    expect(DB::connection('platform')->getCollection('audit_correlations')->findOne(['correlation_id' => 'correlation-1'])['origin_domain'])->toBe('community');
});
