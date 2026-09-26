<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RespuestaEncuesta;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Models\Voto;
use App\Services\ConsultasComunidad;
use Database\Seeders\ParticipacionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ConsultasTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $admin;

    private User $alumno;

    private User $andrea;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->admin = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->andrea = User::where('email', 'andrea@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['titulo' => 'Prioridades de la comunidad', 'descripcion' => 'Consulta de prueba sobre actividades de la comunidad.', 'fecha_inicio' => now()->subMinute()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'fecha_fin' => now()->addDay()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'preguntas' => [['titulo' => '¿Qué actividad prefieres?', 'opciones' => ['Taller de programación', 'Conferencia']]]], ...$extra];
    }

    private function crear(string $tipo = 'encuestas', array $extra = [])
    {
        $id = $this->actingAs($this->admin)->postJson('/api/consultas/'.$tipo, $this->datos($extra))->assertCreated()->json('consulta.id');

        return app(ConsultasComunidad::class)->consulta($tipo)->findOrFail($id);
    }

    private function publicar($c, string $tipo = 'encuestas'): void
    {
        $this->actingAs($this->admin);
        $firma = $this->postJson("/api/consultas/$tipo/$c->id/preview")->assertOk()->json('firma');
        $this->postJson("/api/consultas/$tipo/$c->id/publicar", ['firma' => $firma])->assertOk();
        $c->refresh();
    }

    private function respuesta($c, int $opcion = 0): array
    {
        return ['revision' => $c->revision, 'respuestas' => array_map(fn ($p) => ['pregunta_id' => $p['id'], 'opcion_id' => $p['opciones'][$opcion]['id']], $c->preguntas)];
    }

    public function test_scope_and_draft_privacy_for_both_types(): void
    {
        $this->getJson('/api/consultas/encuestas')->assertUnauthorized();
        foreach (['encuestas', 'votaciones'] as $tipo) {
            $c = $this->crear($tipo);
            $this->actingAs($this->alumno)->getJson('/api/consultas/'.$tipo)->assertJsonCount(0, 'consultas');
            $this->getJson("/api/consultas/$tipo/$c->id")->assertNotFound();
            $this->postJson('/api/consultas/'.$tipo, $this->datos())->assertForbidden();
            $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson("/api/consultas/$tipo/$c->id")->assertNotFound();
            $this->postJson("/api/consultas/$tipo/$c->id/preview")->assertNotFound();
            $this->publicar($c, $tipo);
            $this->actingAs($this->alumno)->getJson('/api/consultas/'.$tipo)->assertJsonCount(1, 'consultas')->assertJsonPath('consultas.0.puede_participar', true);
            $this->getJson("/api/consultas/$tipo/$c->id")->assertOk()->assertJsonMissingPath('consulta.padron')->assertJsonMissingPath('consulta.usuario_id')->assertJsonPath('resultados', null);
            $this->actingAs($this->andrea)->getJson("/api/consultas/$tipo/$c->id")->assertForbidden();
        }
    }

    public function test_shape_options_period_and_single_election_question_are_validated(): void
    {
        $this->actingAs($this->admin);
        foreach ([['titulo' => ''], ['fecha_fin' => $this->datos()['fecha_inicio']], ['fecha_inicio' => '2026-01-01'], ['preguntas' => []], ['preguntas' => [['titulo' => 'Opciones', 'opciones' => ['Igual', ' igual ']]]], ['preguntas' => [['titulo' => 'Opciones', 'opciones' => ['Una']]]], ['preguntas' => ['clave' => $this->datos()['preguntas'][0]]]] as $extra) {
            $this->postJson('/api/consultas/encuestas', $this->datos($extra))->assertUnprocessable();
        }
        $preguntas = [$this->datos()['preguntas'][0], ['titulo' => 'Otra pregunta', 'opciones' => ['Sí', 'No']]];
        $this->postJson('/api/consultas/votaciones', $this->datos(['preguntas' => $preguntas]))->assertUnprocessable();
        $this->crear('encuestas', ['preguntas' => $preguntas, 'estado' => 'cerrada', 'padron' => [(string) $this->andrea->id]])->refresh();
        $this->assertSame('borrador', Encuesta::first()->estado);
        $this->assertNull(Encuesta::first()->padron);
    }

    public function test_edit_revision_and_preview_are_invalidated_by_content_or_membership_changes(): void
    {
        $c = $this->crear();
        $path = "/api/consultas/encuestas/$c->id";
        $firma = $this->postJson($path.'/preview')->assertJsonPath('total_padron', 2)->json('firma');
        $this->putJson($path, $this->datos(['revision' => 1, 'titulo' => 'Título actualizado']))->assertOk();
        $this->putJson($path, $this->datos(['revision' => 1]))->assertConflict();
        $this->postJson($path.'/publicar', ['firma' => $firma])->assertConflict();
        $firma = $this->postJson($path.'/preview')->json('firma');
        MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->andrea->id, 'estado' => 'activo', 'fecha_inicio' => now()]);
        $this->postJson($path.'/publicar', ['firma' => $firma])->assertConflict();
        $this->publicar($c);
        $this->putJson($path, $this->datos(['revision' => 2]))->assertUnprocessable();
    }

    public function test_padron_is_fixed_new_members_excluded_and_revocation_prevents_participation(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        MiembroOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->andrea->id, 'estado' => 'activo', 'fecha_inicio' => now()]);
        $this->actingAs($this->andrea)->getJson('/api/consultas/votaciones')->assertJsonCount(0, 'consultas');
        $this->postJson("/api/consultas/votaciones/$c->id/responder", $this->respuesta($c))->assertNotFound();
        MiembroOrganizacion::where('organizacion_id', (string) $this->org->id)->where('usuario_id', (string) $this->alumno->id)->update(['estado' => 'inactivo']);
        $this->actingAs($this->alumno)->postJson("/api/consultas/votaciones/$c->id/responder", $this->respuesta($c))->assertNotFound();
        $this->assertSame(0, Voto::count());
        $this->assertSame(2, $c->total_padron);
    }

    public function test_each_vote_is_immutable_and_retries_never_duplicate_even_after_close(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        $path = "/api/consultas/votaciones/$c->id";
        $this->actingAs($this->alumno)->postJson($path.'/responder', [...$this->respuesta($c), 'usuario_id' => (string) $this->admin->id])->assertCreated();
        $this->postJson($path.'/responder', $this->respuesta($c, 1))->assertOk();
        $this->assertSame(1, Voto::count());
        $this->assertSame((string) $this->alumno->id, Voto::first()->usuario_id);
        $this->assertSame($this->respuesta($c)['respuestas'], Voto::first()->respuestas);
        $this->travelTo($c->fecha_fin);
        $this->postJson($path.'/responder', $this->respuesta($c, 1))->assertOk();
        $this->getJson($path)->assertJsonPath('resultados.total', 1)->assertJsonPath('resultados.preguntas.0.opciones.0.cantidad', 1);
        $this->assertSame(1, Voto::count());
    }

    public function test_survey_requires_all_questions_and_valid_option_ownership(): void
    {
        $c = $this->crear('encuestas', ['preguntas' => [$this->datos()['preguntas'][0], ['titulo' => '¿Horario?', 'opciones' => ['Mañana', 'Tarde']]]]);
        $this->publicar($c);
        $path = "/api/consultas/encuestas/$c->id/responder";
        $d = $this->respuesta($c);
        $this->actingAs($this->alumno)->postJson($path, [...$d, 'revision' => 99])->assertConflict();
        $this->postJson($path, [...$d, 'respuestas' => [$d['respuestas'][0]]])->assertUnprocessable();
        $this->postJson($path, [...$d, 'respuestas' => [$d['respuestas'][0], $d['respuestas'][0]]])->assertUnprocessable();
        $bad = $d;
        $bad['respuestas'][0]['opcion_id'] = $d['respuestas'][1]['opcion_id'];
        $this->postJson($path, $bad)->assertUnprocessable();
        $bad = $d;
        $bad['respuestas'][0]['pregunta_id'] = (string) Str::uuid();
        $this->postJson($path, $bad)->assertUnprocessable();
        $this->assertSame(0, RespuestaEncuesta::count());
        $this->postJson($path, $d)->assertCreated();
        $this->assertSame(2, count(RespuestaEncuesta::first()->respuestas));
    }

    public function test_start_is_inclusive_end_exclusive_and_results_are_hidden_from_everyone_until_close(): void
    {
        $start = now()->addHour();
        $end = now()->addHours(2);
        $c = $this->crear('votaciones', ['fecha_inicio' => $start->copy()->timezone('America/Mexico_City')->format('Y-m-d\TH:i'), 'fecha_fin' => $end->copy()->timezone('America/Mexico_City')->format('Y-m-d\TH:i')]);
        $this->publicar($c, 'votaciones');
        $path = "/api/consultas/votaciones/$c->id";
        $this->postJson($path.'/responder', $this->respuesta($c))->assertUnprocessable();
        $this->postJson($path.'/cerrar', ['motivo' => 'Antes de iniciar.'])->assertUnprocessable();
        $this->getJson($path)->assertJsonPath('consulta.fase', 'programada')->assertJsonPath('resultados', null);
        $this->travelTo($start);
        $this->postJson($path.'/responder', $this->respuesta($c))->assertCreated();
        $this->getJson($path)->assertJsonPath('resultados', null);
        $this->getJson('/api/transparencia')->assertJsonPath('eleccion', null)->assertJsonPath('totalVotos', 0);
        $this->travelTo($end);
        $this->actingAs($this->alumno)->postJson($path.'/responder', $this->respuesta($c))->assertUnprocessable();
        $this->getJson($path)->assertJsonPath('consulta.fase', 'cerrada')->assertJsonPath('resultados.total', 1);
        $this->getJson('/api/transparencia')->assertJsonPath('totalVotos', 1)->assertJsonMissingPath('eleccion.padron')->assertJsonMissingPath('eleccion.preguntas');
    }

    public function test_cancel_preserves_votes_but_never_publishes_results_or_reopens(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        $path = "/api/consultas/votaciones/$c->id";
        $this->actingAs($this->alumno)->postJson($path.'/responder', $this->respuesta($c))->assertCreated();
        $this->actingAs($this->admin)->postJson($path.'/cancelar', ['motivo' => 'Proceso cancelado para la prueba.'])->assertOk();
        $this->postJson($path.'/responder', $this->respuesta($c))->assertUnprocessable();
        $this->postJson($path.'/cerrar', ['motivo' => 'Intentar reabrir el proceso.'])->assertUnprocessable();
        $this->getJson($path)->assertJsonPath('resultados', null)->assertJsonPath('consulta.fase', 'cancelada');
        $this->getJson('/api/transparencia')->assertJsonPath('eleccion', null);
        $this->assertSame(1, Voto::count());
    }

    public function test_early_close_publishes_tie_without_assigning_roles_or_exposing_individual_responses(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        $path = "/api/consultas/votaciones/$c->id";
        $this->postJson($path.'/responder', $this->respuesta($c))->assertCreated();
        $this->actingAs($this->alumno)->postJson($path.'/responder', $this->respuesta($c, 1))->assertCreated();
        $this->postJson($path.'/cerrar', ['motivo' => 'No tengo permisos de presidencia.'])->assertForbidden();
        $this->actingAs($this->admin)->postJson($path.'/cerrar', ['motivo' => 'Cierre anticipado de demostración.'])->assertOk();
        $out = $this->getJson($path)->assertJsonPath('resultados.total', 2)->assertJsonPath('resultados.preguntas.0.opciones.0.porcentaje', 50)->assertJsonPath('resultados.preguntas.0.opciones.1.porcentaje', 50)->json();
        $this->assertStringNotContainsString((string) $this->alumno->id, json_encode($out));
        $this->assertStringNotContainsString('opcion_id', AuditoriaComunidad::all()->toJson());
        $this->assertSame(2, RolOrganizacion::count());
        $this->postJson($path.'/cancelar', ['motivo' => 'Los resultados ya son visibles.'])->assertUnprocessable();
    }

    public function test_database_unique_constraint_is_the_last_line_of_defence(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        $this->postJson("/api/consultas/votaciones/$c->id/responder", $this->respuesta($c))->assertCreated();
        try {
            Voto::create(['consulta_id' => (string) $c->id, 'organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->admin->id, 'respuestas' => $this->respuesta($c, 1)['respuestas']]);
            $this->fail('Se permitió un voto duplicado.');
        } catch (BulkWriteException $e) {
            $this->assertSame(11000, $e->getCode());
        }
        $this->assertSame(1, Voto::count());
    }

    public function test_expired_role_and_inactive_org_block_management_and_participation(): void
    {
        $c = $this->crear();
        $this->publicar($c);
        RolOrganizacion::where('organizacion_id', (string) $this->org->id)->update(['fecha_fin' => now()->subDay()]);
        $this->postJson("/api/consultas/encuestas/$c->id/cerrar", ['motivo' => 'Cargo que ya expiró.'])->assertForbidden();
        $this->org->update(['estado' => 'inactiva']);
        $this->postJson("/api/consultas/encuestas/$c->id/responder", $this->respuesta($c))->assertForbidden();
    }

    public function test_empty_results_and_pagination_do_not_expose_padron(): void
    {
        $c = $this->crear();
        $this->publicar($c);
        $this->travelTo($c->fecha_fin);
        $this->getJson("/api/consultas/encuestas/$c->id")->assertJsonPath('resultados.total', 0)->assertJsonPath('resultados.preguntas.0.opciones.0.porcentaje', 0)->assertJsonMissingPath('consulta.padron');
        for ($i = 0; $i < 12; $i++) {
            $this->crear('encuestas', ['titulo' => 'Filtro '.$i]);
        }
        $this->getJson('/api/consultas/encuestas')->assertJsonCount(12, 'consultas')->assertJsonPath('last_page', 2);
        $this->getJson('/api/consultas/encuestas?buscar=Filtro')->assertJsonCount(12, 'consultas')->assertJsonPath('last_page', 1);
    }

    public function test_demo_seeder_preserves_manual_edits_and_never_creates_votes(): void
    {
        $this->seed(ParticipacionSeeder::class);
        $e = Eleccion::where('referencia_demo', 'propuestas-sistemas')->firstOrFail();
        $e->update(['titulo' => 'Título personalizado']);
        $this->seed(ParticipacionSeeder::class);
        $this->assertSame(2, Eleccion::count());
        $this->assertSame(2, Encuesta::count());
        $this->assertSame('Título personalizado', $e->fresh()->titulo);
        $this->assertSame(0, Voto::count());
        $this->assertSame(0, RespuestaEncuesta::count());
    }

    public function test_stale_organization_header_cannot_publish_or_vote(): void
    {
        $c = $this->crear('votaciones');
        $this->publicar($c, 'votaciones');
        $this->withHeader('X-Organization-Id', '000000000000000000000000')->postJson("/api/consultas/votaciones/$c->id/responder", $this->respuesta($c))->assertConflict();
        $this->assertSame(0, Voto::count());
    }

    public function test_native_arrays_and_migration_preserve_existing_demonstration_data(): void
    {
        $c = $this->crear();
        $this->publicar($c);
        $this->assertIsArray($c->getRawOriginal('padron'));
        $this->assertIsArray($c->getRawOriginal('preguntas'));
        $padron = $c->padron;
        $db = DB::connection('mongodb');
        $db->table('encuestas')->where('_id', $c->id)->update(['padron' => json_encode($padron), 'preguntas' => json_encode($c->preguntas)]);
        $migration = require database_path('migrations/2026_09_21_000100_normalize_participacion_arrays.php');
        $migration->up();
        $migration->up();
        $this->assertSame($padron, $c->fresh()->padron);
        $this->actingAs($this->alumno)->getJson('/api/consultas/encuestas')->assertJsonCount(1, 'consultas');
        $this->postJson("/api/consultas/encuestas/$c->id/responder", $this->respuesta($c))->assertCreated();
        $this->assertIsArray(RespuestaEncuesta::first()->getRawOriginal('respuestas'));
    }
}
