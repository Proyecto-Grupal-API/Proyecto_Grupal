<?php

use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LegacyRoleBackfillService;
use App\Services\LegacyRoleEntryAnalyzer;
use App\Services\LegacyRoleParityService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_10_05_000200_create_legacy_role_backfill_index.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->backfill = app(LegacyRoleBackfillService::class);
    $this->analyzer = app(LegacyRoleEntryAnalyzer::class);
    $this->parity = app(LegacyRoleParityService::class);
});

function legacyBackfillUser(mixed $roles): User
{
    $user = User::factory()->create();
    DB::connection('mongodb')->getCollection('users')->updateOne(
        ['_id' => new ObjectId((string) $user->getKey())], ['$set' => ['roles' => $roles]]
    );

    return $user->fresh();
}

test('dry run has no persistence or catalog side effects and is the command default', function () {
    $user = legacyBackfillUser([['name' => 'estudiante']]);
    $before = $user->getAttributes();
    $report = $this->backfill->run();
    expect($report['mode'])->toBe('DRY_RUN')->and($report['assignments_to_create'])->toBe(1)
        ->and($report['created'])->toBe(0)->and(RoleAssignment::count())->toBe(0)
        ->and(BusinessMembership::count())->toBe(0)->and($user->fresh()->getAttributes())->toEqual($before)
        ->and(Role::count())->toBe(16)->and(Permission::count())->toBe(8)->and(RolePermission::count())->toBe(13);
    // The pre-backfill parity mismatch is reported, not hidden with a legacy fallback.
    $this->artisan('identity:backfill-role-assignments')->assertFailed();
    expect(RoleAssignment::count())->toBe(0);
});

test('all six legacy roles preserve global and contextual semantics', function (string $role, ?string $scope) {
    $user = legacyBackfillUser([['name' => $role, 'scope_type' => $scope, 'scope_id' => $scope ? 'opaque-context' : null]]);
    $before = $user->getAttributes();
    expect($this->backfill->run(true)['created'])->toBe(1);
    $record = RoleAssignment::first();
    expect($record->role_key)->toBe($role)->and($record->scope_type)->toBe($scope)
        ->and($record->scope_id)->toBe($scope ? 'opaque-context' : null)
        ->and($record->status)->toBe('active')->and($record->generation)->toBe(1)
        ->and($record->revision)->toBe(1)->and($record->is_current)->toBeTrue()
        ->and($record->starts_at)->toBeNull()->and($record->ends_at)->toBeNull()
        ->and($record->assigned_at)->toBeNull()->and($record->assigned_by)->toBeNull()
        ->and($record->origin)->toBe('legacy_backfill')->and(strlen($record->source_key))->toBe(64)
        ->and($user->fresh()->getAttributes())->toEqual($before)
        ->and($this->parity->compare($user, $role, $scope, $scope ? 'opaque-context' : null)['status'])->toBe('MATCH')
        ->and($this->parity->report()['mismatches'])->toBe(0)
        ->and($this->parity->report()['legacy_false_shadow_true'])->toBe(0);
})->with(array_merge(...array_map(fn ($role) => array_map(fn ($type) => [$role, $type], [null, ...Role::VALID_SCOPE_TYPES]), Role::VALID_ROLES)));

test('BSON arrays documents historical JSON and missing scope keys are supported', function (string $format) {
    $entries = [['name' => 'estudiante', 'assigned_at' => '2020-03-04 05:06:07']];
    $roles = match ($format) {
        'json' => json_encode($entries), 'bson' => new BSONArray([new BSONDocument($entries[0])]), default => $entries,
    };
    $user = legacyBackfillUser($roles);
    expect($this->backfill->run(true)['created'])->toBe(1)
        ->and(RoleAssignment::first()->assigned_at->format('Y-m-d H:i:s'))->toBe('2020-03-04 05:06:07')
        ->and($this->parity->compare($user, 'estudiante')['status'])->toBe('MATCH');
})->with(['array', 'json', 'bson']);

