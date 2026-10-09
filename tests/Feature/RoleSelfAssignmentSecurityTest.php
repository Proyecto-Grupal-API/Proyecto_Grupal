<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    // Only the test bypasses request-forgery checks; auth and role protection stay active.
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function roleSecurityUser(array $roles = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    // Persist the fixture without passing through the legacy roles attribute setter.
    User::whereKey($user->getKey())->update(['roles' => $roles]);
    $user = $user->fresh();
    expect($user->roles)->toBe($roles);
    return $user;
}

test('role assignment endpoint still requires authentication', function () {
    $this->postJson('/roles/assign', ['role_name' => 'admin'])->assertUnauthorized();
});

test('authenticated users cannot assign arbitrary global or contextual roles', function () {
    $user = roleSecurityUser();
    $this->actingAs($user);
    foreach ([
        ['role_name' => 'admin'],
        ['role_name' => 'financial_admin', 'scope_type' => 'association', 'scope_id' => 'other-association'],
        ['role_name' => 'cashier', 'scope_type' => 'business', 'scope_id' => 'other-business'],
        [],
    ] as $payload) {
        $this->postJson('/roles/assign', $payload)->assertForbidden();
        expect($user->fresh()->roles)->toBe([]);
    }
});

test('preexisting administrator roles do not reopen the self assignment endpoint', function () {
    $roles = [['name' => 'admin', 'scope_type' => null, 'scope_id' => null, 'assigned_at' => now()->toDateTimeString()]];
    $user = roleSecurityUser($roles);
    $this->actingAs($user)->postJson('/roles/assign', ['role_name' => 'financial_admin'])->assertForbidden();
    expect($user->fresh()->roles)->toBe($roles);
});

test('role assignment payload cannot modify another user', function () {
    $user = roleSecurityUser();
    $other = roleSecurityUser();
    $this->actingAs($user)->postJson('/roles/assign', [
        'role_name' => 'admin', 'user_id' => (string) $other->getKey(),
    ])->assertForbidden();
    expect($user->fresh()->roles)->toBe([])->and($other->fresh()->roles)->toBe([]);
});

test('roles screen remains available for consulting own registered roles', function () {
    $roles = [['name' => 'student', 'scope_type' => null, 'scope_id' => null]];
    $user = roleSecurityUser($roles);
    $this->withoutVite();
    $this->actingAs($user)->get('/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Roles/Index')->where('userRoles', $roles)->missing('availableRoles'));
    expect($user->fresh()->roles)->toBe($roles);
});
