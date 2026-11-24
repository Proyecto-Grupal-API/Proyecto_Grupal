<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\AuditoriaComunidad;
use App\Models\AvisoGeneral;
use App\Models\Mensaje;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Petición del profesor (20-nov-2026): «Debe existir un usuario coordinador que pueda hacer un aviso general a todos los
 * usuarios». La autorización la da E1 como cargo exacto (rol `admin` en el contexto service:comunicaciones); no es el
 * administrador global ni un permiso de organización. El aviso llega a la bandeja interna de E6 de cada cuenta vigente;
 * no se envía correo ni SMS.
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $make = fn (array $a = []) => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false] + $a);
    $this->coordinator = $make(['name' => 'Coordinación de Comunicaciones']);
    $this->coordinator->assignRole(Role::ADMIN, 'service', 'comunicaciones');
    $this->global = $make();
    $this->global->assignRole(Role::ADMIN);
    $this->student = $make();
    $this->other = $make();
    $this->closed = $make();
    $this->closed->delete(); // baja lógica: no recibe avisos
});

function agDeliver($test): void
{
    for ($i = 0; $i < 10 && DB::table('jobs_comunidad')->count() > 0; $i++) {
        $test->artisan('queue:work', ['connection' => 'comunidad', '--queue' => 'comunidad', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
    }
}

test('the communications coordinator publishes a general notice that reaches every active account inbox', function () {
    $this->actingAs($this->coordinator)->getJson('/api/avisos-generales')->assertOk()->assertJsonPath('puede_publicar', true);
    $response = $this->postJson('/api/avisos-generales', ['asunto' => 'Suspensión de clases', 'cuerpo' => 'Mañana no habrá clases por mantenimiento.', 'confirmacion' => true])
        ->assertCreated();
    $id = $response->json('aviso.id');
    agDeliver($this);

    $aviso = AvisoGeneral::findOrFail($id);
    $activos = User::count();
    expect($aviso->estado)->toBe('enviado')->and($aviso->autor_id)->toBe((string) $this->coordinator->getKey())
        ->and($aviso->autor_nombre)->toBe('Coordinación de Comunicaciones')->and($aviso->publicado_en)->not->toBeNull()
        ->and($aviso->entregados)->toBe($activos);
    $mensajes = Mensaje::where('clave_idempotencia', 'aviso-general:'.$id)->get();
    expect($mensajes)->toHaveCount($activos)
        ->and($mensajes->pluck('usuario_id')->all())->not->toContain((string) $this->closed->getKey())
        ->and($mensajes->first()->tipo_aviso)->toBe('aviso_general')->and($mensajes->first()->categoria)->toBe('institucional');

    // Cada usuario lo ve en su bandeja, con autor y fecha.
    $this->actingAs($this->student)->getJson('/api/bandeja')->assertOk()
        ->assertJsonPath('mensajes.0.asunto', 'Suspensión de clases')->assertJsonPath('mensajes.0.emisor_nombre', 'Coordinación de Comunicaciones');
    // El coordinador consulta lo publicado.
    $this->actingAs($this->coordinator)->getJson('/api/avisos-generales')->assertOk()
        ->assertJsonPath('avisos.0.asunto', 'Suspensión de clases')->assertJsonPath('avisos.0.entregados', $activos);
    expect(AuditoriaComunidad::where('accion', 'aviso_general_publicado')->where('entidad_id', $id)->count())->toBe(1);

    // Reprocesar la entrega no duplica mensajes.
    app(\App\Services\EntregaAvisoGeneral::class)->lote($id);
    expect(Mensaje::where('clave_idempotencia', 'aviso-general:'.$id)->count())->toBe($activos);
});

test('accounts without the coordinator charge cannot publish general notices, including the global administrator', function () {
    foreach ([$this->student, $this->global] as $user) {
        $this->actingAs($user)->getJson('/api/avisos-generales')->assertOk()->assertJsonPath('puede_publicar', false)->assertJsonPath('avisos', []);
        $this->postJson('/api/avisos-generales', ['asunto' => 'Intento', 'cuerpo' => 'No autorizado.', 'confirmacion' => true])->assertForbidden();
    }
    $this->actingAs($this->student)->get('/modulo6/avisos-generales')->assertForbidden();
    expect(AvisoGeneral::count())->toBe(0)->and(Mensaje::where('tipo_aviso', 'aviso_general')->count())->toBe(0);
});

test('a general notice requires subject, body and explicit confirmation', function () {
    $this->actingAs($this->coordinator)->postJson('/api/avisos-generales', ['asunto' => '', 'cuerpo' => 'x', 'confirmacion' => true])->assertUnprocessable();
    $this->postJson('/api/avisos-generales', ['asunto' => 'Aviso', 'cuerpo' => 'Texto suficiente.'])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
    expect(AvisoGeneral::count())->toBe(0);
});
