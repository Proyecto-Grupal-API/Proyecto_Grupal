<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    Role::firstOrCreate(['name' => Role::ADMIN], ['name' => Role::ADMIN, 'display_name' => 'Admin']);
    Role::firstOrCreate(['name' => Role::ESTUDIANTE], ['name' => Role::ESTUDIANTE, 'display_name' => 'Estudiante']);
    Role::firstOrCreate(['name' => Role::CONSEJO_ESTUDIANTIL], ['name' => Role::CONSEJO_ESTUDIANTIL, 'display_name' => 'Consejo Estudiantil']);
    Role::firstOrCreate(['name' => Role::STUDENT_MANAGER], ['name' => Role::STUDENT_MANAGER, 'display_name' => 'Gestor de estudiantes']);
});

test('a real global admin assignment satisfies the global role check', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);

    expect($user->fresh()->hasRole(Role::ADMIN))->toBeTrue();
});

test('a malformed historical assignment is never interpreted as a global role', function () {
    $user = User::factory()->create();
    $user->roles = [[
        'name' => Role::ADMIN,
        'scope_type' => null,
        'scope_id' => 'X',
    ]];
    $user->save();

    expect($user->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

test('a business contextual role only matches its exact context', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE, 'business', 'business-1');

    $user = $user->fresh();

    expect($user->hasRole(Role::ESTUDIANTE, 'business', 'business-1'))->toBeTrue()
        ->and($user->hasRole(Role::ESTUDIANTE))->toBeFalse()
        ->and($user->hasRole(Role::ESTUDIANTE, 'business', 'business-2'))->toBeFalse();
});

test('a council contextual role only matches its exact context', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::CONSEJO_ESTUDIANTIL, 'council', 'council-1');

    $user = $user->fresh();

    expect($user->hasRole(Role::CONSEJO_ESTUDIANTIL, 'council', 'council-1'))->toBeTrue()
        ->and($user->hasRole(Role::CONSEJO_ESTUDIANTIL))->toBeFalse()
        ->and($user->hasRole(Role::CONSEJO_ESTUDIANTIL, 'council', 'council-2'))->toBeFalse();
});

test('a regular authenticated user cannot self-assign the admin role', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/roles/assign', [
        'role_name' => Role::ADMIN,
    ]);

    $response->assertForbidden();
    expect($user->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

test('a guest cannot hit the role assignment endpoint', function () {
    $response = $this->post('/roles/assign', [
        'role_name' => Role::ADMIN,
    ]);

    $response->assertRedirect('/login');
});

test('an admin can assign a valid role to another user', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $target = User::factory()->create();

    $response = $this->actingAs($admin)->post('/roles/assign', [
        'role_name' => Role::ESTUDIANTE,
        'user_id' => (string) $target->getKey(),
    ]);

    $response->assertRedirect();
    expect($target->fresh()->hasRole(Role::ESTUDIANTE))->toBeTrue();
});

test('an arbitrary role name outside the catalog is rejected even for an admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $response = $this->actingAs($admin)->post('/roles/assign', [
        'role_name' => 'super-root',
    ]);

    $response->assertSessionHasErrors('role_name');
});

test('User::assignRole rejects a role that is not in the catalog regardless of caller', function () {
    $user = User::factory()->create();

    expect(fn () => $user->assignRole('not-a-real-role'))
        ->toThrow(InvalidArgumentException::class);
});

test('User::assignRole rejects incomplete or unknown contexts', function () {
    $user = User::factory()->create();

    expect(fn () => $user->assignRole(Role::ESTUDIANTE, null, 'scope-1'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $user->assignRole(Role::ESTUDIANTE, 'business', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $user->assignRole(Role::ESTUDIANTE, 'unknown', 'scope-1'))
        ->toThrow(InvalidArgumentException::class);
});

test('User::assignRole rejects an empty contextual scope id', function () {
    $user = User::factory()->create();

    expect(fn () => $user->assignRole(Role::ESTUDIANTE, 'business', ''))
        ->toThrow(InvalidArgumentException::class);
});

test('an admin can assign a council contextual role', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $target = User::factory()->create();

    $this->actingAs($admin)->post('/roles/assign', [
        'role_name' => Role::CONSEJO_ESTUDIANTIL,
        'user_id' => (string) $target->getKey(),
        'scope_type' => 'council',
        'scope_id' => 'council-1',
    ])->assertRedirect();

    expect($target->fresh()->hasRole(Role::CONSEJO_ESTUDIANTIL, 'council', 'council-1'))->toBeTrue();
});

test('the role assignment endpoint rejects an incomplete context', function (array $payload, string $field) {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($admin)->postJson('/roles/assign', [
        'role_name' => Role::ESTUDIANTE,
        ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'scope id without type' => [['scope_id' => 'scope-1'], 'scope_type'],
    'scope type without id' => [['scope_type' => 'business'], 'scope_id'],
]);

test('a request scope id does not alter a global middleware role check', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($admin)->postJson('/roles/assign', [
        'role_name' => Role::ESTUDIANTE,
        'scope_id' => 'untrusted-request-value',
    ])->assertUnprocessable()->assertJsonValidationErrors('scope_type');
});

test('student manager remains a valid persisted role', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::STUDENT_MANAGER);

    expect($user->fresh()->hasRole(Role::STUDENT_MANAGER))->toBeTrue();
});
