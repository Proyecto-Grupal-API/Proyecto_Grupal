<?php

namespace Tests\Feature;

use App\Models\Organizacion;
use App\Models\ServiceClient;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationSummaryApiTest extends TestCase
{
    private const SCOPE = 'community:organizations:read';

    private function organization(string $type = 'asociacion', string $status = 'activa'): Organizacion
    {
        return Organizacion::create([
            'nombre' => 'Organización de prueba', 'tipo' => $type, 'estado' => $status,
            'email' => 'privado@example.test', 'telefono' => '5550000000',
            'descripcion' => 'Datos internos', 'creado_por' => 'private-user-id',
            'clave_alta' => 'private-creation-key', 'firma_alta' => 'private-signature',
            'alta_datos' => ['usuario_id' => 'private-president'],
            'historial_estados' => [['motivo' => 'Motivo interno']],
        ]);
    }

    private function client(array $scopes = [self::SCOPE]): ServiceClient
    {
        return ServiceClient::create([
            'name' => 'Equipo 7 de prueba', 'client_id' => 'svc_rewards_test',
            'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true,
        ]);
    }

    private function token(ServiceClient $client, string $scope = self::SCOPE): string
    {
        return $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->client_id,
            'client_secret' => 'test-secret', 'scope' => $scope,
        ])->assertOk()->json('access_token');
    }

    private function url(string $id): string
    {
        return '/api/v1/organizations/'.$id.'/summary';
    }

    public static function summaries(): array
    {
        return [
            'active association' => ['asociacion', 'activa', 'association', 'active'],
            'suspended association' => ['asociacion', 'suspendida', 'association', 'suspended'],
            'configuring association' => ['asociacion', 'configurando', 'association', 'configuring'],
            'active council' => ['consejo', 'activa', 'council', 'active'],
            'suspended council' => ['consejo', 'suspendida', 'council', 'suspended'],
            'configuring council' => ['consejo', 'configurando', 'council', 'configuring'],
        ];
    }

    #[DataProvider('summaries')]
    public function test_returns_only_the_summary_without_membership_or_personal_data(string $type, string $status, string $apiType, string $apiStatus): void
    {
        $organization = $this->organization($type, $status);
        $before = $organization->toArray();
        $token = $this->token($this->client());
        // No Accept header: service endpoints still return JSON.
        $this->get($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertExactJson([
                'id' => (string) $organization->id, 'type' => $apiType,
                'name' => $organization->nombre, 'status' => $apiStatus,
            ])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $organization->fresh()->toArray());
        $this->assertSame(0, User::count());
    }

    public function test_an_active_summary_changes_immediately_when_the_organization_is_suspended(): void
    {
        $organization = $this->organization();
        $token = $this->token($this->client());
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson($this->url((string) $organization->id), $headers)->assertJsonPath('status', 'active');
        $organization->update(['estado' => 'suspendida']);
        $this->getJson($this->url((string) $organization->id), $headers)->assertOk()->assertJsonPath('status', 'suspended');
    }

    public function test_missing_organizations_and_invalid_identifiers_return_404(): void
    {
        $token = $this->token($this->client());
        foreach (['000000000000000000000000', 'not-an-id', 'sistemas'] as $id) {
            $this->get($this->url($id), ['Authorization' => 'Bearer '.$token])->assertNotFound()->assertHeader('Content-Type', 'application/json');
        }
    }

    public function test_a_user_session_does_not_replace_a_service_token(): void
    {
        $organization = $this->organization();
        $this->get($this->url((string) $organization->id))->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->actingAs(User::factory()->create())
            ->getJson($this->url((string) $organization->id))->assertUnauthorized();
    }

    public function test_empty_scope_and_identity_scope_cannot_read_organization_summaries(): void
    {
        $organization = $this->organization();
        $client = $this->client(['students:read', self::SCOPE]);
        foreach (['', 'students:read'] as $scope) {
            $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$this->token($client, $scope)])
                ->assertForbidden();
        }
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        $organization = $this->organization();
        $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer invalid-token'])->assertUnauthorized();
        $token = $this->token($this->client());
        try {
            JWT::$timestamp = now()->timestamp + config('oauth.access_token_ttl') + 1;
            $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        } finally {
            JWT::$timestamp = null;
        }
    }

    public function test_disabling_or_deleting_a_client_revokes_an_already_issued_token(): void
    {
        $organization = $this->organization();
        $client = $this->client();
        $token = $this->token($client);
        $client->update(['active' => false]);
        $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $client->delete();
        $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_removing_the_client_scope_revokes_read_access_immediately(): void
    {
        $organization = $this->organization();
        $client = $this->client();
        $token = $this->token($client);
        $client->update(['scopes' => ['students:read']]);
        $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_summary_scope_does_not_allow_reading_identity_or_changing_organizations(): void
    {
        $organization = $this->organization();
        $token = $this->token($this->client());
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/students/000000000000000000000000/status', $headers)->assertForbidden();
        $this->postJson($this->url((string) $organization->id), [], $headers)->assertStatus(405);
        $this->assertSame('activa', $organization->fresh()->estado);
    }

    public function test_unknown_status_is_not_reported_as_active(): void
    {
        $organization = $this->organization('asociacion', 'unknown');
        $token = $this->token($this->client());
        $this->getJson($this->url((string) $organization->id), ['Authorization' => 'Bearer '.$token])->assertStatus(500);
    }

    public function test_summary_requests_are_rate_limited(): void
    {
        $organization = $this->organization();
        $token = $this->token($this->client());
        $headers = ['Authorization' => 'Bearer '.$token];
        for ($i = 0; $i < 120; $i++) {
            $this->getJson($this->url((string) $organization->id), $headers)->assertOk();
        }
        $this->get($this->url((string) $organization->id), $headers)->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Content-Type', 'application/json');
    }
}
