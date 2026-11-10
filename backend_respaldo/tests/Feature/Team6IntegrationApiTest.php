<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

/**
 * Tests de integración para el contrato E6 (Comunidad) ↔ E7 (Recompensas / Auditoría).
 *
 * Cubre los requerimientos específicos solicitados por Equipo 6:
 * 1. Conflicto de contenido: mismo event_id con payload diferente retorna 409 Conflict.
 * 2. Origen de auditoría: se almacena estrictamente con source_domain = 'community'
 *    (nunca 'services'), derivado del emisor autenticado.
 * 3. Autenticación independiente: no requiere OAuth de E1 ni tokens locales de Sanctum.
 * 4. Idempotencia: reintento con mismo contenido retorna 200 duplicado.
 */
class Team6IntegrationApiTest extends TestCase
{
    use InteractsWithPointsCollections, WithFaker;

    private const SECRET = 'secreto_comunidad_eq6_2026_seguro';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('platform.ingest.enabled', true);
        Config::set('platform.ingest.secrets.community', self::SECRET);

        AnalyticsEvent::where('source_domain', 'community')->delete();
        AuditLog::where('source_domain', 'community')->delete();
        $this->cleanPointsCollections();
    }

    /**
     * Genera cabeceras con firma HMAC para ingesta de plataforma (/api/v1/plataforma/*).
     */
    private function platformHeaders(string $path, array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        $value = $time . '|POST|' . $path . '|' . hash('sha256', $json);

        return [
            'X-Platform-Domain'    => 'community',
            'X-Platform-Timestamp' => $time,
            'X-Platform-Signature' => hash_hmac('sha256', $value, self::SECRET),
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
        ];
    }

    /**
     * Genera cabeceras con firma HMAC estándar para /api/v1/integrations/ingest-event.
     */
    private function integrationHmacHeaders(array $body): array
    {
        $timestamp = (string) time();
        $path      = '/api/v1/integrations/ingest-event';
        $bodyHash  = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $payload   = $timestamp . '|POST|' . $path . '|' . $bodyHash;
        $signature = hash_hmac('sha256', $payload, self::SECRET);

        return [
            'X-Team-Timestamp'     => $timestamp,
            'X-Team-Signature'     => $signature,
            'X-Team-Source-Domain' => 'community',
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
        ];
    }

    // ── 1. Requerimiento 1: Conflicto 409 por contenido cambiado ─────────────

    /** @test */
    public function test_plataforma_reintento_con_mismo_contenido_retorna_200_duplicado(): void
    {
        $eventId = (string) Str::uuid();
        $body = [
            'event_id'      => $eventId,
            'event_name'    => 'community.organization.created.v1',
            'source_domain' => 'community',
            'aggregate_id'  => 'org-ieee-01',
            'occurred_at'   => now()->toIso8601String(),
            'payload'       => ['organization_id' => 'org-ieee-01', 'category' => 'academica'],
        ];

        $path = '/api/v1/plataforma/eventos';

        // Primera entrega -> 202 Aceptado
        $this->withHeaders($this->platformHeaders($path, $body))
            ->postJson($path, $body)
            ->assertStatus(202)
            ->assertJson(['estado' => 'aceptado']);

        // Reintento idéntico -> 200 Duplicado (sin error)
        $this->withHeaders($this->platformHeaders($path, $body))
            ->postJson($path, $body)
            ->assertStatus(200)
            ->assertJson(['estado' => 'duplicado', 'event_id' => $eventId]);

        $this->assertSame(1, AnalyticsEvent::where('event_id', $eventId)->count());
    }

    /** @test */
    public function test_plataforma_mismo_event_id_con_contenido_diferente_retorna_409_conflict(): void
    {
        $eventId = (string) Str::uuid();
        $bodyOriginal = [
            'event_id'      => $eventId,
            'event_name'    => 'community.activity.started.v1',
            'source_domain' => 'community',
            'aggregate_id'  => 'act-001',
            'occurred_at'   => now()->toIso8601String(),
            'payload'       => ['activity_id' => 'act-001', 'capacity' => 50],
        ];

        $path = '/api/v1/plataforma/eventos';

        // Primera entrega
        $this->withHeaders($this->platformHeaders($path, $bodyOriginal))
            ->postJson($path, $bodyOriginal)
            ->assertStatus(202);

        // Envío del mismo event_id pero con payload alterado (capacidad cambiada a 100)
        $bodyModificado = $bodyOriginal;
        $bodyModificado['payload'] = ['activity_id' => 'act-001', 'capacity' => 100];

        $response = $this->withHeaders($this->platformHeaders($path, $bodyModificado))
            ->postJson($path, $bodyModificado);

        // DEBE retornar 409 Conflict (Requerimiento 1 de E6)
        $response->assertStatus(409);
        $this->assertStringContainsString('El identificador ya existe con otro contenido', $response->json('message') ?? $response->getContent());
    }

    // ── 2. Requerimiento 2: Origen de auditoría strictly community ───────────

    /** @test */
    public function test_auditoria_se_guarda_con_source_domain_community_ignorando_payload_services(): void
    {
        $eventId = (string) Str::uuid();
        $body = [
            'event_id'       => $eventId,
            'actor_id'       => 'user-presidencia-01',
            'actor_role'     => 'admin_comunidad',
            'action'         => 'community.organization.election_closed',
            'entity_type'    => 'organization',
            'entity_id'      => 'org-sociedad-alumnos',
            'correlation_id' => 'corr-elec-2026',
            'occurred_at'    => now()->toIso8601String(),
            'source_domain'  => 'services', // Intento de enviar 'services'; DEBE ser ignorado
            'after'          => ['status' => 'closed', 'total_votes' => 142],
        ];

        $path = '/api/v1/plataforma/auditoria';

        $this->withHeaders($this->platformHeaders($path, $body))
            ->postJson($path, $body)
            ->assertStatus(202)
            ->assertJson(['estado' => 'aceptado']);

        // Verificar que en MongoDB se persistió estrictamente como 'community'
        $audit = AuditLog::where('event_id', $eventId)->first();
        $this->assertNotNull($audit, 'El registro de auditoría debe existir.');
        $this->assertSame('community', $audit->source_domain, 'source_domain debe ser community, nunca services.');
    }

    // ── 3. Compatibilidad con /api/v1/integrations/ingest-event ──────────────

    /** @test */
    public function test_ingest_event_unificado_acepta_eventos_comunidad_con_origen_community(): void
    {
        $eventId = (string) Str::uuid();
        $body = [
            'event_id'      => $eventId,
            'event_type'    => 'community.member_joined',
            'source_domain' => 'community',
            'payload'       => [
                'organization_id' => 'org-robotica',
                'actor_id'        => 'usr-007',
                'role'            => 'member',
            ],
        ];

        $headers = $this->integrationHmacHeaders($body);

        $this->postJson('/api/v1/integrations/ingest-event', $body, $headers)
            ->assertStatus(202)
            ->assertJsonFragment(['success' => true, 'status' => 'processed']);

        $audit = AuditLog::where('entity_id', 'org-robotica')->latest()->first();
        $this->assertNotNull($audit);
        $this->assertSame('community', $audit->source_domain);
    }

    /** @test */
    public function test_ingest_event_unificado_retorna_409_en_conflicto_de_contenido(): void
    {
        $eventId = (string) Str::uuid();
        $bodyOriginal = [
            'event_id'      => $eventId,
            'event_type'    => 'community.volunteer_event_registered',
            'source_domain' => 'community',
            'payload'       => ['event_name' => 'Reforestacion 2026', 'slots' => 30],
        ];

        $headers1 = $this->integrationHmacHeaders($bodyOriginal);
        $this->postJson('/api/v1/integrations/ingest-event', $bodyOriginal, $headers1)->assertStatus(202);

        // Mismo event_id con diferente payload
        $bodyModificado = [
            'event_id'      => $eventId,
            'event_type'    => 'community.volunteer_event_registered',
            'source_domain' => 'community',
            'payload'       => ['event_name' => 'Reforestacion 2026', 'slots' => 999], // contenido cambiado
        ];

        $headers2 = $this->integrationHmacHeaders($bodyModificado);
        $this->postJson('/api/v1/integrations/ingest-event', $bodyModificado, $headers2)
            ->assertStatus(409)
            ->assertJsonFragment([
                'success' => false,
                'codigo'  => 'conflicto_contenido',
                'error'   => 'idempotency_conflict',
            ]);
    }
}

