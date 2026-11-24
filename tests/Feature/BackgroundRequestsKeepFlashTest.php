<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\PreserveSessionForBackgroundRequests;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;

/*
 * La barra común pregunta cada pocos segundos por el latido de seguridad y por las notificaciones, con la misma sesión
 * que el resto de la página. El aviso de confirmación de una operación vive en la sesión sólo hasta la petición
 * siguiente, así que una de esas peticiones de fondo podía gastarlo, o pisar la sesión que la operación acababa de
 * guardar, y la página siguiente no mostraba el aviso aunque la operación se hubiera hecho. Visto en los recorridos de
 * interfaz: una vez con poca carga y doce en una noche con la máquina cargada.
 */
beforeEach(function () {
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    Route::middleware(['web', 'auth'])->post('/prueba-aviso/operacion', fn () => redirect('/prueba-aviso/pagina')->with('success', 'Operación registrada.'));
    Route::middleware(['web', 'auth'])->get('/prueba-aviso/pagina', fn () => response()->json(['aviso' => session('success')]));
    $this->user = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
});

test('the security heartbeat does not consume the confirmation meant for the next page', function () {
    $this->actingAs($this->user)->post('/prueba-aviso/operacion')->assertRedirect('/prueba-aviso/pagina');
    $this->getJson('/seguridad/latido')->assertOk();
    $this->getJson('/seguridad/latido')->assertOk();

    $this->get('/prueba-aviso/pagina')->assertOk()->assertJson(['aviso' => 'Operación registrada.']);
    // El aviso se muestra una vez: la página siguiente ya no lo trae.
    $this->get('/prueba-aviso/pagina')->assertOk()->assertJson(['aviso' => null]);
});

test('the notification poll does not consume the confirmation meant for the next page', function () {
    $this->actingAs($this->user)->post('/prueba-aviso/operacion')->assertRedirect('/prueba-aviso/pagina');
    $this->getJson('/api/v1/comunidad/portal/notificaciones')->assertOk();

    $this->get('/prueba-aviso/pagina')->assertOk()->assertJson(['aviso' => 'Operación registrada.']);
});

test('a background request that was already running does not overwrite what the operation stored', function () {
    $handler = new ArraySessionHandler(10);
    $id = str_repeat('a', 40);
    $background = new Store('campus', $handler, $id);
    $background->start();                                   // la petición de fondo empieza y lee la sesión

    $operation = new Store('campus', $handler, $id);        // mientras tanto, la operación termina y guarda
    $operation->start();
    $operation->flash('success', 'Pedido registrado.');
    $operation->put('carrito', 3);
    $operation->save();

    $request = Request::create('/seguridad/latido');        // la petición de fondo termina y se guarda su copia
    $request->setLaravelSession($background);
    (new PreserveSessionForBackgroundRequests)->handle($request, fn () => response()->json(['ok' => true]));
    $background->save();

    $next = new Store('campus', $handler, $id);             // la página siguiente
    $next->start();
    expect($next->get('success'))->toBe('Pedido registrado.')->and($next->get('carrito'))->toBe(3);
});

test('a normal page still consumes the confirmation: only background requests preserve it', function () {
    $this->actingAs($this->user)->post('/prueba-aviso/operacion');
    $this->get('/prueba-aviso/pagina')->assertJson(['aviso' => 'Operación registrada.']);
    $this->getJson('/seguridad/latido')->assertOk();
    $this->get('/prueba-aviso/pagina')->assertJson(['aviso' => null]);
});
