<?php

use Illuminate\Support\Facades\Route;

// Rúbrica 3.6/6.5: los errores HTML se muestran en español con la guía visual y una salida; las API siguen en JSON.
beforeEach(function () {
    Route::middleware('web')->get('/_prueba-errores/{code}', fn (int $code) => abort($code, $code === 403 ? 'Esta operación requiere personal autorizado de Servicios.' : ''));
});

test('html errors render the spanish campus page with a way back', function (int $code, string $title) {
    $this->get("/_prueba-errores/{$code}")->assertStatus($code)
        ->assertSee('<html lang="es">', false)->assertSee($title)->assertSee('Ir al inicio')->assertSee('Regresar')->assertDontSee('Whoops');
})->with([[403, 'Acceso no autorizado'], [404, 'Página no encontrada'], [419, 'La sesión expiró'], [429, 'Demasiadas solicitudes'], [500, 'Error del servidor'], [503, 'Servicio no disponible']]);

test('a forbidden page explains the reason given by the module', function () {
    $this->get('/_prueba-errores/403')->assertForbidden()->assertSee('Esta operación requiere personal autorizado de Servicios.');
});

test('json clients still receive json errors', function () {
    $this->getJson('/_prueba-errores/404')->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson('/_prueba-errores/403')->assertForbidden()->assertJsonPath('message', 'Esta operación requiere personal autorizado de Servicios.');
});
