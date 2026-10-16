<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\ComisionConsejo;
use App\Models\IntegranteComision;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\User;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ComisionesConsejoTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $persona;

    private Organizacion $consejo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->presidencia = User::where('email', 'consejo@campus.test')->firstOrFail();
        $this->persona = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->consejo = Organizacion::where('slug', 'consejo')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        $hoy = now()->timezone('America/Mexico_City');

        return [...['nombre' => 'Comisión de cultura', 'proposito' => 'Organizar las actividades culturales del Consejo.', 'fecha_inicio' => $hoy->toDateString(), 'fecha_fin' => $hoy->addMonth()->toDateString(), 'clave_solicitud' => (string) Str::uuid()], ...$extra];
    }

    private function crear(array $extra = []): string
    {
        return $this->actingAs($this->presidencia)->postJson('/api/organizaciones/comisiones', $this->datos($extra))->assertCreated()->json('comision.id');
    }

    private function asignar(string $id, string $funcion = 'integrante', ?User $persona = null): string
    {
        return $this->actingAs($this->presidencia)->postJson("/api/organizaciones/comisiones/$id/integrantes", ['usuario_id' => (string) ($persona ?? $this->persona)->id, 'funcion' => $funcion, 'clave_solicitud' => (string) Str::uuid()])->assertCreated()->json('integrante.id');
    }

    public function test_only_council_presidency_manages_and_members_can_read_their_own_commissions(): void
    {
        $this->getJson('/api/organizaciones/comisiones')->assertUnauthorized();
        $id = $this->crear();
        $this->actingAs($this->persona)->withSession(['organizacion_id' => (string) $this->consejo->id])->getJson('/api/organizaciones/comisiones')->assertOk()->assertJsonCount(1, 'comisiones')->assertJsonPath('puede_editar', false);
        $this->postJson('/api/organizaciones/comisiones', $this->datos())->assertForbidden();
        $this->actingAs(User::where('email', 'presidencia@campus.test')->firstOrFail())->getJson("/api/organizaciones/comisiones/$id")->assertNotFound();
        $this->postJson('/api/organizaciones/comisiones', $this->datos())->assertNotFound();
    }

    public function test_creation_is_idempotent_names_are_unique_and_stale_edits_are_rejected(): void
    {
        $d = $this->datos();
        $id = $this->actingAs($this->presidencia)->postJson('/api/organizaciones/comisiones', $d)->assertCreated()->json('comision.id');
        $this->postJson('/api/organizaciones/comisiones', $d)->assertOk()->assertJsonPath('comision.id', $id);
        $this->postJson('/api/organizaciones/comisiones', [...$d, 'nombre' => 'Otro nombre'])->assertConflict();
        $this->postJson('/api/organizaciones/comisiones', $this->datos(['nombre' => 'COMISIÓN DE CULTURA']))->assertUnprocessable();
        $this->putJson("/api/organizaciones/comisiones/$id", [...$d, 'proposito' => 'Un propósito actualizado y autorizado.', 'version' => 1])->assertOk()->assertJsonPath('comision.version', 2);
        $this->putJson("/api/organizaciones/comisiones/$id", [...$d, 'version' => 1])->assertConflict();
        $this->assertSame(1, ComisionConsejo::count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'comision_creada')->count());
    }

    public function test_coordination_requires_membership_is_unique_and_does_not_grant_permissions(): void
    {
        $id = $this->crear();
        $asignacion = $this->asignar($id, 'coordinacion');
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", ['usuario_id' => (string) $this->presidencia->id, 'funcion' => 'coordinacion', 'clave_solicitud' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", ['usuario_id' => (string) User::where('email', 'andrea@campus.test')->firstOrFail()->id, 'funcion' => 'integrante', 'clave_solicitud' => (string) Str::uuid()])->assertUnprocessable();
        foreach (['update', 'comunicar', 'gestionarBecas', 'revisarBecas'] as $capacidad) {
            $this->assertFalse(Gate::forUser($this->persona)->allows($capacidad, $this->consejo));
        }
        $this->actingAs($this->persona)->withSession(['organizacion_id' => (string) $this->consejo->id])->postJson("/api/organizaciones/comisiones/$id/integrantes/$asignacion/retirar", ['motivo' => 'No puedo administrar esta comisión.'])->assertForbidden();
    }

    public function test_assignment_retry_and_withdrawal_keep_history_without_reviving_previous_records(): void
    {
        $id = $this->crear();
        $d = ['usuario_id' => (string) $this->persona->id, 'funcion' => 'integrante', 'clave_solicitud' => (string) Str::uuid()];
        $a = $this->postJson("/api/organizaciones/comisiones/$id/integrantes", $d)->assertCreated()->json('integrante.id');
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", $d)->assertOk()->assertJsonPath('integrante.id', $a);
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", [...$d, 'funcion' => 'coordinacion'])->assertConflict();
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes/$a/retirar", ['motivo' => 'Finaliza esta participación en la comisión.'])->assertOk();
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes/$a/retirar", ['motivo' => 'Reintento de la misma operación de retiro.'])->assertOk();
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", $d)->assertOk();
        $this->assertNotNull(IntegranteComision::findOrFail($a)->retirado_en);
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'comision_integrante_retirado')->count());
        $nuevo = $this->asignar($id);
        $this->assertNotSame($a, $nuevo);
        $this->getJson("/api/organizaciones/comisiones/$id?historial=1")->assertJsonCount(2, 'integrantes');
    }

    public function test_periods_and_closure_prevent_new_assignments_and_preserve_history(): void
    {
        $dia = fn ($n) => now()->timezone('America/Mexico_City')->addDays($n)->toDateString();
        $id = $this->crear(['fecha_inicio' => $dia(1), 'fecha_fin' => $dia(5)]);
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", ['usuario_id' => (string) $this->persona->id, 'funcion' => 'integrante', 'clave_solicitud' => (string) Str::uuid()])->assertUnprocessable();
        $this->travelTo(now()->addDay());
        $a = $this->asignar($id);
        $this->postJson("/api/organizaciones/comisiones/$id/cerrar", ['version' => 1, 'motivo' => 'La comisión terminó sus actividades previstas.'])->assertOk();
        $this->postJson("/api/organizaciones/comisiones/$id/cerrar", ['version' => 1, 'motivo' => 'Reintento del cierre ya realizado.'])->assertOk();
        $this->assertNotNull(IntegranteComision::findOrFail($a)->retirado_en);
        $this->getJson('/api/organizaciones/comisiones?estado=cerrada')->assertJsonCount(1, 'comisiones');
        $this->postJson("/api/organizaciones/comisiones/$id/integrantes", ['usuario_id' => (string) $this->persona->id, 'funcion' => 'integrante', 'clave_solicitud' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_organization_membership_removal_does_not_revive_commission_assignment_on_rejoining(): void
    {
        $id = $this->crear();
        $a = $this->asignar($id);
        $m = MiembroOrganizacion::where('organizacion_id', (string) $this->consejo->id)->where('usuario_id', (string) $this->persona->id)->firstOrFail();
        $this->deleteJson("/api/organizaciones/miembros/$m->id")->assertOk();
        $this->postJson('/api/organizaciones/miembros', ['matricula' => $this->persona->matricula, 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->assertNotNull(IntegranteComision::findOrFail($a)->retirado_en);
        $this->getJson("/api/organizaciones/comisiones/$id")->assertJsonCount(0, 'integrantes');
    }

    public function test_failed_account_deletion_rolls_back_commission_withdrawal(): void
    {
        $id = $this->crear();
        $a = $this->asignar($id);
        try {
            app(ResponsabilidadesComunidad::class)->eliminarCuenta($this->persona, fn () => throw new \RuntimeException('Fallo de identidad'));
            $this->fail('Debe propagarse el fallo.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de identidad', $e->getMessage());
        }
        $this->assertNull(IntegranteComision::findOrFail($a)->retirado_en);
        app(ResponsabilidadesComunidad::class)->eliminarCuenta($this->persona, fn () => $this->persona->delete());
        $this->assertNotNull(IntegranteComision::findOrFail($a)->retirado_en);
    }
}
