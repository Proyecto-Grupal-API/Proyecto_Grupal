<?php

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\NfcCard;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\SecurityEvent;
use App\Models\ServiceClient;
use App\Models\StaffEvento;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('checks NFC through genuine E1 OAuth HTTP and stops accepting a blocked credential', function () {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $staff = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $student = User::factory()->create(['name' => 'NFC HTTP real']);
    StudentProfile::create(['user_id' => (string) $student->id, 'enrollment_number' => 'NFC-HTTP-TEST', 'academic_status' => 'active']);
    $card = NfcCard::create(['user_id' => (string) $student->id, 'uid' => 'TEST-LOCAL-NFC', 'status' => 'active', 'registered_at' => now()]);
    ServiceClient::create(['name' => 'Test-only Comunidad NFC', 'client_id' => 'svc_nfc_local_http',
        'secret_hash' => Hash::make('test-only-http-secret'), 'scopes' => ['identity:nfc:validate'], 'active' => true]);
    $org = Organizacion::create(['nombre' => 'HTTP NFC', 'slug' => 'http-nfc', 'tipo' => 'asociacion', 'estado' => 'activa']);
    MiembroOrganizacion::create(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $staff->id, 'estado' => 'activo', 'fecha_inicio' => now()->subDay()]);
    $event = Evento::create(['organizacion_id' => (string) $org->id, 'titulo' => 'NFC HTTP', 'estado' => 'publicado',
        'fecha_hora_inicio' => now()->addMinutes(15), 'fecha_hora_fin' => now()->addHours(2), 'capacidad' => 1]);
    StaffEvento::create(['evento_id' => (string) $event->id, 'organizacion_id' => (string) $org->id, 'usuario_id' => (string) $staff->id, 'asignado_por' => (string) $staff->id]);
    $booking = RegistroEvento::create(['evento_id' => (string) $event->id, 'usuario_id' => (string) $student->id,
        'estado' => 'confirmada', 'estado_pago' => 'exento', 'estado_asistencia' => 'pendiente', 'token_qr' => Str::random(64)]);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException($errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/identity_nfc_server.php')], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mongodb', 'DB_DATABASE' => 'campus_virtual_testing',
        'MONGODB_DATABASE' => 'campus_virtual_testing', 'MONGODB_DSN' => 'mongodb://mongodb:27017/?replicaSet=rs0',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ]);
    try {
        $process->start();
        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.05);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        expect($ready)->toBeTrue();
        config(['comunidad.eventos_nfc_habilitado' => true, 'identidad_comunidad' => ['url' => 'http://'.$address,
            'client_id' => 'svc_nfc_local_http', 'client_secret' => 'test-only-http-secret']]);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://'.$address.'/*']);
        $body = ['evento_id' => (string) $event->id, 'metodo' => 'nfc', 'credential_uid' => 'TEST-LOCAL-NFC'];
        $this->actingAs($staff)->withSession(['organizacion_id' => (string) $org->id]);
        $this->postJson('/api/eventos/checkin', $body)->assertOk()->assertJsonPath('asistente.name', 'NFC HTTP real');
        expect($booking->fresh()->estado_asistencia)->toBe('asistio');
        $card->update(['status' => 'blocked']);
        $this->postJson('/api/eventos/checkin', $body)->assertUnprocessable()->assertJsonValidationErrors('credential_uid');
        expect(AuditoriaComunidad::where('accion', 'evento_asistencia')->count())->toBe(1)
            ->and(SecurityEvent::where('type', 'nfc_validated')->count())->toBe(1)
            ->and(SecurityEvent::where('type', 'nfc_validation_failed')->count())->toBe(1);
    } finally {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
});
