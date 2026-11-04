<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\WithFaker;

/**
 * Tests de integración para el contrato E1 (Identidad) ↔ E7 (Recompensas).
 *
 * Verificado directamente contra el código de Equipo 1:
 *   - Command PublishDomainEvents: envía 'event_name', 'event_id', 'aggregate_id', 'occurred_at', 'payload'
 *   - Auth: Bearer token estático configurado vía EVENTS_SINK_TOKEN
 *   - Rutas soportadas: /api/v1/integration/events y /api/v1/integrations/ingest-event
 *   - Eventos student.* mapeados a source_domain=identity
 *   - Idempotencia: 200 en duplicado = recepción satisfactoria (acknowledged en Outbox)
 *   - Sanitización de PII: datos personales no se almacenan en audit_logs
 */
class Team1IntegrationApiTest extends TestCase
{
    use WithFaker;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Genera cabeceras HMAC-SHA256 para Equipo 1 (source_domain=identity).
     */
    private function hmacHeaders(array $body, string $secret = 'test_secret_equipo1'): array
    {
        $timestamp = (string) time();
        $path      = '/api/v1/integrations/ingest-event';
        $bodyHash  = hash('sha256', json_encode($body));
        $payload   = $timestamp . '|POST|' . $path . '|' . $bodyHash;
        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            'X-Team-Timestamp'     => $timestamp,
            'X-Team-Signature'     => $signature,
            'X-Team-Source-Domain' => 'identity',
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
        ];
    }

    /**
     * Genera cabeceras Bearer Token como las que envía PublishDomainEvents de Equipo 1 vía EVENTS_SINK_TOKEN.
     */
    private function bearerHeaders(string $token = 'test_service_token_equipo1'): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Limpia colecciones de MongoDB relevantes antes de cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsEvent::where('source_domain', 'identity')->delete();
        AuditLog::where('source_domain', 'identity')->delete();
        IntegrationLog::where('domain', 'identity')->delete();
        WebhookEvent::where('source', 'equipo1_identity')->delete();
    }

    // ── 1. Compatibilidad exacta con PublishDomainEvents (Equipo 1) ──────────

    /** @test */
    public function test_publish_domain_events_payload_con_event_name_y_bearer_token_es_aceptado(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $eventId = $this->faker->uuid();
        $studentId = 'usr_' . $this->faker->numerify('#####');

        // Formato idéntico al enviado por PublishDomainEvents de Equipo 1
        $body = [
            'event_id'     => $eventId,
            'event_name'   => 'student.profile.changed.v1',
            'aggregate_id' => $studentId,
            'occurred_at'  => now()->toIso8601String(),
            'payload'      => [
                'student_id'     => $studentId,
                'operation'      => 'update',
                'changed_fields' => ['program', 'semester'],
                'actor_id'       => 'admin_user_01',
            ],
        ];

        // Llamada a la ruta exacta documentada en TEAM-7.md (/api/v1/integration/events)
        $response = $this->postJson(
            '/api/v1/integration/events',
            $body,
            $this->bearerHeaders('test_service_token_equipo1')
        );

        $response->assertStatus(202)
            ->assertJsonFragment(['success' => true, 'status' => 'processed'])
            ->assertJsonFragment(['event_type' => 'student.profile.changed.v1'])
            ->assertJsonFragment(['audit_action' => 'identity.student.profile.changed']);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'identity.student.profile.changed',
            'source_domain' => 'identity',
            'entity_id'     => $studentId,
        ]);

        $this->assertDatabaseHas('analytics_events', [
            'idempotency_key' => $eventId,
            'source_domain'   => 'identity',
            'event_type'      => 'student.profile.changed.v1',
        ]);
    }

    /** @test */
    public function test_student_consent_changed_v1_es_aceptado_y_auditado(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $eventId = $this->faker->uuid();
        $studentId = 'usr_' . $this->faker->numerify('#####');

        $body = [
            'event_id'     => $eventId,
            'event_name'   => 'student.consent.changed.v1',
            'aggregate_id' => $studentId,
            'occurred_at'  => now()->toIso8601String(),
            'payload'      => [
                'student_id' => $studentId,
                'consent_id' => 'consent_data_rewards',
                'status'     => 'accepted',
                'version'    => 'v1.0',
                'actor_id'   => $studentId,
            ],
        ];

        $response = $this->postJson(
            '/api/v1/integration/events',
            $body,
            $this->bearerHeaders('test_service_token_equipo1')
        );

        $response->assertStatus(202)
            ->assertJsonFragment(['success' => true])
            ->assertJsonFragment(['audit_action' => 'identity.student.consent.changed']);

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'identity.student.consent.changed',
            'source_domain' => 'identity',
            'entity_id'     => $studentId,
        ]);
    }

    /** @test */
    public function test_identity_credential_changed_v1_es_aceptado_como_evento_sensible(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $eventId = $this->faker->uuid();
        $cardId = 'nfc_' . $this->faker->numerify('#####');
        $studentId = 'usr_' . $this->faker->numerify('#####');

        $body = [
            'event_id'     => $eventId,
            'event_name'   => 'identity.credential.changed.v1',
            'aggregate_id' => $cardId,
            'occurred_at'  => now()->toIso8601String(),
            'payload'      => [
                'credential_id'   => $cardId,
                'credential_type' => 'nfc',
                'operation'       => 'status_changed',
                'status'          => 'blocked',
                'actor_id'        => 'admin_user_02',
                'student_id'      => $studentId,
                'old_status'      => 'active',
                'new_status'      => 'blocked',
                'reason'          => 'Tarjeta extraviada reportada por el alumno',
                // Campo sensible hipotético que debe ser omitido
                'nfc_uid'         => '04E1F2A3B4C5D6',
            ],
        ];

        $response = $this->postJson(
            '/api/v1/integration/events',
            $body,
            $this->bearerHeaders('test_service_token_equipo1')
        );

        $response->assertStatus(202)
            ->assertJsonFragment(['success' => true, 'sensitive' => true])
            ->assertJsonFragment(['audit_action' => 'identity.credential.changed']);

        // Verificar que no se guarde el nfc_uid en audit_logs
        $auditEntry = AuditLog::where('action', 'identity.credential.changed')->latest()->first();
        $this->assertNotNull($auditEntry);
        $this->assertArrayNotHasKey('nfc_uid', $auditEntry->after ?? []);
    }

    // ── 2. Idempotencia y Reintentos (Outbox at-least-once) ───────────────────

    /** @test */
    public function test_evento_duplicado_retorna_200_already_processed_para_confirmar_outbox(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $eventId = $this->faker->uuid();
        $body = [
            'event_id'   => $eventId,
            'event_name' => 'student.profile.changed.v1',
            'payload'    => ['student_id' => 'usr_0001', 'operation' => 'create'],
        ];

        $headers = $this->bearerHeaders('test_service_token_equipo1');

        // Primera entrega: procesado (HTTP 202) -> Outbox marca published_at
        $this->postJson('/api/v1/integration/events', $body, $headers)
            ->assertStatus(202)
            ->assertJsonFragment(['status' => 'processed']);

        // Reintento con mismo event_id: HTTP 200 (éxito idempotente) -> Outbox confirma sin fallar
        $this->postJson('/api/v1/integration/events', $body, $headers)
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'already_processed', 'replayed' => true]);
    }

    /** @test */
    public function test_conflicto_de_event_id_retorna_200_y_audita_advertencia(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $eventId = $this->faker->uuid();

        $body1 = [
            'event_id'   => $eventId,
            'event_name' => 'student.profile.changed.v1',
            'payload'    => ['student_id' => 'usr_0002'],
        ];
        $headers = $this->bearerHeaders('test_service_token_equipo1');
        $this->postJson('/api/v1/integration/events', $body1, $headers)->assertStatus(202);

        // Mismo event_id pero distinto event_name
        $body2 = [
            'event_id'   => $eventId,
            'event_name' => 'student.consent.changed.v1',
            'payload'    => ['student_id' => 'usr_0002'],
        ];
        $this->postJson('/api/v1/integration/events', $body2, $headers)
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'already_processed']);

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'integration.event_id_conflict',
            'entity_id' => $eventId,
        ]);
    }

    // ── 3. Autenticación Bearer Token y HMAC ──────────────────────────────────

    /** @test */
    public function test_bearer_token_invalido_retorna_401(): void
    {
        config(['services.team1.service_token' => 'token_correcto']);

        $body = [
            'event_id'   => $this->faker->uuid(),
            'event_name' => 'student.profile.changed.v1',
            'payload'    => ['student_id' => 'usr_0003'],
        ];

        $this->postJson('/api/v1/integration/events', $body, $this->bearerHeaders('token_incorrecto'))
            ->assertStatus(401)
            ->assertJsonFragment(['success' => false]);
    }

    /** @test */
    public function test_hmac_sha256_sigue_siendo_compatible(): void
    {
        config(['services.team1.shared_secret' => 'test_secret_equipo1']);

        $eventId = $this->faker->uuid();
        $body = [
            'event_id'   => $eventId,
            'event_name' => 'student.profile.changed.v1',
            'payload'    => ['student_id' => 'usr_0004'],
        ];

        $this->postJson('/api/v1/integrations/ingest-event', $body, $this->hmacHeaders($body))
            ->assertStatus(202)
            ->assertJsonFragment(['success' => true]);
    }

    // ── 4. Validación de campos ───────────────────────────────────────────────

    /** @test */
    public function test_sin_event_name_ni_event_type_retorna_422(): void
    {
        config(['services.team1.service_token' => 'test_service_token_equipo1']);

        $body = [
            'event_id' => $this->faker->uuid(),
            'payload'  => ['student_id' => 'usr_0005'],
        ];

        $this->postJson('/api/v1/integration/events', $body, $this->bearerHeaders('test_service_token_equipo1'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_type', 'event_name']);
    }
}
