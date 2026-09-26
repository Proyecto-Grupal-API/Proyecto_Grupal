<?php

namespace Tests\Feature;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\StaffEvento;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ContinuidadComunidadTest extends TestCase
{
    use RefreshMongoDatabase;

    private User $presidente;

    private User $estudiante;

    private Organizacion $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->presidente = User::where('email', 'presidencia@campus.test')->firstOrFail();
        $this->estudiante = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->org = Organizacion::where('slug', 'sistemas')->firstOrFail();
    }

    private function cargo(): RolOrganizacion
    {
        return RolOrganizacion::where('organizacion_id', (string) $this->org->id)->where('slug_rol', 'presidencia')->firstOrFail();
    }

    private function opciones(array $extra = []): array
    {
        return [...['organizacion' => 'sistemas', 'matricula' => '20260002', '--hasta' => today()->addYear()->toDateString(), '--motivo' => 'Cambio autorizado tras vencer el periodo.', '--operador' => 'Responsable local de pruebas'], ...$extra];
    }

    public function test_account_deletion_cannot_orphan_current_future_or_expired_presidency(): void
    {
        foreach ([['fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->addMonth()], ['fecha_inicio' => today()->addDay(), 'fecha_fin' => today()->addMonth()], ['fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->subDay()]] as $periodo) {
            $this->cargo()->update($periodo);
            $this->actingAs($this->presidente)->from('/profile')->delete('/profile', ['password' => 'CampusDemo2026!'])->assertRedirect('/profile')->assertSessionHasErrors('password');
            $this->assertAuthenticatedAs($this->presidente);
            $this->assertNotNull($this->presidente->fresh());
            $this->assertNull($this->cargo()->eliminado_en);
        }
    }

    public function test_regular_account_deletion_retires_memberships_roles_and_staff_and_preserves_history(): void
    {
        $rol = RolOrganizacion::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->estudiante->id, 'slug_rol' => 'comunicacion', 'fecha_inicio' => today()]);
        $staff = StaffEvento::create(['organizacion_id' => (string) $this->org->id, 'usuario_id' => (string) $this->estudiante->id, 'evento_id' => 'evento-prueba']);
        $this->actingAs($this->estudiante)->delete('/profile', ['password' => 'CampusDemo2026!'])->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertNull($this->estudiante->fresh());
        $this->assertFalse(MiembroOrganizacion::activos()->where('usuario_id', (string) $this->estudiante->id)->exists());
        $this->assertSame(2, MiembroOrganizacion::where('usuario_id', (string) $this->estudiante->id)->count());
        $this->assertNotNull($rol->fresh()->eliminado_en);
        $this->assertNotNull($staff->fresh()->eliminado_en);
        $this->assertSame(4, AuditoriaComunidad::where('accion', 'cuenta_baja_responsabilidad')->count());
    }

    public function test_cannot_assign_a_role_to_a_deleted_user_with_orphan_membership(): void
    {
        $id = (string) $this->estudiante->id;
        $this->estudiante->delete();
        $this->actingAs($this->presidente)->postJson('/api/organizaciones/roles', ['usuario_id' => $id, 'slug_rol' => 'tesoreria', 'fecha_inicio' => today()->toDateString()])->assertUnprocessable();
        $this->assertFalse(RolOrganizacion::where('usuario_id', $id)->exists());
    }

    public function test_console_recovery_restores_authority_after_expiry_and_keeps_audit(): void
    {
        $this->cargo()->update(['fecha_fin' => today()->subDay()]);
        $this->assertFalse(Gate::forUser($this->presidente)->allows('update', $this->org));
        $this->artisan('comunidad:recuperar-presidencia', $this->opciones())->assertSuccessful();
        $this->assertTrue(Gate::forUser($this->estudiante)->allows('update', $this->org));
        $this->assertFalse(Gate::forUser($this->presidente)->allows('update', $this->org));
        $audit = AuditoriaComunidad::where('accion', 'presidencia_recuperada')->firstOrFail();
        $this->assertNull($audit->usuario_id);
        $this->assertSame((string) $this->presidente->id, $audit->antes['usuario_id']);
        $this->assertSame('consola', $audit->despues['canal']);
        $this->assertSame($this->opciones()['--motivo'], $audit->despues['motivo']);
        $this->artisan('comunidad:recuperar-presidencia', $this->opciones())->assertFailed();
        $this->assertSame(1, AuditoriaComunidad::where('accion', 'presidencia_recuperada')->count());
    }

    public function test_recovery_does_not_replace_current_or_future_authority_and_requires_valid_member_and_reason(): void
    {
        $this->artisan('comunidad:recuperar-presidencia', $this->opciones())->assertFailed();
        $this->cargo()->update(['fecha_inicio' => today()->addDay(), 'fecha_fin' => today()->addMonth()]);
        $this->artisan('comunidad:recuperar-presidencia', $this->opciones())->assertFailed();
        $this->cargo()->update(['fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->subDay()]);
        foreach ([['--motivo' => ''], ['--hasta' => today()->subDay()->toDateString()], ['--operador' => ''], ['matricula' => '20260004'], ['matricula' => 'inexistente'], ['organizacion' => 'otra']] as $invalido) {
            $this->artisan('comunidad:recuperar-presidencia', $this->opciones($invalido))->assertFailed();
        }
        $this->assertSame(0, AuditoriaComunidad::where('accion', 'presidencia_recuperada')->count());
        $this->assertSame((string) $this->presidente->id, $this->cargo()->usuario_id);
    }

    public function test_console_can_repair_an_already_orphaned_presidency(): void
    {
        $this->presidente->delete();
        $this->artisan('comunidad:recuperar-presidencia', $this->opciones())->assertSuccessful();
        $this->assertTrue(Gate::forUser($this->estudiante)->allows('update', $this->org));
    }
}
