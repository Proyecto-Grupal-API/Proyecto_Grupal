<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    public function test_marketplace_route_requires_authentication(): void
    {
        $response = $this->get('/tienda');

        $response->assertRedirect('/login');
    }
}
