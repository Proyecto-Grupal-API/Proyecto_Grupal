<?php

namespace Tests\Feature;

use App\Models\Mensaje;
use App\Models\PreferenciaBandeja;
use App\Models\ServiceClient;
use App\Models\User;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class AvisosServiciosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $alumno;
    private ServiceClient $cliente;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alumno = User::factory()->create();
        $this->cliente = ServiceClient::create(['name' => 'Servicios', 'client_id' => 'equipo5', 'secret_hash' => Hash::make('test-secret'),
            'scopes' => ['notifications:send', 'notifications:read'], 'active' => true]);
        $this->token = app(OAuthTokenService::class)->issue('equipo5', 'test-secret', ['notifications:send', 'notifications:read'])['access_token'];
        config(['identidad_comunidad.url' => 'https://identidad.test', 'identidad_comunidad.client_id' => 'comunidad', 'identidad_comunidad.client_secret' => 'test']);
        Http::preventStrayRequests();
        Http::fake(['https://identidad.test/api/oauth/token' => Http::response(['access_token' => 'identity-test']),
            'https://identidad.test/api/v1/students/*/status' => Http::response(['data' => ['user_id' => (string) $this->alumno->id, 'status' => 'active']])]);
    }

    private function datos(string $tipo = 'orden_lista', string $destino = 'mis_ordenes'): array
    {
        return ['destinatario_id' => (string) $this->alumno->id, 'tipo' => $tipo, 'asunto' => 'Tu orden está lista',
            'texto' => 'Recoge tu impresión en Servicios.', 'accion' => ['destino' => $destino, 'id' => 'orden-123'], 'importancia' => 'normal'];
    }

    private function enviar(array $datos, string $clave = 'orden:123:lista')
    {
        return $this->withToken($this->token)->withHeader('Idempotency-Key', $clave)->postJson('/api/v1/comunidad/notificaciones', $datos);
    }

    public function test_reintentar_tras_perder_respuesta_no_duplica_y_consulta_devuelve_recibo(): void
    {
        $primero = $this->enviar($this->datos())->assertCreated()->assertJsonPath('estado', 'entregada')->json();
        $this->enviar(array_reverse($this->datos(), true))->assertOk()->assertExactJson($primero);
        $this->withToken($this->token)->getJson('/api/v1/comunidad/notificaciones?clave_idempotencia=orden:123:lista')->assertOk()->assertExactJson($primero);
        $this->assertSame(1, Mensaje::count());
        $this->assertSame('/servicios-estudiante/servicios-impresiones?registro=orden-123', Mensaje::first()->accion_url);
        Http::assertSentCount(2); // Only the first send consults Identity.
        $this->enviar([...$this->datos(), 'texto' => 'Contenido cambiado'])->assertConflict()->assertJsonPath('codigo', 'clave_en_conflicto');
    }

    public function test_preferencia_omite_opcionales_pero_no_obligatorios_y_replay_no_cambia(): void
    {
        $this->actingAs($this->alumno)->putJson('/api/bandeja/categorias/servicios_estudiante', ['silenciada' => true])->assertOk();
        $omitida = $this->enviar($this->datos())->assertCreated()->assertJsonPath('estado', 'omitida')->json();
        $this->enviar($this->datos('multa_generada', 'mis_multas'), 'multa:1')->assertCreated()->assertJsonPath('estado', 'entregada');
        $this->actingAs($this->alumno)->getJson('/api/bandeja')->assertJsonCount(1, 'mensajes')->assertJsonPath('no_leidos', 1);
        $this->getJson('/api/notificaciones')->assertJsonCount(1)->assertHeader('X-Unread-Count', '1');
        PreferenciaBandeja::query()->update(['silenciada' => false]);
        $this->enviar($this->datos())->assertOk()->assertExactJson($omitida);
    }

    public function test_avisos_y_preferencias_solo_pertenecen_a_su_usuario(): void
    {
        $id = $this->enviar($this->datos())->assertCreated()->json('notificacion_id');
        $otro = User::factory()->create();
        $this->actingAs($otro)->getJson('/api/bandeja')->assertJsonCount(0, 'mensajes');
        $this->getJson('/api/bandeja/'.$id)->assertNotFound();
        $this->putJson('/api/bandeja/'.$id, ['leida' => true])->assertNotFound();
        $this->putJson('/api/bandeja/categorias/servicios_estudiante', ['silenciada' => true, 'usuario_id' => (string) $this->alumno->id])->assertOk();
        $this->assertFalse(PreferenciaBandeja::where('usuario_id', (string) $this->alumno->id)->exists());
    }

    public function test_retirar_preserva_lectura_y_su_clave_tambien_es_idempotente(): void
    {
        $id = $this->enviar($this->datos())->assertCreated()->json('notificacion_id');
        $this->actingAs($this->alumno)->putJson('/api/bandeja/'.$id, ['leida' => true])->assertOk();
        $antes = Mensaje::findOrFail($id)->primera_lectura_en->toISOString();
        $url = '/api/v1/comunidad/notificaciones/'.$id.'/retiro';
        $this->withToken($this->token)->withHeader('Idempotency-Key', 'retiro:1')->postJson($url, ['motivo' => 'Orden cancelada'])->assertOk()->assertJsonPath('estado', 'retirada');
        $this->postJson($url, ['motivo' => 'Orden cancelada'])->assertOk();
        $this->postJson($url, ['motivo' => 'Otro motivo'])->assertConflict();
        $this->assertSame($antes, Mensaje::findOrFail($id)->primera_lectura_en->toISOString());
        $this->actingAs($this->alumno)->getJson('/api/bandeja')->assertJsonCount(0, 'mensajes');
        $this->getJson('/api/notificaciones')->assertHeader('X-Unread-Count', '0');
        $this->enviar($this->datos())->assertOk()->assertJsonPath('notificacion_id', $id);
        $this->assertSame(1, Mensaje::count());
    }

    public function test_cliente_no_puede_consultar_ni_retirar_avisos_ajenos(): void
    {
        $id = $this->enviar($this->datos())->assertCreated()->json('notificacion_id');
        ServiceClient::create(['name' => 'Otro', 'client_id' => 'otro', 'secret_hash' => Hash::make('secret'), 'active' => true, 'scopes' => ['notifications:send', 'notifications:read']]);
        $token = app(OAuthTokenService::class)->issue('otro', 'secret', ['notifications:send', 'notifications:read'])['access_token'];
        $this->withToken($token)->getJson('/api/v1/comunidad/notificaciones?clave_idempotencia=orden:123:lista')->assertNotFound();
        $this->withHeader('Idempotency-Key', 'retiro:otro')->postJson('/api/v1/comunidad/notificaciones/'.$id.'/retiro', ['motivo' => 'Prueba'])->assertNotFound();
    }

    public function test_token_scope_y_revocacion_vigente_protegen_servicio(): void
    {
        $this->postJson('/api/v1/comunidad/notificaciones', $this->datos())->assertUnauthorized()->assertJsonStructure(['codigo', 'mensaje', 'correlacion_id']);
        $read = app(OAuthTokenService::class)->issue('equipo5', 'test-secret', ['notifications:read'])['access_token'];
        $this->withToken($read)->postJson('/api/v1/comunidad/notificaciones', $this->datos())->assertForbidden();
        $this->cliente->update(['scopes' => ['notifications:read']]);
        $this->enviar($this->datos())->assertForbidden();
        $this->cliente->update(['active' => false]);
        $this->withToken($this->token)->getJson('/api/v1/comunidad/notificaciones?clave_idempotencia=abc')->assertUnauthorized();
    }

    public function test_rechaza_html_urls_tipo_destino_incorrecto_y_fecha_sin_zona(): void
    {
        foreach ([['texto' => '<script>alert(1)</script>'], ['texto' => ['contenido']], ['asunto' => ['titulo']],
            ['accion_url' => 'https://evil.test'], ['tipo' => 'arbitrario'],
            ['accion' => ['destino' => 'mi_ticket', 'id' => '123']], ['expira_en' => '2027-01-01 12:00:00']] as $cambio) {
            $this->enviar([...$this->datos(), ...$cambio])->assertUnprocessable()->assertJsonStructure(['codigo', 'mensaje', 'correlacion_id']);
        }
        $this->assertSame(0, Mensaje::count());
    }

    public function test_aviso_expirado_desaparece_pero_recibo_se_conserva(): void
    {
        $datos = [...$this->datos(), 'expira_en' => now()->addMinute()->toIso8601String()];
        $recibo = $this->enviar($datos)->assertCreated()->json();
        $this->travel(2)->minutes();
        $this->actingAs($this->alumno)->getJson('/api/bandeja')->assertJsonCount(0, 'mensajes');
        $this->enviar($datos)->assertOk()->assertExactJson($recibo);
    }

    public function test_fallo_identidad_no_guarda_recibo_y_puede_reintentarse(): void
    {
        config(['identidad_comunidad.url' => 'https://fallo.test']);
        Http::fake(['https://fallo.test/*' => Http::response([], 503)]);
        $this->enviar($this->datos())->assertServiceUnavailable()->assertJsonPath('codigo', 'identidad_no_disponible');
        $this->assertSame(0, Mensaje::count());
        $this->withToken($this->token)->getJson('/api/v1/comunidad/notificaciones?clave_idempotencia=orden:123:lista')->assertNotFound();
    }
    public function test_limite_de_solicitudes_se_aplica_por_cliente_y_devuelve_error_estable(): void
    {
        $this->enviar($this->datos())->assertCreated();
        for ($i = 1; $i < 120; $i++) { $this->enviar($this->datos())->assertOk(); }
        $this->enviar($this->datos())->assertStatus(429)->assertJsonPath('codigo', 'limite_excedido')->assertHeader('Retry-After');
        $this->assertSame(1, Mensaje::count());
    }

    public function test_panel_no_cuenta_avisos_omitidos_retirados_o_expirados(): void
    {
        $org = \App\Models\Organizacion::create(['slug' => 'avisos-panel', 'nombre' => 'Comunidad avisos', 'tipo' => 'asociacion', 'estado' => 'activa']);
        \App\Models\MiembroOrganizacion::create(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $this->alumno->id, 'estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
        foreach ([[], ['estado_entrega' => 'omitida'], ['retirada_en' => now()], ['expira_en' => now()->subMinute()]] as $extra) {
            Mensaje::create(['usuario_id' => (string) $this->alumno->id, 'asunto' => 'Prueba de visibilidad', 'cuerpo' => 'Prueba', ...$extra]);
        }
        $this->actingAs($this->alumno)->withHeader('X-Organization-Id', (string) $org->id)->getJson('/api/dashboard')->assertOk()->assertJsonPath('personales.no_leidos', 1);
    }

}
