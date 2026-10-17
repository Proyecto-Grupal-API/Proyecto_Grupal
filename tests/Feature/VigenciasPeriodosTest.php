<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\DelegacionOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class VigenciasPeriodosTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidencia;

    private User $integrante;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->presidencia = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->integrante = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function periodo(array $extra = []): array
    {
        return $this->actingAs($this->presidencia)->postJson('/api/organizaciones/periodos', [...['nombre' => 'Periodo de la mesa directiva', 'descripcion' => 'Periodo para comprobar las vigencias de sus cargos.', 'fecha_inicio' => today()->subMonth()->toDateString(), 'fecha_fin' => today()->addMonth()->toDateString(), 'clave_solicitud' => (string) Str::uuid()], ...$extra])->assertCreated()->json('periodo');
    }

    private function datos(array $p, array $extra = []): array
    {
        return [...['usuario_id' => (string) $this->integrante->id, 'slug_rol' => 'comunicacion', 'fecha_inicio' => today()->toDateString(), 'fecha_fin' => $p['fecha_fin'], 'periodo_id' => $p['id']], ...$extra];
    }

    private function cargo(): RolOrganizacion
    {
        return RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'comunicacion')->whereNull('eliminado_en')->firstOrFail();
    }

    public function test_linked_roles_require_a_finite_range_inside_the_term_and_normalize_the_reference(): void
    {
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/roles', $this->datos($p, ['fecha_fin' => null]))->assertUnprocessable();
        $this->postJson('/api/organizaciones/roles', $this->datos($p, ['fecha_inicio' => today()->subYears(2)->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/organizaciones/roles', $this->datos($p, ['fecha_fin' => today()->addYears(2)->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/organizaciones/roles', $this->datos($p, ['periodo_id' => strtoupper($p['id'])]))->assertCreated();
        $this->assertSame($p['id'], $this->cargo()->periodo_id);
        $this->getJson('/api/organizaciones/periodos/'.strtoupper($p['id']))->assertJsonCount(1, 'cargos')->assertJsonPath('periodo.en_uso', true);
        $this->getJson('/api/organizaciones')->assertJsonFragment(['periodo_id' => $p['id']])->assertJsonFragment(['nombre' => $p['nombre']]);
    }

    public function test_a_role_cannot_link_to_another_organization_or_a_cancelled_term(): void
    {
        $consejo = User::where('email', 'consejo@campus.test')->firstOrFail();
        $otro = $this->actingAs($consejo)->postJson('/api/organizaciones/periodos', ['nombre' => 'Periodo del Consejo', 'fecha_inicio' => today()->toDateString(), 'fecha_fin' => today()->addMonth()->toDateString(), 'clave_solicitud' => (string) Str::uuid()])->assertCreated()->json('periodo');
        $this->actingAs($this->presidencia)->postJson('/api/organizaciones/roles', $this->datos($otro))->assertNotFound();
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', ['version' => 1, 'motivo' => 'Periodo duplicado antes de asignar cargos.'])->assertOk();
        $this->postJson('/api/organizaciones/roles', $this->datos($p))->assertUnprocessable();
    }

    public function test_a_link_cannot_be_removed_or_moved_and_old_clients_preserve_it(): void
    {
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/roles', $this->datos($p))->assertCreated();
        $rol = $this->cargo();
        $otro = $this->periodo(['nombre' => 'Periodo siguiente', 'fecha_inicio' => today()->addYears(2)->toDateString(), 'fecha_fin' => today()->addYears(3)->toDateString()]);
        $this->putJson('/api/organizaciones/roles/'.$rol->id, $this->datos($otro, ['fecha_inicio' => $otro['fecha_inicio']]))->assertUnprocessable();
        $this->putJson('/api/organizaciones/roles/'.$rol->id, $this->datos($p, ['periodo_id' => null]))->assertUnprocessable();
        $datos = $this->datos($p, ['usuario_id' => (string) $this->presidencia->id]);
        unset($datos['periodo_id']);
        $this->putJson('/api/organizaciones/roles/'.$rol->id, $datos)->assertOk();
        $this->assertSame($p['id'], $rol->fresh()->periodo_id);
    }

    public function test_used_term_dates_and_cancellation_are_protected_even_after_withdrawal(): void
    {
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/roles', $this->datos($p))->assertCreated();
        $meta = ['nombre' => 'Nombre del periodo corregido', 'descripcion' => $p['descripcion'], 'fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'], 'version' => 1];
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], $meta)->assertOk()->assertJsonPath('periodo.en_uso', true);
        $this->putJson('/api/organizaciones/periodos/'.$p['id'], [...$meta, 'version' => 2, 'fecha_fin' => today()->addYear()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', ['version' => 2, 'motivo' => 'Intento de cancelar un periodo utilizado.'])->assertUnprocessable();
        $this->deleteJson('/api/organizaciones/roles/'.$this->cargo()->id)->assertOk();
        $this->postJson('/api/organizaciones/periodos/'.$p['id'].'/cancelar', ['version' => 2, 'motivo' => 'Intento de descartar cargos históricos.'])->assertUnprocessable();
        $this->getJson('/api/organizaciones/periodos/'.$p['id'])->assertJsonCount(1, 'cargos')->assertJsonPath('cargos.0.vigente', false);
    }

    public function test_linked_permissions_end_after_the_last_inclusive_day(): void
    {
        $p = $this->periodo(['fecha_inicio' => today()->toDateString(), 'fecha_fin' => today()->toDateString()]);
        $this->postJson('/api/organizaciones/roles', $this->datos($p))->assertCreated();
        $this->travelTo(today()->endOfDay());
        $this->assertTrue(Gate::forUser($this->integrante)->allows('comunicar', $this->org));
        $this->travel(1)->seconds();
        $this->assertFalse(Gate::forUser($this->integrante)->allows('comunicar', $this->org));
        $this->getJson('/api/organizaciones/periodos/'.$p['id'])->assertJsonPath('periodo.situacion', 'finalizado');
    }

    public function test_term_history_only_exposes_role_events_of_its_organization(): void
    {
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/roles', $this->datos($p))->assertCreated();
        $rol = $this->cargo();
        $this->putJson('/api/organizaciones/roles/'.$rol->id, $this->datos($p, ['usuario_id' => (string) $this->presidencia->id]))->assertOk();
        $this->deleteJson('/api/organizaciones/roles/'.$rol->id)->assertOk();
        AuditoriaComunidad::create(['organizacion_id' => (string) $this->org->id, 'accion' => 'organizacion_documento_creado', 'despues' => ['periodo_id' => $p['id'], 'titulo' => 'DOCUMENTO-PRIVADO-OCULTO']]);
        AuditoriaComunidad::create(['organizacion_id' => (string) Organizacion::where('slug', 'consejo')->firstOrFail()->id, 'accion' => 'cargo_asignado', 'despues' => ['periodo_id' => $p['id'], 'usuario_id' => 'USUARIO-DE-OTRA-ORGANIZACION']]);
        $this->actingAs($this->integrante)->getJson('/api/organizaciones/periodos/'.strtoupper($p['id']).'/historial')->assertOk()->assertJsonCount(3, 'movimientos')->assertDontSee('DOCUMENTO-PRIVADO-OCULTO')->assertDontSee('USUARIO-DE-OTRA-ORGANIZACION');
        $this->actingAs(User::where('email', 'consejo@campus.test')->firstOrFail())->getJson('/api/organizaciones/periodos/'.$p['id'].'/historial')->assertNotFound();
    }

    public function test_recovery_preserves_the_previous_term_assignment_and_revokes_delegations(): void
    {
        $p = $this->periodo(['fecha_inicio' => today()->toDateString(), 'fecha_fin' => today()->addDays(2)->toDateString()]);
        $rol = RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->firstOrFail();
        $this->putJson('/api/organizaciones/roles/'.$rol->id, $this->datos($p, ['usuario_id' => (string) $this->presidencia->id, 'slug_rol' => 'presidencia']))->assertOk();
        $this->postJson('/api/organizaciones/delegaciones', ['destinatario_id' => (string) $this->integrante->id, 'capacidades' => ['comunicacion.gestionar'], 'inicio' => now()->timezone('America/Mexico_City')->addMinute()->format('Y-m-d\TH:i'), 'fin' => now()->timezone('America/Mexico_City')->addDay()->format('Y-m-d\TH:i'), 'motivo' => 'Suplencia que depende de la presidencia original.', 'clave_idempotencia' => (string) Str::uuid()])->assertCreated();
        $this->travel(4)->days();
        $this->artisan('comunidad:recuperar-presidencia', ['organizacion' => 'sistemas', 'matricula' => '20260002', '--hasta' => today()->addYear()->toDateString(), '--motivo' => 'Recuperación autorizada después del vencimiento.', '--operador' => 'Responsable local de pruebas'])->assertExitCode(0);
        $this->assertNotNull($rol->fresh()->eliminado_en);
        $this->assertSame((string) $this->presidencia->id, $rol->fresh()->usuario_id);
        $actual = RolOrganizacion::vigentes()->where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->firstOrFail();
        $this->assertNotSame((string) $rol->id, (string) $actual->id);
        $this->assertNull($actual->periodo_id);
        $this->assertNotNull(DelegacionOrganizacion::firstOrFail()->revocada_en);
        $this->assertTrue(Gate::forUser($this->integrante)->allows('update', $this->org));
        $this->actingAs($this->integrante)->getJson('/api/organizaciones/periodos/'.$p['id'])->assertJsonCount(1, 'cargos')->assertJsonPath('cargos.0.usuario_id', (string) $this->presidencia->id);
    }

    public function test_unlinked_assignments_remain_compatible_and_failed_linking_does_not_leave_history(): void
    {
        $p = $this->periodo();
        $this->postJson('/api/organizaciones/roles', ['usuario_id' => (string) $this->integrante->id, 'slug_rol' => 'secretaria', 'fecha_inicio' => today()->toDateString()])->assertCreated();
        $this->assertNull(RolOrganizacion::where('slug_rol', 'secretaria')->firstOrFail()->periodo_id);
        $fallar = true;
        AuditoriaComunidad::creating(function ($a) use (&$fallar) {
            if ($fallar && $a->accion === 'cargo_asignado') {
                $fallar = false;
                throw new \RuntimeException('Fallo al registrar el vínculo del cargo');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/organizaciones/roles', $this->datos($p));
            $this->fail('Debe fallar la escritura de auditoría.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo al registrar el vínculo del cargo', $e->getMessage());
        }
        $this->assertSame(0, RolOrganizacion::where('periodo_id', $p['id'])->count());
        $this->getJson('/api/organizaciones/periodos/'.$p['id'])->assertJsonPath('periodo.en_uso', false);
    }
}
