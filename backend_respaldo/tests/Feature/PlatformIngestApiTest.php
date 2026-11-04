<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

/**
 * Cubre el receptor de ingesta de plataforma (/api/v1/plataforma/eventos|auditoria),
 * ya NO autenticado por Sanctum sino por firma HMAC por dominio
 * (ver AuthenticatePlatformSource + config/platform.php).
 */
class PlatformIngestApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    private const SECRET = 'secreto-de-pruebas-community-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('platform.ingest.enabled', true);
        Config::set('platform.ingest.secrets.community', self::SECRET);
        Config::set('platform.ingest.secrets.services', self::SECRET);

        User::query()->delete();
        AnalyticsEvent::query()->delete();
        $this->cleanPointsCollections();
    }

    private function envelope(array $o = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(), 'event_name' => 'community.audit.created.v1',
            'source_domain' => 'community', 'aggregate_id' => 'org-1',
            'occurred_at' => now()->toIso8601String(), 'payload' => ['organization_id' => 'org-1'],
        ], $o);
    }

    /** Headers firmados para un dominio dado. Mismo esquema que el emisor de Equipo 6. */
    private function signedHeaders(string $domain, string $method, string $path, array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        $value = $time.'|'.$method.'|'.$path.'|'.hash('sha256', $json);

        return [
            'X-Platform-Domain'    => $domain,
            'X-Platform-Timestamp' => $time,
            'X-Platform-Signature' => hash_hmac('sha256', $value, self::SECRET),
        ];
    }

    public function test_duplicate_event_with_same_content_is_counted_once(): void
    {
        $body = $this->envelope();
        $headers = $this->signedHeaders('community', 'POST', '/api/v1/plataforma/eventos', $body);

        $this->withHeaders($headers)->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(200);

        $this->assertSame(1, AnalyticsEvent::where('event_id', $body['event_id'])->count());
    }

    public function test_same_event_id_with_different_payload_returns_409(): void
    {
        $body = $this->envelope();
        $this->withHeaders($this->signedHeaders('community', 'POST', '/api/v1/plataforma/eventos', $body))
            ->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(202);

        $changed = $this->envelope(['event_id' => $body['event_id'], 'aggregate_id' => 'org-2']);
        $this->withHeaders($this->signedHeaders('community', 'POST', '/api/v1/plataforma/eventos', $changed))
            ->postJson('/api/v1/plataforma/eventos', $changed)->assertStatus(409);
    }

    public function test_audit_source_domain_comes_from_authenticated_signer_not_payload(): void
    {
        $body = [
            'event_id' => (string) Str::uuid(), 'actor_id' => 'actor-1', 'actor_role' => 'system',
            'action' => 'community.organization.approved', 'entity_type' => 'organization', 'entity_id' => 'org-1',
            'correlation_id' => 'corr-1', 'occurred_at' => now()->toIso8601String(),
            'source_domain' => 'services', // intenta falsear el dominio: debe ignorarse
        ];

        $this->withHeaders($this->signedHeaders('community', 'POST', '/api/v1/plataforma/auditoria', $body))
            ->postJson('/api/v1/plataforma/auditoria', $body)->assertStatus(202);

        $this->assertSame('community', AuditLog::where('event_id', $body['event_id'])->first()->source_domain);
    }

    public function test_invalid_envelope_returns_422_with_error_shape(): void
    {
        $body = $this->envelope(['payload' => ['email' => 'a@b.c']]);
        $this->withHeaders($this->signedHeaders('community', 'POST', '/api/v1/plataforma/eventos', $body))
            ->postJson('/api/v1/plataforma/eventos', $body)
            ->assertStatus(422)->assertJsonStructure(['codigo', 'mensaje', 'correlacion_id']);
    }

    public function test_request_without_valid_signature_is_rejected(): void
    {
        $this->postJson('/api/v1/plataforma/eventos', $this->envelope())->assertStatus(401);
    }

    public function test_wrong_domain_secret_is_rejected(): void
    {
        $body = $this->envelope();
        // Firma con el secreto de 'community' pero anunciándose como 'wallet' (sin secreto propio en el test).
        $headers = $this->signedHeaders('community', 'POST', '/api/v1/plataforma/eventos', $body);
        $headers['X-Platform-Domain'] = 'wallet';

        $this->withHeaders($headers)->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(401);
    }
}
