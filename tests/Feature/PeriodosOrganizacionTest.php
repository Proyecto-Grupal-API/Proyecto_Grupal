<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\PeriodoOrganizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class PeriodosOrganizacionTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $integrante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->integrante = User::where('email', 'estudiante@campus.test')->firstOrFail();
    }

    private function datos(array $extra = []): array
    {
        return [...['nombre' => 'Mesa directiva actual', 'descripcion' => 'Periodo administrativo de la asociación.', 'fecha_inicio' => today()->startOfYear()->toDateString(), 'fecha_fin' => today()->endOfYear()->toDateString(), 'clave_solicitud' => (string) Str::uuid()], ...$extra];
    }

    private function crear(array $extra = []): array
    {
        return $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', $this->datos($extra))->assertCreated()->json('periodo');
    }

    public function test_presidency_registers_a_term_and_members_only_read_their_organization(): void
    {
        $p = $this->crear();
        $this->assertSame('vigente', $p['situacion']);
        $this->assertFalse($p['en_uso']);
        $this->assertArrayNotHasKey('huella', $p);
        $this->assertArrayNotHasKey('clave_solicitud', $p);
        $this->actingAs($this->integrante)->getJson('/api/organizaciones/periodos')->assertOk()->assertJsonCount(1, 'periodos')->assertJsonPath('puede_editar', false);
        $this->getJson('/api/organizaciones/periodos/'.$p['id'])->assertOk()->assertJsonCount(0, 'cargos');
        $this->postJson('/api/organizaciones/periodos', $this->datos())->assertForbidden();
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$this->datos(), 'version' => 1])->assertForbidden();
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', ['version' => 1, 'motivo' => 'Intento de cancelar sin autorización.'])->assertForbidden();
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/organizaciones/periodos/'.$p['id'])->assertNotFound();
    }

    public function test_dates_are_required_and_overlapping_days_are_rejected_per_organization(): void
    {
        $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', $this->datos(['fecha_fin' => '']))->assertUnprocessable();
        $this->postJson('/api/organizaciones/periodos', $this->datos(['fecha_fin' => today()->subYears(2)->toDateString()]))->assertUnprocessable();
        $p = $this->crear();
        $this->postJson('/api/organizaciones/periodos', $this->datos(['nombre' => 'Otro periodo superpuesto']))->assertUnprocessable();
        $this->postJson('/api/organizaciones/periodos', $this->datos(['nombre' => 'Comparte el último día', 'fecha_inicio' => $p['fecha_fin'], 'fecha_fin' => today()->addYear()->endOfYear()->toDateString()]))->assertUnprocessable();
        $this->crear(['nombre' => 'Mesa del siguiente año', 'fecha_inicio' => today()->addYear()->startOfYear()->toDateString(), 'fecha_fin' => today()->addYear()->endOfYear()->toDateString()]);
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->postJson('/api/organizaciones/periodos', $this->datos())->assertCreated();
        $this->assertSame(3, PeriodoOrganizacion::count());
    }

    public function test_create_retries_keep_one_record_and_reject_different_content_or_actor(): void
    {
        $datos = $this->datos();
        $id = $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', $datos)->assertCreated()->json('periodo.id');
        $this->postJson('/api/organizaciones/periodos', $datos)->assertOk()->assertJsonPath('periodo.id', $id);
        $this->postJson('/api/organizaciones/periodos', [...$datos, 'nombre' => 'Nombre diferente'])->assertConflict();
        $this->assertSame(1, PeriodoOrganizacion::count());
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'periodo_creado')->count());
        $rol = RolOrganizacion::where('usuario_id', (string) $this->presidencia->id)->where('slug_rol', 'presidencia')->firstOrFail();
        $this->putJson('/api/organizaciones/roles/'.$rol->id, ['usuario_id' => (string) $this->integrante->id, 'slug_rol' => 'presidencia', 'fecha_inicio' => today()->toDateString(), 'fecha_fin' => today()->addYear()->toDateString()])->assertOk();
        $this->actingAs($this->integrante)->postJson('/api/organizaciones/periodos', $datos)->assertConflict();
    }

    public function test_updates_use_revision_and_recheck_overlaps(): void
    {
        $p = $this->crear();
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$this->datos(), 'nombre' => 'Mesa directiva corregida', 'version' => 1])->assertOk()->assertJsonPath('periodo.version', 2);
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$this->datos(), 'version' => 1])->assertConflict();
        $fin = today()->addYear()->endOfYear()->toDateString();
        $this->crear(['nombre' => 'Mesa futura', 'fecha_inicio' => today()->addYear()->startOfYear()->toDateString(), 'fecha_fin' => $fin]);
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$this->datos(), 'fecha_fin' => $fin, 'version' => 2])->assertUnprocessable();
    }

    public function test_cancellation_is_audited_idempotent_and_does_not_restore_on_create_retry(): void
    {
        $datos = $this->datos();
        $p = $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', $datos)->assertCreated()->json('periodo');
        $body = ['version' => 1, 'motivo' => 'Se registró el periodo por equivocación.'];
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', $body)->assertOk()->assertJsonPath('periodo.situacion', 'cancelado');
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', $body)->assertOk();
        $this->postJson('/api/organizaciones/periodos', $datos)->assertOk()->assertJsonPath('periodo.situacion', 'cancelado');
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'periodo_cancelado')->count());
        $this->getJson('/api/organizaciones/periodos?situacion=cancelado')->assertJsonCount(1, 'periodos');
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$datos, 'version' => 2])->assertUnprocessable();
        $this->crear(['nombre' => 'Periodo correcto']);
    }

    public function test_situations_are_calendar_based_and_context_cannot_cross_tabs(): void
    {
        $p = $this->crear();
        $this->crear(['nombre' => 'Periodo pasado', 'fecha_inicio' => today()->subYear()->startOfYear()->toDateString(), 'fecha_fin' => today()->subYear()->endOfYear()->toDateString()]);
        $this->crear(['nombre' => 'Periodo futuro', 'fecha_inicio' => today()->addYear()->startOfYear()->toDateString(), 'fecha_fin' => today()->addYear()->endOfYear()->toDateString()]);
        foreach (['vigente', 'finalizado', 'programado'] as $situacion) {
            $this->getJson('/api/organizaciones/periodos?situacion='.$situacion)->assertJsonCount(1, 'periodos')->assertJsonPath('periodos.0.situacion', $situacion);
        }
        $this->withHeader('X-Organization-Id', str_repeat('a', 24))->putJson('/api/organizaciones/periodos/'.$p['id'], [...$this->datos(), 'version' => 1])->assertConflict();
    }

    public function test_database_failure_rolls_back_the_term_and_audit(): void
    {
        $fallar = true;
        AuditoriaComunidad::creating(function () use (&$fallar) {
            if ($fallar) {
                $fallar = false;
                throw new \RuntimeException('Fallo de auditoría de periodo');
            }
        });
        $this->withoutExceptionHandling();
        $datos = $this->datos();
        try {
            $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', $datos);
            $this->fail('Debe propagarse el fallo de auditoría.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de auditoría de periodo', $e->getMessage());
        }
        $this->assertSame(0, PeriodoOrganizacion::count());
        $this->postJson('/api/organizaciones/periodos', $datos)->assertCreated();
    }
}
