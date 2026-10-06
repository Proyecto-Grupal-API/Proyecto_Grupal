<?php

use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\LegacyRoleBackfillService;
use App\Services\LegacyRoleEntryAnalyzer;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Only target metadata is simulated. All reads/writes use the guarded testing database.
class TestingDevelopmentRoleTransition extends LegacyRoleBackfillService
{
    public array $target = ['local', 'mongodb', 'campus_virtual'];

    protected function transitionTarget(): array
    {
        return $this->target;
    }
}

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_10_05_000200_create_legacy_role_backfill_index.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->transition = new TestingDevelopmentRoleTransition(app(LegacyRoleEntryAnalyzer::class));
    $this->subject = User::factory()->create(['roles' => [['name' => 'estudiante']]]);
});

test('normal testing apply remains allowed and local default apply remains denied', function () {
    expect(app(LegacyRoleBackfillService::class)->run(true)['created'])->toBe(1);
    $this->app['env'] = 'local';
    expect(fn () => app(LegacyRoleBackfillService::class)->run(true))->toThrow(RuntimeException::class);
    $this->artisan('identity:backfill-role-assignments', ['--apply' => true])->assertFailed();
});

test('transition requires exact target and explicit confirmations', function (array $target, ?int $count, ?string $hash) {
    $this->transition->target = $target;
    expect(fn () => $this->transition->applyDevelopmentTransition($count, $hash))
        ->toThrow(RuntimeException::class)->and(RoleAssignment::count())->toBe(0);
})->with([
    'missing count' => [['local', 'mongodb', 'campus_virtual'], null, str_repeat('a', 64)],
    'missing plan' => [['local', 'mongodb', 'campus_virtual'], 1, null],
    'zero count' => [['local', 'mongodb', 'campus_virtual'], 0, str_repeat('a', 64)],
    'wrong database' => [['local', 'mongodb', 'other'], 1, str_repeat('a', 64)],
    'wrong connection' => [['local', 'mysql', 'campus_virtual'], 1, str_repeat('a', 64)],
    'production' => [['production', 'mongodb', 'campus_virtual'], 1, str_repeat('a', 64)],
    'testing not transition' => [['testing', 'mongodb', 'campus_virtual_testing'], 1, str_repeat('a', 64)],
]);

test('valid confirmed transition preserves source provenance and never repeats generations', function () {
    $before = $this->subject->fresh()->getAttributes();
    $plan = $this->transition->developmentPlan();
    expect($plan['plan_hash'])->toBe($this->transition->developmentPlan()['plan_hash']);
    expect($this->transition->applyDevelopmentTransition(1, $plan['plan_hash'])['created'])->toBe(1);
    $assignment = RoleAssignment::first();
    $decision = app(LegacyRoleEntryAnalyzer::class)->analyze($this->subject->fresh())[0];
    expect($assignment->user_id)->toBe((string) $this->subject->getKey())
        ->and($assignment->role_key)->toBe('estudiante')->and($assignment->scope_type)->toBeNull()
        ->and($assignment->scope_id)->toBeNull()->and($assignment->status)->toBe('active')
        ->and($assignment->is_current)->toBeTrue()->and($assignment->revision)->toBe(1)
        ->and($assignment->generation)->toBe(1)->and($assignment->origin)->toBe('legacy_backfill')
        ->and($assignment->source_fingerprint)->toBe($decision['attributes']['source_fingerprint'])
        ->and($assignment->source_key)->toBe($decision['attributes']['source_key'])
        ->and($this->subject->fresh()->getAttributes())->toEqual($before)
        ->and($this->transition->developmentPlan()['dry_run']['assignments_to_create'])->toBe(0);
    expect(fn () => $this->transition->applyDevelopmentTransition(1, $plan['plan_hash']))->toThrow(RuntimeException::class);
    expect(RoleAssignment::count())->toBe(1)->and(RoleAssignment::first()->generation)->toBe(1);
});

test('wrong count and stale source or inventory reject the whole confirmed plan', function (string $change) {
    $plan = $this->transition->developmentPlan();
    if ($change === 'source') {
        $this->subject->assignRole('maestro');
    } elseif ($change === 'metadata') {
        $this->subject->roles = [['name' => 'estudiante', 'assigned_at' => '2020-01-01']];
        $this->subject->save();
    } elseif ($change === 'inventory') {
        User::factory()->create(['roles' => [['name' => 'admin']]]);
    }
    expect(fn () => $this->transition->applyDevelopmentTransition($change === 'count' ? 2 : 1, $plan['plan_hash']))
        ->toThrow(RuntimeException::class)->and(RoleAssignment::count())->toBe(0);
})->with(['count', 'source', 'metadata', 'inventory']);