test('scope ObjectId and numeric IDs retain exact legacy string comparison', function (mixed $id) {
    $user = legacyBackfillUser([['name' => 'maestro', 'scope_type' => 'service', 'scope_id' => $id]]);
    $this->backfill->run(true);
    expect(RoleAssignment::first()->scope_id)->toBe((string) $id)
        ->and($this->parity->compare($user, 'maestro', 'service', (string) $id)['status'])->toBe('MATCH');
})->with([['EXTERNAL-AbC'], [new ObjectId('507f1f77bcf86cd799439011')], [42]]);

test('historical actor and BSON timestamp are preserved without fabricating history', function () {
    $actor = User::factory()->create();
    legacyBackfillUser([['name' => 'estudiante', 'assigned_by' => new ObjectId((string) $actor->getKey()),
        'assigned_at' => new UTCDateTime(1600000000000)]]);
    $this->backfill->run(true);
    expect(RoleAssignment::first()->assigned_by)->toBe((string) $actor->getKey())
        ->and(RoleAssignment::first()->assigned_at->getTimestamp())->toBe(1600000000);
});

test('invalid and ambiguous entries fail closed without changing source', function (mixed $entry, string $category) {
    $user = legacyBackfillUser([$entry]);
    $before = $user->getAttributes();
    $report = $this->backfill->run(true);
    expect($report['created'])->toBe(0)->and($report['issues'][0]['category'])->toBe($category)
        ->and(RoleAssignment::count())->toBe(0)->and($user->fresh()->getAttributes())->toEqual($before)
        ->and($this->parity->report()['not_comparable'])->toBeGreaterThan(0);
})->with([
    [['name' => 'unknown'], 'UNKNOWN_ROLE'],
    ['admin', 'MALFORMED_ENTRY'],
    [[], 'MALFORMED_ENTRY'],
    [['name' => 'admin', 'scope_type' => 'warehouse', 'scope_id' => 'x'], 'INVALID_SCOPE_TYPE'],
    [['name' => 'admin', 'scope_type' => 'business'], 'MISSING_SCOPE_ID'],
    [['name' => 'admin', 'scope_id' => 'x'], 'UNEXPECTED_SCOPE'],
    [['name' => 'admin', 'scope_type' => 'business', 'scope_id' => ' x '], 'UNEXPECTED_SCOPE'],
    [['name' => 'admin', 'scope_type' => 'business', 'scope_id' => ['x']], 'UNEXPECTED_SCOPE'],
    [['name' => 'admin', 'assigned_by' => 'missing'], 'INVALID_USER_REFERENCE'],
    [['name' => 'admin', 'assigned_at' => 'yesterday'], 'UNSUPPORTED_LEGACY_SHAPE'],
    [['name' => 'admin', 'assigned_at' => '2020-02-30'], 'UNSUPPORTED_LEGACY_SHAPE'],
    [['name' => 'admin', 'assigned_at' => '2020-02-20 01:02:03.123456'], 'UNSUPPORTED_LEGACY_SHAPE'],
    [['name' => 'admin', 'ends_at' => '2027-01-01'], 'UNSUPPORTED_LEGACY_SHAPE'],
]);

