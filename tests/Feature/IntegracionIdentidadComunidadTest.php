<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegracionIdentidadComunidadTest extends TestCase
{
    public function test_consulta_solo_el_usuario_autenticado_por_oauth_http(): void
    {
        config(['identidad_comunidad' => ['url' => 'http://identity.test', 'client_id' => 'comunidad', 'client_secret' => 'secret']]);
        $user = User::factory()->create();
        Http::preventStrayRequests();
        Http::fake([
            'identity.test/api/oauth/token' => Http::response(['access_token' => 'token-demo']),
            'identity.test/api/v1/students/'.$user->id.'/status' => Http::response(['data' => ['student_id' => (string) $user->id, 'status' => 'active', 'benefits_eligible' => null]]),
        ]);
        $this->actingAs($user)->getJson('/api/identidad/mi-condicion?student_id=otro')
            ->assertOk()->assertJsonPath('data.student_id', (string) $user->id)->assertJsonPath('data.benefits_eligible', null);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.$user->id.'/status') && $request->hasHeader('Authorization', 'Bearer token-demo'));
        Http::assertSentCount(2);
    }

    public function test_no_inventa_estado_si_identidad_no_esta_disponible(): void
    {
        config(['identidad_comunidad' => ['url' => 'http://identity.test', 'client_id' => 'comunidad', 'client_secret' => 'secret']]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->create())->getJson('/api/identidad/mi-condicion')->assertStatus(503)->assertJsonMissingPath('data.status');
    }

    public function test_sesion_revocada_por_identidad_no_puede_seguir_usando_comunidad(): void
    {
        $user = User::factory()->create();
        $session = UserSession::create(['user_id' => (string) $user->id, 'revoked_at' => now()]);
        $this->actingAs($user)->withSession(['cd_session_id' => (string) $session->id])
            ->get('/modulo6/asociacion')->assertRedirect('/login');
        $this->assertGuest();
    }
    public function test_la_integracion_conserva_matriculas_unicas_y_admite_usuarios_sin_matricula(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $collection = \Illuminate\Support\Facades\DB::connection('mongodb')->getCollection('users');
        $collection->insertMany([
            ['email' => 'uno@example.com', 'matricula' => '20260001'],
            ['email' => 'sin-matricula@example.com'],
            ['email' => 'sin-matricula-dos@example.com'],
        ]);
        $this->expectException(\MongoDB\Driver\Exception\BulkWriteException::class);
        $collection->insertOne(['email' => 'dos@example.com', 'matricula' => '20260001']);
    }
}