test('anomalies foundation conflicts and stale shadows deny transition', function (string $case) {
    if ($case === 'foundation' || $case === 'invalid' || $case === 'duplicate') {
        $this->subject->roles = match ($case) {
            'foundation' => [['name' => 'business_owner']],
            'invalid' => [['name' => 'unknown']],
            'duplicate' => [['name' => 'estudiante'], ['name' => 'estudiante']],
        };
        $this->subject->save();
    } elseif ($case === 'conflict') {
        RoleAssignment::create(['user_id' => (string) $this->subject->getKey(), 'role_key' => 'estudiante', 'status' => 'active']);
    } else {
        app(LegacyRoleBackfillService::class)->run(true);
        $this->subject->revokeRole('estudiante');
    }
    $count = RoleAssignment::count();
    $plan = $this->transition->developmentPlan();
    expect(fn () => $this->transition->applyDevelopmentTransition(1, $plan['plan_hash']))->toThrow(RuntimeException::class);
    expect(RoleAssignment::count())->toBe($count);
})->with(['foundation', 'invalid', 'duplicate', 'conflict', 'stale']);

test('post cutover marker survives service recreation and denies all legacy apply paths', function () {
    $plan = $this->transition->developmentPlan();
    app(LegacyRoleBackfillService::class)->markAuthorityCutover();
    $fresh = new TestingDevelopmentRoleTransition(app(LegacyRoleEntryAnalyzer::class));
    expect(fn () => $fresh->applyDevelopmentTransition(1, $plan['plan_hash']))->toThrow(RuntimeException::class)
        ->and(fn () => app(LegacyRoleBackfillService::class)->run(true))->toThrow(RuntimeException::class)
        ->and(fn () => app(LegacyRoleBackfillService::class)->materialize($this->subject, app(LegacyRoleEntryAnalyzer::class)->analyze($this->subject)[0]))->toThrow(RuntimeException::class)
        ->and(RoleAssignment::count())->toBe(0);
});

test('transition cannot bypass index guards or expose a public unguarded materializer', function () {
    $plan = $this->transition->developmentPlan();
    (require database_path('migrations/2026_10_05_000200_create_legacy_role_backfill_index.php'))->down();
    expect(fn () => $this->transition->applyDevelopmentTransition(1, $plan['plan_hash']))->toThrow(RuntimeException::class)
        ->and((new ReflectionMethod(LegacyRoleBackfillService::class, 'persistDecision'))->isPrivate())->toBeTrue()
        ->and(RoleAssignment::count())->toBe(0);
});

test('storage failure rolls back all assignments and completion receipt', function () {
    User::factory()->create(['roles' => [['name' => 'maestro']]]);
    $plan = $this->transition->developmentPlan();
    $event = 'eloquent.creating: '.RoleAssignment::class;
    $writes = 0;
    Event::listen($event, function () use (&$writes) {
        if (++$writes === 2) {
            throw new RuntimeException('Simulated second write failure');
        }
    });
    try {
        expect(fn () => $this->transition->applyDevelopmentTransition(2, $plan['plan_hash']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(RoleAssignment::count())->toBe(0)
        ->and(DB::connection('mongodb')->getCollection('identity_transition_state')->findOne(['_id' => 'development_legacy_transition']))->toBeNull();
});

test('command refuses missing confirmations filtered inventory and conflicting modes', function () {
    $this->artisan('identity:backfill-role-assignments', ['--development-transition' => true, '--apply' => true])->assertFailed();
    $this->artisan('identity:backfill-role-assignments', ['--development-transition' => true, '--user' => (string) $this->subject->getKey()])->assertFailed();
    $this->artisan('identity:backfill-role-assignments', ['--expected-create' => '1'])->assertFailed();
    $this->artisan('identity:backfill-role-assignments', ['--development-transition' => true, '--apply' => true, '--dry-run' => true])->assertFailed();
    expect(RoleAssignment::count())->toBe(0);
});

test('command carries explicit plan confirmation through the real service write boundary', function () {
    $this->app->instance(LegacyRoleBackfillService::class, $this->transition);
    $plan = $this->transition->developmentPlan();
    $this->artisan('identity:backfill-role-assignments', ['--development-transition' => true, '--dry-run' => true])->assertSuccessful();
    $this->artisan('identity:backfill-role-assignments', ['--development-transition' => true,
        '--apply' => true, '--expected-create' => '1', '--plan-hash' => $plan['plan_hash']])->assertSuccessful();
    expect(RoleAssignment::count())->toBe(1);
});
