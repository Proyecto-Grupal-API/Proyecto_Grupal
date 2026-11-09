<?php

use App\Models\Role;
use App\Models\User;

test('an authenticated student cannot assign the global admin role to themselves', function () {
    $student = User::factory()->create();
    $student->assignRole(Role::ESTUDIANTE);
    $rolesBefore = $student->fresh()->roles;

    $this->actingAs($student)->postJson('/roles/assign', [
        'user_id' => (string) $student->getKey(),
        'role_name' => Role::ADMIN,
    ])->assertForbidden();

    expect($student->fresh()->roles)->toEqual($rolesBefore)
        ->and($student->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

test('a contextual admin cannot convert its assignment into global role administration', function (array $scope) {
    $actor = User::factory()->create();
    $actor->assignRole(Role::ADMIN, 'business', 'A');
    withConfirmedTestTwoFactor($actor);
    $target = User::factory()->create();
    $actorRolesBefore = $actor->fresh()->roles;

    $this->actingAs($actor)->postJson('/roles/assign', [
        'user_id' => (string) $target->getKey(),
        'role_name' => Role::ADMIN,
        ...$scope,
    ])->assertForbidden();

    expect($actor->fresh()->roles)->toEqual($actorRolesBefore)
        ->and($actor->fresh()->hasRole(Role::ADMIN))->toBeFalse()
        ->and($target->fresh()->roles)->toBe([]);
})->with([
    'omitted scope' => [[]],
    'null scope' => [['scope_type' => null, 'scope_id' => null]],
    'own context' => [['scope_type' => 'business', 'scope_id' => 'A']],
]);

test('client supplied actor and target aliases cannot authorize a role change', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::ESTUDIANTE);
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $target = User::factory()->create();
    $actorRolesBefore = $actor->fresh()->roles;

    $this->actingAs($actor)->postJson('/roles/assign', [
        'user_id' => (string) $target->getKey(),
        'target_user_id' => (string) $actor->getKey(),
        'actor_id' => (string) $admin->getKey(),
        'role_name' => Role::ADMIN,
    ])->assertForbidden();

    expect($actor->fresh()->roles)->toEqual($actorRolesBefore)
        ->and($target->fresh()->roles)->toBe([])
        ->and($actor->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

test('profile updates cannot mass assign roles or replace the authenticated target', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::ESTUDIANTE);
    $target = User::factory()->create();
    $actorRolesBefore = $actor->fresh()->roles;
    $targetBefore = $target->fresh()->toArray();

    $this->actingAs($actor)->patch('/profile', [
        'name' => 'Audit Student',
        'email' => $actor->email,
        'roles' => [['name' => Role::ADMIN, 'scope_type' => null, 'scope_id' => null]],
        'role' => Role::ADMIN,
        'is_admin' => true,
        'user_id' => (string) $target->getKey(),
    ])->assertRedirect();

    expect($actor->fresh()->name)->toBe('Audit Student')
        ->and($actor->fresh()->roles)->toEqual($actorRolesBefore)
        ->and($target->fresh()->toArray())->toEqual($targetBefore);
});
