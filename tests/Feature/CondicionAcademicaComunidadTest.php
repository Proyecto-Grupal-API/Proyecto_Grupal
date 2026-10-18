<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CondicionAcademicaComunidadTest extends TestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create();
        config(['identidad_comunidad' => ['url' => 'http://identity.test', 'client_id' => 'comunidad', 'client_secret' => 'secret']]);
        Http::preventStrayRequests();
    }

    private function estado(): array
    {
        return ['student_id' => (string) $this->usuario->id, 'user_id' => (string) $this->usuario->id,
            'name' => 'Nombre desde Identidad', 'enrollment' => '20260001', 'program' => 'Ingeniería en Sistemas',
            'semester' => 6, 'campus' => 'Campus principal', 'status' => 'active', 'status_label' => 'Activo',
            'effective_from' => null, 'status_reason' => null, 'restrictions' => null, 'benefits_eligible' => null];
    }

    private function respuesta(array $datos, int $status = 200): void
    {
        Http::fake(['identity.test/api/oauth/token' => Http::response(['access_token' => 'token-comunidad']),
            'identity.test/api/v1/students/*/status' => Http::response(['data' => $datos], $status)]);
    }

    public function test_no_expone_el_perfil_de_otro_usuario_ni_usa_el_nombre_local_como_respaldo(): void
    {
        $otro = (string) new ObjectId;
        $this->respuesta([...$this->estado(), 'student_id' => $otro, 'user_id' => $otro]);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion')
            ->assertStatus(503)->assertJsonMissingPath('data')->assertDontSee('Nombre desde Identidad');
    }

    #[DataProvider('respuestasInvalidas')]
    public function test_rechaza_respuestas_incompatibles_sin_inventar_una_condicion(array $cambios): void
    {
        $this->respuesta([...$this->estado(), ...$cambios]);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion')
            ->assertStatus(503)->assertJsonMissingPath('data');
    }

    public static function respuestasInvalidas(): array
    {
        return [
            'student_id no canónico' => [['student_id' => 'perfil-en-lugar-de-usuario']],
            'user_id inconsistente' => [['user_id' => 'otra-cuenta']],
            'identificador ausente' => [['user_id' => null]],
            'estado desconocido' => [['status' => 'enabled']],
            'estado ausente' => [['status' => null]],
            'nombre compuesto' => [['name' => ['private' => 'dato']]],
            'campus como objeto' => [['campus' => ['id' => 'interno']]],
            'semestre como etiqueta' => [['semester' => 'sexto']],
            'elegibilidad no publicada' => [['benefits_eligible' => true]],
            'restricciones no publicadas' => [['restrictions' => ['becas']]],
        ];
    }

    #[DataProvider('estadosAcademicos')]
    public function test_conserva_la_condicion_publicada_sin_convertirla_en_elegibilidad(string $estado): void
    {
        $datos = [...$this->estado(), 'status' => $estado];
        $this->respuesta($datos);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion?student_id=otra-cuenta')
            ->assertOk()->assertExactJson(['data' => $datos])->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($r) => $r->url() === 'http://identity.test/api/v1/students/'.$this->usuario->id.'/status'
            && $r->hasHeader('Authorization', 'Bearer token-comunidad'));
        Http::assertSentCount(2);
    }

    public static function estadosAcademicos(): array
    {
        return array_map(fn ($estado) => [$estado], ['active', 'inactive', 'suspended', 'restricted', 'leave', 'graduated']);
    }

    public function test_proyecta_solo_el_contrato_academico_y_no_reexpone_campos_adicionales(): void
    {
        $datos = $this->estado();
        $this->respuesta([...$datos, 'history' => [['actor_id' => 'dato-interno']], 'personal_email' => 'privado@example.test',
            'campus_id' => 'no-publicado', 'permissions' => ['admin']]);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion')
            ->assertOk()->assertExactJson(['data' => $datos]);
    }

    public function test_perfil_no_encontrado_no_se_presenta_como_cuenta_inexistente(): void
    {
        $this->respuesta([], 404);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion')->assertNotFound()
            ->assertJsonPath('message', 'No tienes un perfil académico registrado en Identidad.')->assertJsonMissingPath('data');
    }

    public function test_un_fallo_de_red_no_consulta_colecciones_de_identidad_como_respaldo(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->actingAs($this->usuario)->getJson('/api/identidad/mi-condicion')->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_una_sesion_ausente_no_inicia_consultas_a_identidad(): void
    {
        Http::fake();
        $this->getJson('/api/identidad/mi-condicion')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
