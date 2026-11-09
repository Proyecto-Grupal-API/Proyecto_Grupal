<?php

use App\Models\RoleAssignment;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\LegacyRoleBackfillService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_10_05_000200_create_legacy_role_backfill_index.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
});

test('legacy only admin never authorizes policy middleware display or required two factor', function () {
    $user = User::factory()->create(['roles' => [['name' => 'admin']]]);
    expect($user->hasRole('admin'))->toBeFalse()
        ->and((new RolePolicy)->assign($user))->toBeFalse()
        ->and($user->requiresTwoFactorAuthentication())->toBeFalse()
        ->and($user->effectiveRoles())->toBe([]);
    $this->actingAs($user)->post('/roles/assign', ['user_id' => (string) $user->getKey(), 'role_name' => 'maestro'])->assertForbidden();
});

test('assignment only admin authorizes independently of marker and legacy data', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    expect($user->fresh()->roles)->toBe([])->and($user->hasRole('admin'))->toBeTrue()
        ->and($user->requiresTwoFactorAuthentication())->toBeTrue()
        ->and((new RolePolicy)->assign($user))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('roles')
        ->and($user->effectiveRoles()[0]['name'])->toBe('admin');
});

test('assignment lifecycle is the only temporal authority', function (string $case, bool $allow) {
    $this->freezeTime();
    $user = User::factory()->create(['roles' => [['name' => 'admin']]]);
    $record = RoleAssignment::create([
        'user_id' => (string) $user->getKey(), 'role_key' => 'admin',
        'status' => $case === 'pending' ? 'pending' : 'active',
        'starts_at' => $case === 'future' ? now()->addSecond() : now()->subSecond(),
        'ends_at' => $case === 'expired' ? now() : null,
    ]);
    if (in_array($case, ['suspended', 'revoked'], true)) {
        $record->transitionTo($case);
    }
    if ($case === 'non-current') {
        DB::connection('mongodb')->getCollection('role_assignments')->updateOne(
            ['_id' => new ObjectId((string) $record->getKey())], ['$set' => ['is_current' => false]]
        );
    }
    expect($user->hasRole('admin'))->toBe($allow)
        ->and($user->requiresTwoFactorAuthentication())->toBe($allow);
})->with(['active' => ['active', true], 'pending' => ['pending', false],
    'suspended' => ['suspended', false], 'revoked' => ['revoked', false],
    'expired' => ['expired', false], 'future' => ['future', false], 'non-current' => ['non-current', false]]);

test('assign revoke regrant preserves legacy data and lifecycle history', function () {
    $user = User::factory()->create(['roles' => [['name' => 'maestro']]]);
    $before = serialize(DB::connection('mongodb')->getCollection('users')->findOne([
        '_id' => new ObjectId((string) $user->getKey()),
    ]));
    $user->assignRole('estudiante', 'business', 'A');
    $user->assignRole('estudiante', 'business', 'A');
    expect(RoleAssignment::count())->toBe(1)->and($user->hasRole('estudiante', 'business', 'A'))->toBeTrue()
        ->and($user->hasRole('estudiante', 'business', 'B'))->toBeFalse()
        ->and($user->hasRole('estudiante'))->toBeFalse()->and($user->hasRole('maestro'))->toBeFalse();
    expect($user->revokeRole('estudiante', 'business', 'A'))->toBeTrue();
    $old = RoleAssignment::first();
    expect($old->status)->toBe('revoked')->and($old->revision)->toBe(2)->and($old->is_current)->toBeFalse();
    $user->assignRole('estudiante', 'business', 'A');
    expect(RoleAssignment::count())->toBe(2)->and(RoleAssignment::where('is_current', true)->first()->generation)->toBe(2)
        ->and(serialize(DB::connection('mongodb')->getCollection('users')->findOne([
            '_id' => new ObjectId((string) $user->getKey()),
        ])) === $before)->toBeTrue();
});

test('cutover marker blocks development backfill even with transition confirmations', function () {
    app(LegacyRoleBackfillService::class)->markAuthorityCutover();
    $this->artisan('identity:backfill-role-assignments', ['--apply' => true, '--development-transition' => true,
        '--expected-create' => '4', '--plan-hash' => str_repeat('a', 64)])->assertFailed();
    expect(RoleAssignment::count())->toBe(0);
});
