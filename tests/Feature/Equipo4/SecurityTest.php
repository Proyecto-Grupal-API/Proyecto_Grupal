<?php

namespace Tests\Feature\Equipo4;

use Tests\TestCase;

class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.team4.shared_secret' => 'test-only-team4-shared-secret',
            'services.team4.allowed_clock_skew' => 300,
        ]);
    }

    /** @test */
    public function test_hmac_invalido_retorna_401(): void
    {
        $response = $this->withHeaders([
            'X-Team-Timestamp' => (string) time(),
            'X-Team-Signature' => str_repeat('d', 64),
        ])->postJson('/api/v1/inventory/availability', ['items' => []]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function test_ruta_e3_tambien_requiere_hmac(): void
    {
        $response = $this->withHeaders([
            'X-Team-Timestamp' => (string) time(),
            'X-Team-Signature' => str_repeat('e', 64),
        ])->postJson('/api/equipo4/integration/availability', ['items' => []]);

        $response->assertUnauthorized();
    }
}