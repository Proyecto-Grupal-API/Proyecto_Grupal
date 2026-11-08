<?php

namespace Tests\Feature\Equipo4;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTeam4Role;
use App\Services\BusinessService;
use App\Services\IdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class BusinessScopeMiddlewareTest extends TestCase
{
    private array $createdBusinessIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $businesses = DB::connection('mongodb')->getCollection('businesses');
        foreach (['BUS-CD-SOUV-001', 'BUS-SECOND-STORE-002'] as $businessId) {
            $result = $businesses->updateOne(
                ['business_id' => $businessId],
                ['$setOnInsert' => [
                    '_id' => new ObjectId(),
                    'business_id' => $businessId,
                    'name' => 'Negocio de prueba',
                    'active' => true,
                ]],
                ['upsert' => true]
            );
            if ($result->getUpsertedCount() > 0) {
                $this->createdBusinessIds[] = $businessId;
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            DB::connection('mongodb')->getCollection('businesses')->deleteMany([
                'business_id' => ['$in' => $this->createdBusinessIds],
            ]);
        }
        parent::tearDown();
    }

    public function test_business_scope_selects_assigned_business_and_global_scope_resets_default(): void
    {
        $defaultBusinessId = (string) config('team4.default_business_id');
        $businessId = 'BUS-SECOND-STORE-002';
        $identity = $this->mock(IdentityService::class);
        $identity->shouldReceive('resolveCurrentUserId')
            ->twice()
            ->andReturn('USR-SCOPED-001', 'USR-GLOBAL-001');
        $identity->shouldReceive('getUser')
            ->twice()
            ->andReturn(
                [
                    'roles' => ['inventory_manager'],
                    'role' => 'inventory_manager',
                    'role_scope' => 'business',
                    'role_business_id' => $businessId,
                ],
                [
                    'roles' => ['inventory_manager'],
                    'role' => 'inventory_manager',
                    'role_scope' => 'global',
                    'role_business_id' => null,
                ]
            );
        $identity->shouldReceive('isAuthorized')->twice()->with(['inventory_manager'])->andReturn(true);

        $middleware = new EnsureTeam4Role($identity, app(BusinessService::class));
        $scopedController = $this->makeController();
        $scopedRequest = $this->makeRequest($scopedController);
        $middleware->handle($scopedRequest, fn () => response('ok'));

        $this->assertSame($businessId, $scopedController->businessId());
        $this->assertSame($businessId, $scopedRequest->attributes->get('team4_business_id'));

        $globalController = $this->makeController();
        $globalRequest = $this->makeRequest($globalController);
        $middleware->handle($globalRequest, fn () => response('ok'));

        $this->assertSame($defaultBusinessId, $globalController->businessId());
        $this->assertSame($defaultBusinessId, config('team4.business_id'));
    }

    private function makeController(): Controller
    {
        return new class extends Controller {
            protected string $businessId = 'BUS-CD-SOUV-001';

            public function businessId(): string
            {
                return $this->businessId;
            }
        };
    }

    private function makeRequest(Controller $controller): Request
    {
        $route = new class($controller) {
            public function __construct(private Controller $controller) {}

            public function getController(): Controller
            {
                return $this->controller;
            }
        };
        $request = Request::create('/equipo4');
        $request->setRouteResolver(fn () => $route);
        return $request;
    }
}
