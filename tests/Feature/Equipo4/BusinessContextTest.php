<?php

namespace Tests\Feature\Equipo4;

use App\Http\Controllers\Controller;
use Tests\TestCase;

class BusinessContextTest extends TestCase
{
    public function test_business_context_updates_scoped_controller_property(): void
    {
        $controller = new class extends Controller {
            protected string $businessId = 'BUS-CD-SOUV-001';

            public function businessId(): string
            {
                return $this->businessId;
            }
        };

        $controller->setBusinessContext('BUS-OTHER-STORE-002');

        $this->assertSame('BUS-OTHER-STORE-002', $controller->businessId());
    }
}