test('foundation names in legacy are security anomalies not migration inputs', function (string $role) {
    legacyBackfillUser([['name' => $role]]);
    $report = $this->backfill->run(true);
    expect($report['issues'][0]['category'])->toBe('FOUNDATION_ROLE_IN_LEGACY_SOURCE')
        ->and(RoleAssignment::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
})->with(Role::NEW_ROLES);

test('unsupported containers and deleted users are reported without assignments', function (mixed $roles) {
    legacyBackfillUser($roles);
    expect($this->backfill->run(true)['unsupported'])->toBe(1)->and(RoleAssignment::count())->toBe(0);
})->with([['{invalid'], [42], [['name' => 'admin']]]);

test('soft deleted legacy subjects are never materialized', function () {
    $user = legacyBackfillUser([['name' => 'admin']]);
    $user->delete();
    expect($this->backfill->run(true)['issues'][0]['category'])->toBe('INVALID_USER_REFERENCE')
        ->and(RoleAssignment::count())->toBe(0)
        ->and($this->parity->compare($user, 'admin')['status'])->toBe('NOT_COMPARABLE_INVALID_LEGACY');
});

test('identical duplicates migrate once and three applies do not invent generations', function () {
    $entry = ['name' => 'estudiante', 'scope_type' => 'business', 'scope_id' => 'same'];
    legacyBackfillUser([$entry, $entry]);
    expect($this->backfill->run(true)['created'])->toBe(1);
    $id = (string) RoleAssignment::first()->getKey();
    foreach ([1, 2] as $repeat) {
        $report = $this->backfill->run(true);
        expect($report['created'])->toBe(0)->and($report['already_migrated'])->toBe(1)
            ->and($report['quarantined'])->toBe(1);
    }
    expect(RoleAssignment::count())->toBe(1)->and((string) RoleAssignment::first()->getKey())->toBe($id)
        ->and(RoleAssignment::first()->generation)->toBe(1);
});

test('duplicate identities with conflicting history are quarantined as a group', function () {
    legacyBackfillUser([['name' => 'admin', 'assigned_at' => '2020-01-01'], ['name' => 'admin', 'assigned_at' => '2021-01-01']]);
    $report = $this->backfill->run(true);
    expect($report['quarantined'])->toBe(2)->and(array_column($report['issues'], 'category'))->toBe(['CONFLICTING_LEGACY_ENTRY', 'CONFLICTING_LEGACY_ENTRY'])
        ->and(RoleAssignment::count())->toBe(0);
});

test('other origin current assignments are never adopted overwritten or revoked', function () {
    $user = legacyBackfillUser([['name' => 'admin']]);
    $external = RoleAssignment::create(['user_id' => (string) $user->getKey(), 'role_key' => 'admin', 'status' => 'active']);
    $before = $external->fresh()->getAttributes();
    expect($this->backfill->run(true)['conflicts'])->toBe(1)
        ->and($this->parity->report()['reconciliation']['SHADOW_CONFLICT'])->toBe(1)
        ->and($external->fresh()->getAttributes())->toEqual($before)->and(RoleAssignment::count())->toBe(1);
});

test('stale shadow revisions and revoked history are conflicts not new generations', function (string $transition) {
    legacyBackfillUser([['name' => 'admin']]);
    $this->backfill->run(true);
    RoleAssignment::first()->transitionTo($transition);
    expect($this->backfill->run(true)['conflicts'])->toBe(1)
        ->and(RoleAssignment::count())->toBe(1)->and(RoleAssignment::first()->revision)->toBe(2);
})->with(['suspended', 'revoked']);

test('precomputed stale legacy plan is rejected before persistence', function () {
    $user = legacyBackfillUser([['name' => 'estudiante']]);
    $plan = $this->analyzer->analyze($user)[0];
    $user->revokeRole('estudiante');
    expect($this->backfill->materialize($user, $plan))->toBe('STALE_LEGACY_SOURCE')->and(RoleAssignment::count())->toBe(0);
});

test('overlapping materializers resolve an insert winner without duplicate current or generation', function () {
    $user = legacyBackfillUser([['name' => 'estudiante']]);
    $plan = $this->analyzer->analyze($user)[0];
    $entered = false;
    $event = 'eloquent.creating: '.RoleAssignment::class;
    Event::listen($event, function () use (&$entered, $user, $plan) {
        if (! $entered) {
            $entered = true;
            expect($this->backfill->materialize($user, $plan))->toBe('CREATED');
        }
    });
    try {
        expect($this->backfill->materialize($user, $plan))->toBe('SKIP_ALREADY_MIGRATED');
    } finally {
        Event::forget($event);
    }
    expect(RoleAssignment::count())->toBe(1)->and(RoleAssignment::first()->generation)->toBe(1);
});

test('legacy writers remain single source while rerun incorporates additions and reports removals', function () {
    $user = legacyBackfillUser([['name' => 'estudiante']]);
    $this->backfill->run(true);
    $user->assignRole('maestro', 'service', 'new');
    expect(RoleAssignment::count())->toBe(1)->and($this->parity->report()['reconciliation']['LEGACY_PRESENT_SHADOW_MISSING'])->toBe(1);
    expect($this->backfill->run(true)['created'])->toBe(1)->and($this->parity->report()['mismatches'])->toBe(0);
    $user->revokeRole('estudiante');
    $report = $this->parity->report();
    expect($report['reconciliation']['LEGACY_REMOVED_SHADOW_PRESENT'])->toBe(1)
        ->and($report['legacy_false_shadow_true'])->toBe(1)
        ->and($user->fresh()->hasRole('estudiante'))->toBeFalse()->and(RoleAssignment::count())->toBe(2);
    $this->backfill->run(true);
    expect(RoleAssignment::count())->toBe(2)->and(RoleAssignment::where('role_key', 'estudiante')->first()->status)->toBe('active');
});

test('role matrix compares multiple scopes roles wrong contexts and empty users independently', function () {
    $user = legacyBackfillUser([
        ['name' => 'estudiante'],
        ['name' => 'maestro', 'scope_type' => 'business', 'scope_id' => 'a'],
        ['name' => 'maestro', 'scope_type' => 'business', 'scope_id' => 'b'],
        ['name' => 'servicio_cafeteria', 'scope_type' => 'business', 'scope_id' => 'a'],
    ]);
    legacyBackfillUser([]);
    $this->backfill->run(true);
    foreach ([['estudiante', null, null], ['admin', null, null], ['maestro', 'business', 'a'],
        ['maestro', 'business', 'b'], ['maestro', 'business', 'wrong'], ['maestro', 'service', 'a'],
        ['maestro', null, null], ['maestro', 'business', null], ['servicio_cafeteria', 'business', 'a']] as $query) {
        expect($this->parity->compare($user, ...$query)['status'])->toBe('MATCH');
    }
    $report = $this->parity->report();
    expect($report['mismatches'])->toBe(0)->and($report['not_comparable'])->toBe(0)
        ->and($report['legacy_false_shadow_true'])->toBe(0)->and($report['reconciliation']['IN_SYNC'])->toBe(4)
        ->and(BusinessMembership::count())->toBe(0);
});

test('controlled dataset dry apply reconcile reports anomalies without granting to force parity', function () {
    legacyBackfillUser([['name' => 'estudiante'], ['name' => 'maestro', 'scope_type' => 'business', 'scope_id' => 'a'],
        ['name' => 'maestro', 'scope_type' => 'business', 'scope_id' => 'b'],
        ['name' => 'servicio_cafeteria', 'scope_type' => 'business', 'scope_id' => 'a'], ['name' => 'estudiante']]);
    legacyBackfillUser([['name' => 'unknown'], 'malformed', ['name' => 'admin', 'scope_type' => 'service'], ['name' => 'business_owner']]);
    legacyBackfillUser([]);
    $dry = $this->backfill->run();
    expect($dry['users_scanned'])->toBe(3)->and($dry['legacy_entries_scanned'])->toBe(9)
        ->and($dry['assignments_to_create'])->toBe(4)->and($dry['quarantined'])->toBe(2)->and($dry['invalid'])->toBe(3);
    expect($this->backfill->run(true)['created'])->toBe(4);
    $after = $this->backfill->run();
    $parity = $this->parity->report();
    expect($after['already_migrated'])->toBe(4)->and($after['assignments_to_create'])->toBe(0)
        ->and($parity['mismatches'])->toBe(0)->and($parity['legacy_false_shadow_true'])->toBe(0)
        ->and($parity['reconciliation']['IN_SYNC'])->toBe(4)->and($parity['reconciliation']['INVALID_LEGACY'])->toBe(3)
        ->and($parity['reconciliation']['FOUNDATION_ROLE_IN_LEGACY_SOURCE'])->toBe(1);
    // Only sanitized aggregates, never fixture identifiers or raw roles, are emitted as evidence.
    fwrite(STDOUT, "\nCONTROLLED_PARITY=".json_encode($parity)."\n");
});

test('command apply works only in testing and outputs no user pii or entry values', function () {
    $user = legacyBackfillUser([['name' => 'estudiante', 'scope_type' => 'service', 'scope_id' => 'PRIVATE-CONTEXT']]);
    $this->artisan('identity:backfill-role-assignments', ['--apply' => true, '--user' => (string) $user->getKey()])->assertSuccessful();
    expect(RoleAssignment::count())->toBe(1);
    $this->artisan('identity:backfill-role-assignments', ['--dry-run' => true, '--apply' => true])->assertFailed();
    $this->artisan('identity:backfill-role-assignments', ['--user' => 'invalid'])->assertFailed();
    $this->app['env'] = 'local';
    expect(fn () => $this->backfill->run(true))->toThrow(RuntimeException::class);
});

test('apply without prerequisite indexes fails before creating shadows', function () {
    legacyBackfillUser([['name' => 'estudiante']]);
    (require database_path('migrations/2026_10_05_000200_create_legacy_role_backfill_index.php'))->down();
    expect(fn () => $this->backfill->run(true))->toThrow(RuntimeException::class)->and(RoleAssignment::count())->toBe(0);
});

test('permission catalog cannot grant legacy roles and new roles remain nonassignable', function () {
    $user = legacyBackfillUser([]);
    RoleAssignment::create(['user_id' => (string) $user->getKey(), 'role_key' => 'admin', 'status' => 'active']);
    expect($user->hasRole('admin'))->toBeFalse()->and($user->requiresTwoFactorAuthentication())->toBeFalse()
        ->and($this->parity->compare($user, 'admin')['shadow'])->toBeFalse();
    foreach (Role::NEW_ROLES as $role) {
        expect(fn () => $user->assignRole($role))->toThrow(InvalidArgumentException::class);
    }
    expect($user->fresh()->roles)->toBe([])->and(Permission::count())->toBe(8)->and(RolePermission::count())->toBe(13);
});

test('storage rejection is not reported as successful migration and rerun can resume', function () {
    legacyBackfillUser([['name' => 'estudiante']]);
    Role::where('name', 'estudiante')->delete();
    $report = $this->backfill->run(true);
    expect($report['created'])->toBe(0)->and($report['issues'][0]['category'])->toBe('STORAGE_ERROR')
        ->and(RoleAssignment::count())->toBe(0);
    $this->seed(AuthorizationFoundationSeeder::class);
    expect($this->backfill->run(true)['created'])->toBe(1);
});

test('changed legacy metadata is a conflict rather than an implicit history rewrite', function () {
    $user = legacyBackfillUser([['name' => 'estudiante', 'assigned_at' => '2020-01-01']]);
    $this->backfill->run(true);
    DB::connection('mongodb')->getCollection('users')->updateOne(
        ['_id' => new ObjectId((string) $user->getKey())],
        ['$set' => ['roles' => [['name' => 'estudiante', 'assigned_at' => '2021-01-01']]]]
    );
    expect($this->backfill->run(true)['conflicts'])->toBe(1)
        ->and(RoleAssignment::count())->toBe(1)->and(RoleAssignment::first()->assigned_at->format('Y'))->toBe('2020');
});

test('structured issue reports disclose no original identifiers or unknown role values', function () {
    $user = legacyBackfillUser([['name' => 'PRIVATE-UNKNOWN-ROLE', 'scope_id' => 'PRIVATE-SCOPE']]);
    $report = json_encode($this->backfill->run());
    expect($report)->not->toContain($user->email)->not->toContain((string) $user->getKey())
        ->not->toContain('PRIVATE-UNKNOWN-ROLE')->not->toContain('PRIVATE-SCOPE');
});

test('misconfigured named indexes cannot bypass required uniqueness guarantees', function () {
    legacyBackfillUser([['name' => 'estudiante']]);
    $collection = DB::connection('mongodb')->getCollection('role_assignments');
    $collection->dropIndex('legacy_source_unique');
    $collection->createIndex(['reason' => 1], ['name' => 'legacy_source_unique', 'unique' => true]);
    expect(fn () => $this->backfill->run(true))->toThrow(RuntimeException::class)->and(RoleAssignment::count())->toBe(0);
});

test('invalid current source is shadow conflict and not falsely classified as a revocation', function () {
    $user = legacyBackfillUser([['name' => 'estudiante']]);
    $this->backfill->run(true);
    DB::connection('mongodb')->getCollection('users')->updateOne(
        ['_id' => new ObjectId((string) $user->getKey())],
        ['$set' => ['roles' => [['name' => 'estudiante', 'scope_type' => 'business']]]]
    );
    $report = $this->parity->report();
    expect($report['reconciliation']['SHADOW_CONFLICT'])->toBe(1)
        ->and($report['reconciliation']['LEGACY_REMOVED_SHADOW_PRESENT'])->toBe(0)
        ->and($report['deltas'][0]['category'])->toBe('SHADOW_CONFLICT')->and(RoleAssignment::count())->toBe(1);
});
