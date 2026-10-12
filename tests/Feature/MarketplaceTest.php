<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    public function test_marketplace_route_requires_authentication(): void
    {
        $this->get('/tienda')->assertRedirect('/login');
    }

    public function test_management_routes_require_authentication(): void
    {
        foreach ([
            '/tienda/negocios',
            '/tienda/solicitudes',
            '/tienda/productos',
            '/tienda/pedidos',
            '/tienda/vendedor',
            '/tienda/vendedor/pedidos',
            '/tienda/postventa',
            '/tienda/auditoria',
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }
}
