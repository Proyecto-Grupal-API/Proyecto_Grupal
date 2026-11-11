<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\PagoReservaEvento;
use App\Models\User;
use App\Services\PagosEvento;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class EventPaymentScopeTest extends TestCase
{
    use RefreshMongoDatabase;

    public function test_only_explicitly_enabled_organizations_can_start_new_payments(): void
    {
        config(['comunidad.eventos_pagos.enabled' => true,
            'comunidad.eventos_pagos.organizaciones' => ['507f1f77bcf86cd799439011']]);
        $this->assertTrue(PagosEvento::habilitadoParaOrganizacion('507f1f77bcf86cd799439011'));
        $this->assertFalse(PagosEvento::habilitadoParaOrganizacion('507f1f77bcf86cd799439012'));
        $this->assertFalse(PagosEvento::habilitadoParaOrganizacion(null));
        config(['comunidad.eventos_pagos.organizaciones' => []]);
        $this->assertFalse(PagosEvento::habilitadoParaOrganizacion('507f1f77bcf86cd799439011'));
        config(['comunidad.eventos_pagos.organizaciones' => null]);
        $this->assertTrue(PagosEvento::habilitadoParaOrganizacion('507f1f77bcf86cd799439011'));
        config(['comunidad.eventos_pagos.enabled' => false]);
        $this->assertFalse(PagosEvento::habilitadoParaOrganizacion('507f1f77bcf86cd799439011'));
    }

    public function test_disallowed_organization_never_prepares_a_financial_attempt(): void
    {
        $this->seed();
        Http::preventStrayRequests();
        $org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        $student = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $event = Evento::create(['organizacion_id' => (string) $org->id, 'titulo' => 'Piloto acotado',
            'slug' => 'pilot-scope', 'estado' => 'publicado', 'capacidad' => 5, 'costo' => 25,
            'lista_espera' => true, 'fecha_hora_inicio' => now()->addHour(), 'fecha_hora_fin' => now()->addHours(2),
            'fecha_inicio_registro' => now()->subHour(), 'fecha_fin_registro' => now()->addMinutes(50)]);
        $this->actingAs($student)->postJson('/api/v1/comunidad/portal/eventos/'.$event->id.'/inscripcion',
            ['clave_solicitud' => (string) Str::uuid(), 'generacion' => 0])->assertOk();
        config(['comunidad.eventos_pagos.enabled' => true, 'comunidad.eventos_pagos.client_id' => 'scope-test',
            'comunidad.eventos_pagos.organizaciones' => []]);
        $this->postJson('/api/v1/comunidad/portal/eventos/'.$event->id.'/pago', ['generacion' => 1])->assertNotFound();
        $this->assertSame(0, PagoReservaEvento::count());
        Http::assertNothingSent();
    }
    public function test_setup_command_denies_activation_outside_local_before_provisioning(): void
    {
        $this->artisan('comunidad:preparar-pagos-eventos', ['organizacion' => 'sistemas', '--activar' => true])
            ->expectsOutput('Preparación limitada al entorno local; no concede autoridad productiva.')
            ->assertFailed();
        $this->assertSame(0, \App\Models\ServiceClient::count());
    }
}
