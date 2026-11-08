<?php

use App\Models\PlatformModule\AuditLog;
use App\Models\StudentServices\Audit\ServiceAuditLog;
use App\Services\StudentServices\Audit\PlatformAuditDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'platform.services_delivery' => ['enabled' => true, 'url' => 'http://app', 'since' => now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z')],
        'platform.ingest.enabled' => true,
        'platform.ingest.secrets.services' => 'services-fixture-credential',
    ]);
    Http::preventStrayRequests();
    $this->addAudit = fn (array $changes = []) => ServiceAuditLog::create($changes + [
        'event_id' => (string) Str::uuid(), 'source_domain' => 'services', 'actor_id' => '507f1f77bcf86cd799439011',
        'actor_role' => 'texto privado', 'action' => 'library.fine.waived', 'entity_type' => 'library_fine',
        'entity_id' => '507f1f77bcf86cd799439012', 'occurred_at' => now()->subMinute(), 'published_at' => null,
        'before' => ['amount_cents' => 1500], 'after' => ['name' => 'Persona de prueba'], 'reason' => 'Expediente privado',
        'correlation_id' => 'texto-externo', 'ip_address' => '192.0.2.1', 'device' => 'dispositivo privado',
    ]);
});

it('delivers a minimal signed envelope and acknowledges only a matching E7 receipt', function () {
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    $audit = ($this->addAudit)();
    $path = '/api/v1/plataforma/auditoria';
    Http::fake(['http://app'.$path => Http::sequence()
        ->push(['estado' => 'aceptado', 'event_id' => (string) Str::uuid()], 202)
        ->push(['estado' => 'duplicado', 'event_id' => $audit->event_id], 200)]);

    $this->artisan('servicios:publicar-auditoria')->assertFailed();
    expect($audit->fresh()->published_at)->toBeNull();

    $request = Http::recorded()->first()[0];
    expect($request->data())->toBe([
        'event_id' => $audit->event_id, 'actor_id' => $audit->actor_id, 'actor_role' => 'not_recorded',
        'action' => $audit->action, 'entity_type' => $audit->entity_type, 'entity_id' => $audit->entity_id,
        'correlation_id' => 'services:'.$audit->event_id, 'occurred_at' => $audit->occurred_at->toISOString(),
    ]);
    $headers = [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PLATFORM_DOMAIN' => $request->header('X-Platform-Domain')[0],
        'HTTP_X_PLATFORM_TIMESTAMP' => $request->header('X-Platform-Timestamp')[0],
        'HTTP_X_PLATFORM_SIGNATURE' => $request->header('X-Platform-Signature')[0],
    ];
    $this->call('POST', $path, [], [], [], $headers, $request->body())->assertAccepted();
    $this->call('POST', $path, [], [], [], $headers, $request->body())->assertOk();
    expect(AuditLog::where('source_domain', 'services')->count())->toBe(1);

    $this->artisan('servicios:publicar-auditoria')->assertSuccessful();
    expect($audit->fresh()->published_at)->not->toBeNull();
    $this->artisan('servicios:publicar-auditoria')->assertSuccessful();
    Http::assertSentCount(2);
});

it('rotates failed deliveries and keeps older and unapproved audit actions local', function () {
    $first = ($this->addAudit)(['occurred_at' => now()->subMinutes(2)]);
    $second = ($this->addAudit)();
    $older = ($this->addAudit)(['occurred_at' => now()->subHours(2)]);
    $unsupported = ($this->addAudit)(['action' => 'calendar.rules.updated']);
    Http::fake(['http://app/*' => Http::sequence()->pushFailedConnection()
        ->push(['estado' => 'aceptado', 'event_id' => $second->event_id], 202)]);

    $this->artisan('servicios:publicar-auditoria', ['--limit' => 1])->assertFailed();
    $this->artisan('servicios:publicar-auditoria', ['--limit' => 1])->assertSuccessful();

    expect($first->fresh()->published_at)->toBeNull()
        ->and($second->fresh()->published_at)->not->toBeNull()
        ->and($older->fresh()->published_at)->toBeNull()
        ->and($unsupported->fresh()->published_at)->toBeNull();
});

it('requires explicit enablement and a cutoff before opening the connection', function () {
    ($this->addAudit)();
    Http::fake();
    $service = app(PlatformAuditDelivery::class);
    config(['platform.services_delivery.enabled' => false]);
    expect($service->deliver()['enabled'])->toBeFalse();
    config(['platform.services_delivery.enabled' => true, 'platform.services_delivery.since' => '']);
    $this->artisan('servicios:publicar-auditoria')->assertFailed();
    Http::assertNothingSent();
});
