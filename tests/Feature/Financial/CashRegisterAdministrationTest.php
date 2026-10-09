<?php

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Services\CashRegisterAdministrationService;
use App\Domains\Financial\Services\CashShiftService;
use App\Models\ServiceClient;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    cashAdminCleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () { cashAdminCleanup(); });

function cashAdminCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Administración de caja requiere la base financiera de pruebas.');
    $ids = CashRegister::where('association_id', 'like', 'test-fc-cash-admin-%')->pluck('id');
    $shiftIds = CashShift::whereIn('cash_register_id', $ids)->pluck('id');
    CashRegisterChange::whereIn('cash_register_id', $ids)->delete();
    CashMovement::whereIn('cash_shift_id', $shiftIds)->delete();
    CashShift::whereIn('id', $shiftIds)->delete(); CashRegister::whereIn('id', $ids)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-admin-%')->delete();
    User::where('email', 'like', 'test-fc-cash-admin-%')->delete();
    fcCleanup();
}
function cashAdminCreate(): array
{
    return app(CashRegisterAdministrationService::class)->create(fcKey('cash-admin-association'), 'Caja original', 'MXN', FC_ACTOR, 'Alta aprobada', fcKey('cash-admin-create'));
}
function cashAdminGrant(string $actor, string $association, array $actions): void
{
    app()->instance(CashAuthorizationProvider::class, new class($actor, $association, $actions) implements CashAuthorizationProvider {
        public function __construct(private string $actor, private string $association, private array $actions) {}
        public function allows(string $actorId, string $associationId, string $action): bool
        {
            return $actorId === $this->actor && $associationId === $this->association && in_array($action, $this->actions, true);
        }
    });
    foreach (app('router')->getRoutes() as $route) $route->flushController();
}
function cashAdminToken($test, array $scopes): array
{
    $id = fcKey('cash-admin-client');
    ServiceClient::create(['client_id' => $id, 'name' => 'Caja admin prueba', 'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id,
        'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}
function cashAdminUser(): User
{
    return User::factory()->create(['email' => fcKey('cash-admin-user').'@example.test', 'email_verified_at' => now()]);
}

test('cash register administration creates one audited register and retries its immutable original response', function () {
    $service = app(CashRegisterAdministrationService::class); $association = fcKey('cash-admin-association'); $key = fcKey('cash-admin-create');
    $first = $service->create($association, 'Caja original', 'mxn', FC_ACTOR, 'Alta aprobada', $key);
    $retry = $service->create($association, 'Caja original', 'MXN', FC_ACTOR, 'Alta aprobada', $key);
    expect($retry)->toBe($first)->and($first['version'])->toBe(1)->and($first['status'])->toBe('ACTIVE');
    $change = CashRegisterChange::where('idempotency_key', $key)->firstOrFail();
    expect($change->before_state)->toBeNull()->and($change->after_state)->toBe($first)->and($change->actor_id)->toBe(FC_ACTOR);
    $service->update($association, $first['id'], 'Caja renombrada', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Nombre actualizado', fcKey('cash-admin-update'));
    expect($service->create($association, 'Caja original', 'MXN', FC_ACTOR, 'Alta aprobada', $key))->toBe($first);
    expect(CashRegister::where('association_id', $association)->count())->toBe(1);
});

test('cash register administration rejects a creation key reused with another association actor or payload', function () {
    $service = app(CashRegisterAdministrationService::class); $association = fcKey('cash-admin-association'); $key = fcKey('cash-admin-create');
    $service->create($association, 'Caja', 'MXN', FC_ACTOR, 'Alta', $key);
    foreach ([[$association, 'Otra caja', FC_ACTOR], [fcKey('cash-admin-other'), 'Caja', FC_ACTOR], [$association, 'Caja', 'other-actor']] as [$assoc, $name, $actor])
        expect(fn () => $service->create($assoc, $name, 'MXN', $actor, 'Alta', $key))->toThrow(InvalidArgumentException::class);
    expect(CashRegisterChange::where('idempotency_key', $key)->count())->toBe(1);
});

test('cash register administration rejects stale versions and retries an earlier update after a later update', function () {
    $first = cashAdminCreate(); $service = app(CashRegisterAdministrationService::class); $key = fcKey('cash-admin-update');
    $second = $service->update($first['association_id'], $first['id'], 'Caja dos', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Nuevo nombre', $key);
    expect($second['version'])->toBe(2);
    expect(fn () => $service->update($first['association_id'], $first['id'], 'Edición vieja', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Antigua', fcKey('cash-admin-stale')))->toThrow(InvalidArgumentException::class);
    $third = $service->update($first['association_id'], $first['id'], 'Caja tres', CashRegisterStatus::ACTIVE, 2, FC_ACTOR, 'Otra edición', fcKey('cash-admin-next'));
    expect($third['version'])->toBe(3);
    expect($service->update($first['association_id'], strtoupper($first['id']), 'Caja dos', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Nuevo nombre', $key))->toBe($second);
    $register = CashRegister::where('public_id', $first['id'])->firstOrFail();
    expect($register->name)->toBe('Caja tres')->and(CashRegisterChange::where('cash_register_id', $register->id)->count())->toBe(3);
});

test('cash register administration prevents deactivation while open and permits it after closing without duplicate history', function () {
    $first = cashAdminCreate(); $service = app(CashRegisterAdministrationService::class); $shifts = app(CashShiftService::class);
    $shift = $shifts->open($first['id'], FC_ACTOR, 1000, fcKey('cash-admin-open')); $key = fcKey('cash-admin-disable');
    expect(fn () => $service->update($first['association_id'], $first['id'], $first['name'], CashRegisterStatus::INACTIVE, 1, FC_ACTOR, 'Desactivar', $key))->toThrow(InvalidArgumentException::class);
    expect(CashRegisterChange::where('idempotency_key', $key)->exists())->toBeFalse();
    $shifts->close($shift->public_id, 1000, fcKey('cash-admin-close'), FC_ACTOR, 'Cierre');
    foreach ([1, 2] as $attempt) $result = $service->update($first['association_id'], $first['id'], $first['name'], CashRegisterStatus::INACTIVE, 1, FC_ACTOR, 'Desactivar', $key);
    expect($result['status'])->toBe('INACTIVE')->and($result['version'])->toBe(2);
    expect(fn () => $shifts->open($first['id'], FC_ACTOR, 0, fcKey('cash-admin-reopen')))->toThrow(InvalidArgumentException::class);
    $active = $service->update($first['association_id'], $first['id'], $first['name'], CashRegisterStatus::ACTIVE, 2, FC_ACTOR, 'Reactivar', fcKey('cash-admin-enable'));
    expect($active['version'])->toBe(3);
    expect($shifts->open($first['id'], FC_ACTOR, 0, fcKey('cash-admin-reopen'))->status->value)->toBe('OPEN');
});

test('cash register administration rolls back creation and update when history persistence fails', function () {
    $service = app(CashRegisterAdministrationService::class); $association = fcKey('cash-admin-association'); $key = fcKey('cash-admin-fail-create');
    CashRegisterChange::creating(function () { throw new RuntimeException('Fallo auditado'); });
    try {
        expect(fn () => $service->create($association, 'Caja', 'MXN', FC_ACTOR, 'Alta', $key))->toThrow(RuntimeException::class);
        expect(CashRegister::where('association_id', $association)->count())->toBe(0);
    } finally { CashRegisterChange::flushEventListeners(); }
    $first = $service->create($association, 'Caja', 'MXN', FC_ACTOR, 'Alta', $key);
    $updateKey = fcKey('cash-admin-fail-update');
    CashRegisterChange::creating(function () { throw new RuntimeException('Fallo auditado'); });
    try {
        expect(fn () => $service->update($association, $first['id'], 'Otra caja', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Editar', $updateKey))->toThrow(RuntimeException::class);
        $register = CashRegister::where('public_id', $first['id'])->firstOrFail();
        expect($register->version)->toBe(1)->and($register->name)->toBe('Caja');
    } finally { CashRegisterChange::flushEventListeners(); }
    expect($service->update($association, $first['id'], 'Otra caja', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Editar', $updateKey)['version'])->toBe(2);
});

test('cash register administration api requires a manage scope and independent association grant', function () {
    $first = cashAdminCreate(); $url = '/api/v1/financial/cash/associations/'.$first['association_id'].'/registers';
    $this->postJson($url, [])->assertUnauthorized();
    [$headers, $actor] = cashAdminToken($this, ['financial:cash:operate', 'financial:cash:read']);
    cashAdminGrant($actor, $first['association_id'], ['manage', 'read']);
    $this->postJson($url, [], $headers)->assertForbidden();
    $this->patchJson($url.'/'.$first['id'], [], $headers)->assertForbidden();
    [$headers, $actor] = cashAdminToken($this, ['financial:cash:manage']);
    cashAdminGrant($actor, $first['association_id'], ['read']);
    $this->postJson($url, [], $headers)->assertForbidden();
    $this->patchJson($url.'/'.$first['id'], [], $headers)->assertForbidden();
    cashAdminGrant($actor, $first['association_id'], ['manage']);
    $this->getJson($url.'/'.$first['id'].'/history', $headers)->assertForbidden();
    $this->postJson($url, ['name' => 'Caja', 'currency' => 'MXN', 'reason' => 'Alta'], $headers)->assertUnprocessable();
});

test('cash register administration api creates edits retries and returns scoped audit history with trusted actor', function () {
    $association = fcKey('cash-admin-association'); $url = '/api/v1/financial/cash/associations/'.$association.'/registers';
    [$headers, $actor] = cashAdminToken($this, ['financial:cash:manage', 'financial:cash:read']);
    cashAdminGrant($actor, $association, ['manage', 'read']);
    $headers['Idempotency-Key'] = fcKey('cash-admin-api-create');
    $body = ['name' => 'Caja API', 'currency' => 'MXN', 'reason' => 'Alta', 'actor_id' => 'forged'];
    $id = $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.created_by', $actor)->json('data.id');
    $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.id', $id);
    $headers['Idempotency-Key'] = fcKey('cash-admin-api-update');
    $body = ['name' => 'Caja API nueva', 'status' => 'INACTIVE', 'expected_version' => 1, 'reason' => 'Inactivar', 'actor_id' => 'forged'];
    foreach ([1, 2] as $attempt) $this->patchJson($url.'/'.$id, $body, $headers)->assertOk()->assertJsonPath('data.version', 2);
    $this->getJson($url.'/'.$id.'/history', $headers)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.actor_id', $actor)
        ->assertJsonPath('data.0.before.status', 'ACTIVE')->assertJsonPath('data.0.after.status', 'INACTIVE');
    $foreign = cashAdminCreate();
    $this->getJson($url.'/'.$foreign['id'].'/history', $headers)->assertNotFound();
    $this->patchJson($url.'/'.$foreign['id'], $body, $headers)->assertNotFound();
    $headers['Idempotency-Key'] = fcKey('cash-admin-api-currency');
    $this->patchJson($url.'/'.$id, array_merge($body, ['expected_version' => 2, 'currency' => 'USD']), $headers)->assertUnprocessable();
    $this->patchJson($url.'/'.$id, array_merge($body, ['expected_version' => 2, 'association_id' => $foreign['association_id']]), $headers)->assertUnprocessable();
});

test('cash register administration web denies pending permissions even for preexisting admin roles', function () {
    $this->get('/finanzas/caja/administracion')->assertRedirect('/login');
    $first = cashAdminCreate(); $url = '/finanzas/caja/asociaciones/'.$first['association_id'].'/registers';
    $user = cashAdminUser(); User::whereKey($user->getKey())->update(['roles' => [['name' => 'admin']]]);
    $this->actingAs($user->fresh())->get('/finanzas/caja/administracion')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/CashRegisters')->missing('registers'));
    $this->postJson($url, [])->assertForbidden();
    $this->patchJson($url.'/'.$first['id'], [])->assertForbidden();
    $this->getJson($url.'/'.$first['id'].'/history')->assertForbidden();
});

test('cash register administration web uses session identity and enforces manage versus read', function () {
    $user = cashAdminUser(); $association = fcKey('cash-admin-association'); $actor = 'user:'.$user->getKey();
    $url = '/finanzas/caja/asociaciones/'.$association.'/registers';
    $this->actingAs($user); cashAdminGrant($actor, $association, ['manage', 'read']);
    $headers = ['Idempotency-Key' => fcKey('cash-admin-web-create')];
    $body = ['name' => 'Caja web', 'currency' => 'MXN', 'reason' => 'Alta', 'actor_id' => 'forged'];
    $id = $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.created_by', $actor)->json('data.id');
    $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.id', $id);
    $headers['Idempotency-Key'] = fcKey('cash-admin-web-update');
    $body = ['name' => 'Caja web nueva', 'status' => 'ACTIVE', 'expected_version' => 1, 'reason' => 'Editar'];
    $this->patchJson($url.'/'.$id, $body, $headers)->assertOk()->assertJsonPath('data.version', 2);
    $this->getJson($url.'/'.$id.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.actor_id', $actor);
    cashAdminGrant($actor, $association, ['read']);
    $this->patchJson($url.'/'.$id, $body, $headers)->assertForbidden();
    $this->getJson($url.'/'.$id.'/history')->assertOk();
    $this->actingAs(cashAdminUser())->getJson($url.'/'.$id.'/history')->assertForbidden();
});
