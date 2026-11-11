<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\PagoReservaEvento;
use App\Models\RegistroEvento;
use App\Models\User;
use Database\Seeders\EventoSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class EventoPaymentAvailabilityTest extends TestCase
{
    use RefreshMongoDatabase;

    private const BASE = '/api/v1/comunidad/portal/eventos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        $this->seed();
        $this->seed(EventoSeeder::class);
        Http::preventStrayRequests();
    }

    public static function paymentConfigurations(): array
    {
        return [
            'disabled legacy' => [false, null, false],
            'disabled despite allowed organization' => [false, ['sistemas'], false],
            'enabled legacy null' => [true, null, true],
            'enabled empty allowlist' => [true, [], false],
            'enabled allowed organization' => [true, ['sistemas'], true],
            'enabled excluded organization' => [true, ['consejo'], false],
        ];
    }

    #[DataProvider('paymentConfigurations')]
    public function test_payment_availability_respects_global_flag_and_event_organization_without_charging(
        bool $enabled,
        ?array $organizationSlugs,
        bool $available,
    ): void {
        $organizations = $organizationSlugs === null ? null : Organizacion::whereIn('slug', $organizationSlugs)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        config(['comunidad.eventos_pagos.enabled' => $enabled, 'comunidad.eventos_pagos.organizaciones' => $organizations]);
        $evento = Evento::where('slug', 'encuentro-comunidad-demo')->firstOrFail();
        $this->actingAs(User::where('email', 'presidencia@campus.test')->firstOrFail());

        foreach (['/api/eventos', self::BASE] as $url) {
            foreach ([0, 1] as $gestion) {
                $response = $this->getJson($url.'?gestion='.$gestion)
                    ->assertOk()
                    ->assertJsonPath('pagos_habilitados', $available)
                    ->assertJsonPath('puede_gestionar', true)
                    ->assertJsonCount(2, 'eventos');

                $listado = collect($response->json('eventos'))->firstWhere('id', (string) $evento->id);
                $this->assertNotNull($listado);
                $this->assertEquals(75, $listado['costo']);
                $this->assertTrue($listado['registro_abierto']);
                $this->assertNull($listado['mi_registro']);
            }
        }

        $this->assertSame(0, RegistroEvento::count());
        $alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->actingAs($alumno)->postJson(self::BASE.'/'.$evento->id.'/inscripcion')
            ->assertOk()
            ->assertJsonPath('registro.estado_pago', 'pendiente')
            ->assertJsonPath('message', $available
                ? 'Lugar reservado durante un máximo de 15 minutos. Abre Mis boletos para pagar.'
                : 'Lugar reservado durante un máximo de 15 minutos. El pago aún no está disponible.');
        $registro = RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', (string) $alumno->id)->firstOrFail();
        $this->assertTrue($registro->reserva_hasta->equalTo(now()->addMinutes(15)));

        // Ticket availability follows the event organization, even after switching the active organization.
        $consejo = Organizacion::where('slug', 'consejo')->firstOrFail();
        $this->withSession(['organizacion_id' => (string) $consejo->id])
            ->getJson(self::BASE.'/'.$evento->id.'/boleto')->assertOk()
            ->assertJsonPath('puede_pagar', $available)
            ->assertJsonPath('puede_consultar_pago', false)
            ->assertJsonPath('estado_pago', 'pendiente')
            ->assertJsonPath('token_qr', null);
        $this->assertSame(0, PagoReservaEvento::count());
        Http::assertNothingSent();
    }

    public function test_removing_organization_keeps_existing_payment_recovery_under_global_flag(): void
    {
        config(['comunidad.eventos_pagos.enabled' => true, 'comunidad.eventos_pagos.organizaciones' => null]);
        $evento = Evento::where('slug', 'encuentro-comunidad-demo')->firstOrFail();
        $alumno = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $this->actingAs($alumno)->postJson(self::BASE.'/'.$evento->id.'/inscripcion')->assertOk();
        $registro = RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', (string) $alumno->id)->firstOrFail();
        config(['comunidad.eventos_pagos.organizaciones' => []]);

        foreach (['pendiente', 'cancelacion_pendiente', 'devolucion_pendiente'] as $estado) {
            // Fixture of a prior attempt; no financial operation is executed.
            $registro->update(['pago_estado' => $estado]);
            $this->getJson(self::BASE.'/'.$evento->id.'/boleto')->assertOk()
                ->assertJsonPath('puede_pagar', false)
                ->assertJsonPath('puede_consultar_pago', true)
                ->assertJsonPath('estado_pago', 'pendiente');
        }

        config(['comunidad.eventos_pagos.enabled' => false]);
        $this->getJson(self::BASE.'/'.$evento->id.'/boleto')->assertOk()
            ->assertJsonPath('puede_pagar', false)
            ->assertJsonPath('puede_consultar_pago', false);
        $this->assertSame(0, PagoReservaEvento::count());
        Http::assertNothingSent();
    }
}
