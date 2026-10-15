<?php

namespace Tests\Feature;

use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Mensaje;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\SolicitudBeca;
use App\Models\User;
use App\Services\EntregaCampanas;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class PermisosComunidadTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $persona;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->persona = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function cargo(string $slug): RolOrganizacion
    {
        return RolOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->persona->id, 'slug_rol' => $slug, 'fecha_inicio' => now()->subDay()]);
    }

    public function test_comunicacion_can_send_but_cannot_administer_the_organization_or_scholarships(): void
    {
        $this->cargo('comunicacion');
        $id = $this->actingAs($this->persona)->postJson('/api/comunicacion', ['nombre' => 'Aviso', 'asunto' => 'Aviso de la comunidad', 'cuerpo' => 'Una campaña creada por el responsable de comunicación.', 'audiencia' => 'miembros', 'accion' => 'ninguna'])->assertCreated()->json('campana.id');
        $firma = $this->postJson("/api/comunicacion/$id/preview")->assertOk()->json('firma');
        $this->postJson("/api/comunicacion/$id/enviar", ['firma' => $firma])->assertStatus(202);
        $c = Campana::findOrFail($id);
        app(EntregaCampanas::class)->lote($id, $c->ejecucion);
        $this->assertSame('enviada', $c->fresh()->estado);
        $this->assertGreaterThan(0, Mensaje::count());
        $this->assertFalse(Gate::forUser($this->persona)->allows('update', $this->org));
        $this->getJson('/api/becas?gestion=1')->assertForbidden();
        $this->withHeader('X-Organization-Id', (string) Organizacion::where('slug', 'consejo')->firstOrFail()->id)->postJson("/api/comunicacion/$id/preview")->assertConflict();
    }

    public function test_cargo_permission_expires_and_requires_active_membership_and_organization(): void
    {
        $cargo = $this->cargo('comunicacion');
        $this->assertTrue(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $cargo->update(['fecha_fin' => now()->subDay()]);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        $cargo->update(['fecha_fin' => null]);
        MiembroOrganizacion::where('usuario_id', (string) $this->persona->id)->where('organizacion_id', (string) $this->org->id)->update(['estado' => 'inactivo']);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
        MiembroOrganizacion::where('usuario_id', (string) $this->persona->id)->update(['estado' => 'activo']);
        $this->org->update(['estado' => 'suspendida']);
        $this->assertFalse(Gate::forUser($this->persona)->allows('comunicar', $this->org));
    }

    public function test_scholarship_reviewer_sees_submitted_applications_but_cannot_change_calls_or_review_their_own(): void
    {
        $this->cargo('responsable_becas');
        $c = ConvocatoriaBeca::create(['organizacion_id' => (string) $this->org->id, 'titulo' => 'Beca', 'estado' => 'en_revision', 'fecha_fin' => now()->subDay()]);
        $otra = User::where('email', 'andrea@campus.test')->firstOrFail();
        $enviada = SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $c->id, 'usuario_id' => (string) $otra->id, 'folio' => 'BECA-ENVIADA', 'estado' => 'pendiente', 'enviado_en' => now(), 'documentos' => []]);
        $borrador = SolicitudBeca::create(['organizacion_id' => (string) $this->org->id, 'convocatoria_id' => (string) $c->id, 'usuario_id' => (string) $this->persona->id, 'folio' => 'BECA-PROPIA', 'estado' => 'pendiente', 'enviado_en' => now(), 'documentos' => []]);
        $this->actingAs($this->persona)->getJson('/api/becas?gestion=1')->assertOk()->assertJsonPath('puede_gestionar', false)->assertJsonPath('puede_revisar', true);
        $this->getJson("/api/becas/$c->id/solicitudes")->assertOk()->assertJsonCount(2, 'solicitudes');
        $this->postJson("/api/becas/$c->id/estado", ['estado' => 'finalizada'])->assertForbidden();
        $this->postJson("/api/becas/solicitudes/$enviada->id/dictamen", ['estado' => 'rechazada', 'motivo' => 'La documentación no cumple los requisitos.'])->assertOk();
        $this->postJson("/api/becas/solicitudes/$borrador->id/dictamen", ['estado' => 'rechazada', 'motivo' => 'No puedo dictaminar mi propia solicitud.'])->assertUnprocessable();
        $this->getJson('/api/comunicacion')->assertForbidden();
        $enviada->update(['estado' => 'borrador', 'enviado_en' => null]);
        $this->getJson("/api/becas/solicitudes/$enviada->id")->assertNotFound();
        $this->withSession(['organizacion_id' => (string) Organizacion::where('slug', 'consejo')->firstOrFail()->id])->getJson('/api/becas?gestion=1')->assertForbidden();
    }
}
