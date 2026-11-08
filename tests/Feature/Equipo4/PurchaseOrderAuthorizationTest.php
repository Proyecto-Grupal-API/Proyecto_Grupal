<?php

namespace Tests\Feature\Equipo4;

use App\Http\Controllers\PurchaseOrderController;
use App\Services\IdentityService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PurchaseOrderAuthorizationTest extends TestCase
{
    public function test_authorization_permission_alone_cannot_cancel_purchase_orders(): void
    {
        $request = Request::create('/equipo4/compras/invalid/status', 'PATCH', [
            'action' => 'cancelar',
        ]);
        $request->attributes->set('team4_user_id', 'USR-AUTHORIZER-001');

        $identity = $this->mock(IdentityService::class);
        $identity->shouldReceive('userCan')
            ->once()
            ->with('USR-AUTHORIZER-001', 'compras.cancel')
            ->andReturn(false);

        try {
            app(PurchaseOrderController::class)->changeStatus($request, 'invalid');
            $this->fail('Se esperaba denegar la cancelación sin compras.cancel.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_cancel_permission_is_not_blocked_by_route_authorization_gate(): void
    {
        $request = Request::create('/equipo4/compras/000000000000000000000001/status', 'PATCH', [
            'action' => 'cancelar',
        ]);
        $request->attributes->set('team4_user_id', 'USR-CANCEL-001');

        $identity = $this->mock(IdentityService::class);
        $identity->shouldReceive('userCan')
            ->once()
            ->with('USR-CANCEL-001', 'compras.cancel')
            ->andReturn(true);

        $response = app(PurchaseOrderController::class)->changeStatus(
            $request,
            '000000000000000000000001'
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }
}
