<?php

namespace Tests\Feature;

use App\Actions\Users\DeleteUserAccount;
use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\ResponsabilidadesComunidad;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class IntegracionFinalIdentidadComunidadTest extends TestCase
{
    use RefreshMongoDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_la_politica_de_2fa_del_equipo_1_tambien_protege_comunidad(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::ADMIN);
        $this->actingAs($user)->get('/modulo6/asociacion')->assertRedirectToRoute('two-factor.enrollment');
        $this->getJson('/api/organizaciones')->assertStatus(423)->assertJsonStructure(['enrollment_url']);
        $this->get('/security/two-factor-enrollment')->assertOk();
    }

    public function test_una_contrasena_temporal_se_cambia_antes_de_acceder_a_comunidad(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => true])->save();
        $user->assignRole(Role::ADMIN);
        $this->actingAs($user)->get('/modulo6/becas')->assertRedirectToRoute('password.initial.edit');
        $this->getJson('/api/organizaciones')->assertStatus(423)->assertJsonPath('code', 'initial_password_change_required');
    }

    public function test_la_navegacion_comparte_asignaciones_efectivas_y_oculta_roles_historicos(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['roles' => [['name' => Role::ADMIN, 'scope_type' => null, 'scope_id' => null]]])->save();
        $this->actingAs($user)->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('auth.user.effective_roles', 0)->missing('auth.user.roles')->where('auth.hasStudentProfile', false));
        $this->get('/students')->assertForbidden();
    }

    public function test_un_rol_contextual_de_identidad_no_crea_presidencia_ni_membership(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::CONSEJO_ESTUDIANTIL, 'association', 'organizacion-ajena');
        $org = Organizacion::create(['slug' => 'aislada', 'nombre' => 'Organización aislada', 'tipo' => 'asociacion', 'estado' => 'activa']);
        $this->assertFalse(Gate::forUser($user)->allows('update', $org));
        $this->assertSame(0, MiembroOrganizacion::count());
        $this->assertSame(0, RolOrganizacion::count());
    }

    public function test_al_vencer_un_rol_desaparece_de_la_navegacion_y_de_la_autoridad(): void
    {
        $user = User::factory()->create();
        RoleAssignment::create(['user_id' => (string) $user->id, 'role_key' => Role::ADMIN,
            'scope_type' => null, 'scope_id' => null, 'status' => 'active',
            'origin' => 'legacy_role_management', 'ends_at' => now()->addMinute()]);
        $this->travel(2)->minutes();
        $this->actingAs($user)->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->has('auth.user.effective_roles', 0));
        $this->get('/students')->assertForbidden();
        $this->get('/modulo6/asociacion')->assertOk();
    }

    public function test_un_error_de_identidad_revierte_la_baja_de_responsabilidades_y_auditoria(): void
    {
        $user = User::factory()->create();
        $org = Organizacion::create(['slug' => 'rollback', 'nombre' => 'Organización rollback', 'tipo' => 'asociacion', 'estado' => 'activa']);
        $member = MiembroOrganizacion::create(['usuario_id' => (string) $user->id, 'organizacion_id' => (string) $org->id, 'estado' => 'activo', 'fecha_inicio' => today()]);
        try {
            app(ResponsabilidadesComunidad::class)->eliminarCuenta($user, function () use ($user) {
                app(DeleteUserAccount::class)->execute($user);
                throw new \RuntimeException('Fallo deliberado después de cerrar la identidad.');
            });
            $this->fail('La operación debía revertirse.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo deliberado después de cerrar la identidad.', $e->getMessage());
        }
        $this->assertNotNull(User::find($user->id));
        $this->assertNull($member->fresh()->eliminado_en);
        $this->assertSame('activo', $member->fresh()->estado);
        $this->assertSame(0, AuditoriaComunidad::count());
    }
}
