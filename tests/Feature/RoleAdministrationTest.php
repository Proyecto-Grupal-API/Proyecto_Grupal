<?php

use App\Models\Role;
use App\Models\User;

function roleAdministrator(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    return withConfirmedTestTwoFactor($admin);
}

test('admin must identify a real target and cannot assign to self by omission', function () {
    $admin = roleAdministrator();
    $target = User::factory()->create();

    $this->actingAs($admin)->postJson('/roles/assign', ['role_name' => Role::MAESTRO])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->actingAs($admin)->postJson('/roles/assign', [
        'user_id' => 'nonexistent-user',
        'role_name' => Role::MAESTRO,
    ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

    expect($admin->fresh()->hasRole(Role::MAESTRO))->toBeFalse()
        ->and($target->fresh()->hasRole(Role::MAESTRO))->toBeFalse();
});

test('admin assigns an exact global and contextual role to another user', function () {
    $admin = roleAdministrator();
    $target = User::factory()->create();
    $payload = ['user_id' => (string) $target->getKey(), 'role_name' => Role::MAESTRO];

    $this->actingAs($admin)->post('/roles/assign', $payload)->assertRedirect();
    $this->actingAs($admin)->post('/roles/assign', [
        ...$payload,
        'scope_type' => 'council',
        'scope_id' => 'council-1',
    ])->assertRedirect();

    expect($target->fresh()->hasRole(Role::MAESTRO))->toBeTrue()
        ->and($target->fresh()->hasRole(Role::MAESTRO, 'council', 'council-1'))->toBeTrue()
        ->and($admin->fresh()->hasRole(Role::MAESTRO))->toBeFalse();
});

test('assignment rejects invalid role and invalid or incomplete scope', function (array $changes, string $field) {
    $admin = roleAdministrator();
    $target = User::factory()->create();
    $payload = ['user_id' => (string) $target->getKey(), 'role_name' => Role::ESTUDIANTE];

    $this->actingAs($admin)->postJson('/roles/assign', [...$payload, ...$changes])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($target->fresh()->roles)->toBe([]);
})->with([
    'invalid role' => [['role_name' => 'business_owner'], 'role_name'],
    'invalid scope' => [['scope_type' => 'campus', 'scope_id' => '1'], 'scope_type'],
    'scope without id' => [['scope_type' => 'business'], 'scope_id'],
    'global with id' => [['scope_id' => '1'], 'scope_id'],
]);

test('only a global admin may assign or revoke roles', function (string $actorRole, ?string $scopeType, ?string $scopeId) {
    $actor = User::factory()->create();
    $actor->assignRole($actorRole, $scopeType, $scopeId);
    if ($actor->requiresTwoFactorAuthentication()) {
        withConfirmedTestTwoFactor($actor);
    }
    $target = User::factory()->create();
    $target->assignRole(Role::ESTUDIANTE);
    $payload = ['user_id' => (string) $target->getKey(), 'role_name' => Role::ESTUDIANTE];

    $this->actingAs($actor)->post('/roles/assign', $payload)->assertForbidden();
    $this->actingAs($actor)->delete('/roles/revoke', $payload)->assertForbidden();

    expect($target->fresh()->hasRole(Role::ESTUDIANTE))->toBeTrue();
})->with([
    'student' => [Role::ESTUDIANTE, null, null],
    'teacher' => [Role::MAESTRO, null, null],
    'student manager' => [Role::STUDENT_MANAGER, null, null],
    'contextual admin' => [Role::ADMIN, 'business', 'business-1'],
]);

test('revocation removes only the exact contextual tuple and preserves legacy roles', function () {
    $admin = roleAdministrator();
    $target = User::factory()->create();
    $target->assignRole(Role::ESTUDIANTE);
    $target->assignRole(Role::ESTUDIANTE, 'business', '1');
    $target->assignRole(Role::ESTUDIANTE, 'business', '2');
    $roles = $target->roles;
    $roles[] = ['name' => 'business_owner', 'scope_type' => null, 'scope_id' => null];
    $target->roles = $roles;
    $target->save();

    $payload = ['user_id' => (string) $target->getKey(), 'role_name' => Role::ESTUDIANTE];
    $this->actingAs($admin)->delete('/roles/revoke', [
        ...$payload, 'scope_type' => 'business', 'scope_id' => '1',
    ])->assertRedirect();

    $target = $target->fresh();
    expect($target->hasRole(Role::ESTUDIANTE, 'business', '1'))->toBeFalse()
        ->and($target->hasRole(Role::ESTUDIANTE, 'business', '2'))->toBeTrue()
        ->and($target->hasRole(Role::ESTUDIANTE))->toBeTrue()
        ->and(collect($target->roles)->pluck('name')->contains('business_owner'))->toBeTrue();

    $this->actingAs($admin)->delete('/roles/revoke', $payload)->assertRedirect();
    expect($target->fresh()->hasRole(Role::ESTUDIANTE))->toBeFalse()
        ->and($target->fresh()->hasRole(Role::ESTUDIANTE, 'business', '2'))->toBeTrue();
});

test('revocation rejects invalid tuples and is safely idempotent', function () {
    $admin = roleAdministrator();
    $target = User::factory()->create();
    $payload = ['user_id' => (string) $target->getKey(), 'role_name' => Role::ESTUDIANTE];

    $this->actingAs($admin)->delete('/roles/revoke', $payload)->assertRedirect()
        ->assertSessionHas('success', 'La asignación indicada ya no existe.');
    $this->actingAs($admin)->deleteJson('/roles/revoke', [...$payload, 'scope_type' => 'business'])
        ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    $this->actingAs($admin)->deleteJson('/roles/revoke', [...$payload, 'role_name' => 'business_owner'])
        ->assertUnprocessable()->assertJsonValidationErrors('role_name');
    expect($target->fresh()->roles)->toBe([]);
});

test('admin Inertia contract exposes canonical roles, four scopes and minimal target data', function () {
    $admin = roleAdministrator();
    $target = User::factory()->create();
    Role::create(['name' => 'business_owner', 'display_name' => 'Legacy']);
    $target->roles = [['name' => 'business_owner', 'scope_type' => null, 'scope_id' => null]];
    $target->save();

    $this->actingAs($admin)->get('/roles')->assertInertia(fn ($page) => $page
        ->component('Roles/Index')
        ->where('canAssignRoles', true)
        ->where('assignableScopes', Role::VALID_SCOPE_TYPES)
        ->where('assignableRoles', array_map(static fn ($name) => ['name' => $name, 'display_name' => $name], Role::VALID_ROLES))
        ->has('assignableUsers', 2)
        ->etc());

    $response = $this->actingAs($admin)->get('/roles');
    $users = $response->viewData('page')['props']['assignableUsers'];
    $projected = collect($users)->firstWhere('id', (string) $target->getKey());
    expect(array_keys($projected))->toBe(['id', 'name', 'email', 'roles'])
        ->and($projected['roles'][0]['name'])->toBe('business_owner');
});

test('non-admin Inertia contract does not expose other users', function () {
    $student = User::factory()->create();
    $student->assignRole(Role::ESTUDIANTE);
    User::factory()->create();

    $this->actingAs($student)->get('/roles')->assertInertia(fn ($page) => $page
        ->component('Roles/Index')
        ->where('canAssignRoles', false)
        ->where('assignableUsers', [])
        ->etc());
});
