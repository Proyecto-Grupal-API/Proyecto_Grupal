<?php

use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\Role;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Models\UserSession;
use App\Services\CouncilBecaDesignationService;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Str;

test('a designated council issuer opens the BECA panel without generic financial authority and loses it on revocation', function () {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    Role::create(['name' => 'bonus_beca_council_issuer', 'display_name' => 'Emisor BECA']);
    $president = User::factory()->create(['account_activation_pending' => false, 'must_change_password' => false]);
    $issuer = withConfirmedTestTwoFactor(User::factory()->create([
        'account_activation_pending' => false, 'must_change_password' => false,
    ]));
    $council = Organizacion::create(['nombre' => 'Consejo de prueba', 'slug' => 'consejo-prueba', 'tipo' => 'consejo', 'estado' => 'activa']);
    MiembroOrganizacion::create(['organizacion_id' => (string) $council->id, 'usuario_id' => (string) $president->id,
        'estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
    RolOrganizacion::create(['organizacion_id' => (string) $council->id, 'usuario_id' => (string) $president->id,
        'slug_rol' => 'presidencia', 'fecha_inicio' => now()->subDay()]);
    config(['becas_finanzas.enabled' => true, 'becas_finanzas.convocatorias' => [
        (string) Str::ulid() => ['organization_id' => (string) $council->id,
            'budget_id' => strtolower((string) Str::uuid()), 'business_id' => 'BUS-TEST'],
    ]]);
    $assignment = app(CouncilBecaDesignationService::class)->assign($president, $issuer, (string) $council->id, 'Prueba del panel');
    $session = UserSession::create(['user_id' => (string) $issuer->id, 'started_at' => now()]);
    $this->actingAs($issuer)->withSession(['cd_session_id' => (string) $session->id])
        ->get('/finanzas/bonos/administracion')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Financial/BonusAdministration')
            ->where('communityBecaEnabled', true)->where('permissions.issue', false));

    app(CouncilBecaDesignationService::class)->revoke($president, $assignment, (string) $council->id, 'Cierre de prueba');
    $this->get('/finanzas/bonos/administracion')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('communityBecaEnabled', false)->where('permissions.issue', false));
});
