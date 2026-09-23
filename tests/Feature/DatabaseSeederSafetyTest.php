<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;

test('the default seeder preserves the development bootstrap in testing', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'test@example.com')->firstOrFail();

    expect($admin->hasRole(Role::ADMIN))->toBeTrue()
        ->and(User::where('email', 'active.student@example.com')->exists())->toBeTrue()
        ->and(Role::where('name', Role::ADMIN)->exists())->toBeTrue();
});

test('demo bootstrap is allowed only in local and testing environments', function () {
    expect(DatabaseSeeder::allowsDemoBootstrap('local'))->toBeTrue()
        ->and(DatabaseSeeder::allowsDemoBootstrap('testing'))->toBeTrue()
        ->and(DatabaseSeeder::allowsDemoBootstrap('production'))->toBeFalse()
        ->and(DatabaseSeeder::allowsDemoBootstrap('staging'))->toBeFalse();
});

test('the role catalog is seeded independently of demo accounts', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::VALID_ROLES)->sort()->values()->all())
        ->and(User::count())->toBe(0);
});

test('tests can continue assigning an admin role explicitly', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);

    expect($user->fresh()->hasRole(Role::ADMIN))->toBeTrue();
});
