<?php

namespace Tests\Feature;

use App\Models\{Mensaje,ServiceClient,User};
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\{Hash,Http};
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class FinancialCommunityInboxTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = User::factory()->create();
        config(['identidad_comunidad' => ['url' => 'https://identity.test', 'client_id' => 'identity-test', 'client_secret' => 'fixture'],
            'comunidad.avisos_finanzas_client_id' => 'financial-notice-test']);
        foreach (['financial-notice-test', 'services-notice-test'] as $client) {
            ServiceClient::create(['client_id' => $client, 'name' => $client, 'secret_hash' => Hash::make('fixture'),
                'active' => true, 'scopes' => ['notifications:send', 'notifications:read']]);
        }
        Http::preventStrayRequests();
        Http::fake(['identity.test/api/oauth/token' => Http::response(['access_token' => 'fixture-token']),
            'identity.test/api/v1/students/*/status' => Http::response(['data' => ['student_id' => (string) $this->student->id,
                'user_id' => (string) $this->student->id, 'status' => 'active', 'restrictions' => null, 'benefits_eligible' => null]])]);
    }

    private function payload(): array
    {
        return ['destinatario_id' => (string) $this->student->id, 'tipo' => 'pago_evento_confirmado',
            'asunto' => 'Pago confirmado', 'texto' => 'Se confirmó tu pago. Consulta su comprobante.',
            'accion' => ['destino' => 'comprobante_financiero', 'id' => (string) Str::uuid()]];
    }

    private function token(string $client): string
    {
        return app(OAuthTokenService::class)->issue($client, 'fixture', ['notifications:send', 'notifications:read'])['access_token'];
    }

    public function test_financial_notice_is_private_recoverable_and_uses_a_canonical_receipt_link(): void
    {
        $data = $this->payload(); $key = 'financial-community:event:'.Str::uuid();
        $this->withToken($this->token('financial-notice-test'))->withHeader('Idempotency-Key', $key);
        $receipt = $this->postJson('/api/v1/comunidad/notificaciones', $data)->assertCreated()->json();
        $this->postJson('/api/v1/comunidad/notificaciones', $data)->assertOk()->assertExactJson($receipt);
        $this->getJson('/api/v1/comunidad/notificaciones?clave_idempotencia='.$key)->assertOk()->assertExactJson($receipt);
        $message = Mensaje::sole();
        $this->assertSame('finanzas', $message->categoria);
        $this->assertSame('Finanzas', $message->emisor_nombre);
        $this->assertSame('/finanzas/comprobantes/transaction/'.$data['accion']['id'], $message->accion_url);
        $this->assertSame(1, Mensaje::count());
        $this->actingAs($this->student)->getJson('/api/v1/comunidad/portal/bandeja')->assertOk()->assertJsonPath('mensajes.0.id', (string) $message->id);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/comunidad/portal/bandeja/'.$message->id)->assertNotFound();
    }

    public function test_services_client_cannot_impersonate_finance_and_finance_cannot_impersonate_services(): void
    {
        $this->withToken($this->token('services-notice-test'))->withHeader('Idempotency-Key', 'wrong-service')
            ->postJson('/api/v1/comunidad/notificaciones', $this->payload())->assertUnprocessable();
        $data = $this->payload();$data['tipo'] = 'orden_lista';$data['accion'] = ['destino' => 'mis_ordenes', 'id' => 'order-1'];
        $this->withToken($this->token('financial-notice-test'))->postJson('/api/v1/comunidad/notificaciones', $data)->assertUnprocessable();
        $this->assertSame(0, Mensaje::count());Http::assertNothingSent();
    }

    public function test_unknown_financial_client_configuration_and_arbitrary_links_fail_closed(): void
    {
        config(['comunidad.avisos_finanzas_client_id' => null]);
        $this->withToken($this->token('financial-notice-test'))->withHeader('Idempotency-Key', 'unconfigured')
            ->postJson('/api/v1/comunidad/notificaciones', $this->payload())->assertUnprocessable();
        config(['comunidad.avisos_finanzas_client_id' => 'financial-notice-test']);
        $data = $this->payload();$data['accion']['id'] = 'https-example-com';
        $this->postJson('/api/v1/comunidad/notificaciones', $data)->assertUnprocessable();
        $this->assertSame(0, Mensaje::count());Http::assertNothingSent();
    }

    public function test_bonus_and_refund_notices_use_separate_fixed_destinations(): void
    {
        $this->withToken($this->token('financial-notice-test'));
        foreach (['beca_emitida' => 'bono_financiero', 'devolucion_confirmada' => 'comprobante_financiero'] as $type => $destination) {
            $data = $this->payload();$data['tipo'] = $type;$data['accion']['destino'] = $destination;
            $this->withHeader('Idempotency-Key', $type)->postJson('/api/v1/comunidad/notificaciones', $data)->assertCreated();
        }
        $this->assertSame(2, Mensaje::where('categoria','finanzas')->count());
    }
}
