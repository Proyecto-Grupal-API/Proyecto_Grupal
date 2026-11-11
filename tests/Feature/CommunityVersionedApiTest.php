<?php

namespace Tests\Feature;

use App\Models\Organizacion;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class CommunityVersionedApiTest extends TestCase
{
    use RefreshMongoDatabase;

    private const BASE = '/api/v1/comunidad/portal';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_all_portal_routes_preserve_the_legacy_controller_and_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $versioned = $routes->filter(fn ($r) => str_starts_with($r->uri(), ltrim(self::BASE, '/').'/'));
        $this->assertGreaterThan(90, $versioned->count());
        foreach ($versioned as $route) {
            $uri = 'api/'.substr($route->uri(), strlen(ltrim(self::BASE, '/')) + 1);
            $legacy = $routes->first(fn ($r) => $r->uri() === $uri && $r->methods() === $route->methods());
            $this->assertNotNull($legacy, $uri);
            $this->assertSame($legacy->getActionName(), $route->getActionName());
            $this->assertSame($legacy->gatherMiddleware(), $route->gatherMiddleware());
        }
    }

    public function test_anonymous_api_requests_return_json_without_an_accept_header(): void
    {
        $this->get(self::BASE.'/eventos')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_versioned_dashboard_and_legacy_dashboard_agree(): void
    {
        $this->actingAs(User::where('email', 'presidencia@campus.test')->firstOrFail());
        $a = $this->getJson('/api/dashboard')->assertOk();
        $b = $this->getJson(self::BASE.'/dashboard')->assertOk();
        foreach (['organizacion', 'miembrosActivos', 'eventosActivos', 'becasPendientes'] as $key) {
            $this->assertSame($a->json($key), $b->json($key));
        }
    }

    public function test_portal_notifications_do_not_replace_the_oauth_notification_api(): void
    {
        $this->actingAs(User::where('email', 'estudiante@campus.test')->firstOrFail());
        $this->getJson(self::BASE.'/notificaciones')->assertOk();
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/comunidad/notificaciones', 'POST'));
        $this->assertNotSame('App\\Http\\Controllers\\NotificacionController@index', $route->getActionName());
        $this->assertContains('oauth.service:notifications:send', $route->gatherMiddleware());
    }

    public function test_selection_and_institutional_management_keep_their_organization_exemptions(): void
    {
        $user = User::where('email', 'estudiante@campus.test')->firstOrFail();
        $org = Organizacion::where('slug', 'consejo')->firstOrFail();
        $this->actingAs($user)->withHeader('X-Organization-Id', 'previous-tab')
            ->postJson(self::BASE.'/organizaciones/seleccionar', ['organizacion_id' => (string) $org->id])
            ->assertOk();
        $this->postJson(self::BASE.'/gestion-organizaciones', [])->assertForbidden();
    }

    public function test_api_validates_permissions_input_missing_resources_and_stale_organization(): void
    {
        $this->actingAs(User::where('email', 'estudiante@campus.test')->firstOrFail());
        $this->putJson(self::BASE.'/organizaciones/perfil', ['nombre' => 'No autorizado', 'email' => 'test@example.test'])->assertForbidden();
        $this->actingAs(User::where('email', 'presidencia@campus.test')->firstOrFail());
        $this->postJson(self::BASE.'/eventos', [])->assertUnprocessable()->assertJsonValidationErrors('titulo');
        $this->get(self::BASE.'/staff/eventos/507f1f77bcf86cd799439011')->assertNotFound()->assertJsonStructure(['message']);
        $this->withHeader('X-Organization-Id', 'previous-tab')
            ->putJson(self::BASE.'/organizaciones/perfil', ['nombre' => 'Cambio', 'email' => 'test@example.test'])
            ->assertConflict()->assertJsonStructure(['message']);
    }

    public function test_debug_errors_do_not_expose_a_stack_trace_or_environment(): void
    {
        config(['app.debug' => true]);
        Route::get(self::BASE.'/audit-error', fn () => throw new \RuntimeException('private internal detail'));
        $r = $this->get(self::BASE.'/audit-error')->assertStatus(500)->assertJsonStructure(['message']);
        $this->assertArrayNotHasKey('trace', $r->json());
        $this->assertStringNotContainsString('private internal detail', $r->getContent());
    }
}
