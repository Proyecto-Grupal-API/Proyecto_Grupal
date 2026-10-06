<?php

namespace App\Services;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit maintenance operation. Nothing in the request authorization path calls this. */
class LegacyRoleBackfillService
{
    public function __construct(private LegacyRoleEntryAnalyzer $analyzer) {}

    public function run(bool $apply = false, ?string $userId = null): array
    {
        if ($apply) {
            $this->assertSafeApply();
        }
        $report = ['mode' => $apply ? 'APPLY' : 'DRY_RUN', 'users_scanned' => 0,
            'legacy_entries_scanned' => 0, 'migratable' => 0, 'already_migrated' => 0,
            'quarantined' => 0, 'invalid' => 0, 'unsupported' => 0,
            'assignments_to_create' => 0, 'created' => 0, 'conflicts' => 0, 'issues' => []];
        $query = User::withTrashed()->orderBy('_id');
        if ($userId !== null) {
            $query->whereKey($userId);
        }
        foreach ($query->cursor() as $user) {
            $report['users_scanned']++;
            foreach ($this->analyzer->analyze($user) as $decision) {
                $report['legacy_entries_scanned']++;
                if ($decision['decision'] !== 'MIGRATE') {
                    $report[strtolower($decision['decision']) === 'quarantine' ? 'quarantined' : strtolower($decision['decision'])]++;
                    $report['issues'][] = $this->issue($user, $decision, $decision['category']);
                    continue;
                }
                $report['migratable']++;
                $state = $this->state($decision['attributes']);
                if ($state === 'MISSING') {
                    $report['assignments_to_create']++;
                    if ($apply) {
                        $state = $this->materialize($user, $decision);
                    }
                }
                if ($state === 'CREATED') {
                    $report['created']++;
                } elseif ($state === 'SKIP_ALREADY_MIGRATED') {
                    $report['already_migrated']++;
                } elseif ($state !== 'MISSING') {
                    $report['conflicts']++;
                    $report['issues'][] = $this->issue($user, $decision, $state);
                }
            }
        }

        return $report;
    }

    public function assertSafeApply(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mongodb'
            || DB::connection('mongodb')->getDatabaseName() !== 'campus_virtual_testing') {
            throw new \RuntimeException('Apply is restricted to campus_virtual_testing in this phase.');
        }
        $this->assertPreCutover();
        $this->assertIndexes();
    }

    private function assertIndexes(): void
    {
        $indexes = [];
        foreach (DB::connection('mongodb')->getCollection('role_assignments')->listIndexes() as $index) {
            $indexes[$index->getName()] = $index;
        }
        $identity = ['user_id' => 1, 'role_key' => 1, 'scope_type' => 1, 'scope_id' => 1];
        foreach (['legacy_source_unique' => [['source_key' => 1], ['origin' => 'legacy_backfill']],
            'current_identity_unique' => [$identity, ['is_current' => true]],
            'identity_generation_unique' => [[...$identity, 'generation' => 1], []]] as $name => [$keys, $partial]) {
            $index = $indexes[$name] ?? null;
            if (! $index || ! $index->isUnique() || $index->isSparse() || $index->getKey() !== $keys
                || (array) ($index['partialFilterExpression'] ?? []) !== $partial) {
                throw new \RuntimeException('Required authorization uniqueness indexes must be installed before apply.');
            }
        }
    }

    /** Rechecking rejects an already-stale plan. Unique indexes arbitrate concurrent inserts.
     * Legacy can still change after this check: reconciliation reports that drift, never authorizes it.
     */
    public function materialize(User $snapshot, array $decision): string
    {
        $this->assertSafeApply();

        return $this->persistDecision($snapshot, $decision);
    }

    private function persistDecision(User $snapshot, array $decision): string
    {
        $fresh = User::whereKey($snapshot->getKey())->first();
        $attributes = $decision['attributes'];
        $eligible = $fresh && collect($this->analyzer->analyze($fresh))->contains(fn ($entry) =>
            $entry['decision'] === 'MIGRATE'
            && $entry['attributes']['source_fingerprint'] === $attributes['source_fingerprint']);
        if (! $eligible) {
            return 'STALE_LEGACY_SOURCE';
        }
        $state = $this->state($attributes);
        if ($state !== 'MISSING') {
            return $state;
        }
        try {
            RoleAssignment::create($attributes);

            return 'CREATED';
        } catch (\Throwable $exception) {
            // A concurrent winner is accepted only with the same provenance and intact lifecycle.
            // Never expose a driver message (it may contain document values).
            if ((int) $exception->getCode() === 11000 || str_contains($exception->getMessage(), 'E11000')) {
                return $this->state($attributes) === 'SKIP_ALREADY_MIGRATED'
                    ? 'SKIP_ALREADY_MIGRATED' : 'SHADOW_CONFLICT';
            }

            return 'STORAGE_ERROR';
        }
    }

    /** Read-only confirmation binds the entire source and shadow inventory, not just a count. */
    public function developmentPlan(): array
    {
        $inventory = [];
        foreach (User::withTrashed()->orderBy('_id')->cursor() as $user) {
            $inventory[] = [(string) $user->getKey(), $user->trashed(), $this->analyzer->analyze($user)];
        }
        $shadows = RoleAssignment::orderBy('_id')->get()->map(fn ($record) => $record->getAttributes())->all();

        return ['plan_hash' => hash('sha256', serialize([$inventory, $shadows])),
            'dry_run' => $this->run(), 'parity' => app(LegacyRoleParityService::class)->report()];
    }

    protected function transitionTarget(): array
    {
        return [app()->environment(), config('database.default'), DB::connection('mongodb')->getDatabaseName()];
    }

    private function assertPreCutover(): void
    {
        if (DB::connection('mongodb')->getCollection('identity_transition_state')->findOne(['_id' => 'rbac_authority_cutover'])) {
            throw new \RuntimeException('Legacy apply is forbidden after authority cutover.');
        }
    }

    /** Append-only marker support. R-C must never call this; the actual cutover activates it. */
    public function markAuthorityCutover(): void
    {
        if (! in_array($this->transitionTarget(), [['local', 'mongodb', 'campus_virtual'], ['testing', 'mongodb', 'campus_virtual_testing']], true)) {
            throw new \RuntimeException('Invalid cutover marker target.');
        }
        DB::connection('mongodb')->getCollection('identity_transition_state')->insertOne([
            '_id' => 'rbac_authority_cutover', 'activated_at' => new \MongoDB\BSON\UTCDateTime(),
        ]);
    }

    /** One-shot, pre-cutover maintenance only. The normal testing apply guard is unchanged. */
    public function applyDevelopmentTransition(?int $expectedCreate, ?string $planHash): array
    {
        if ($this->transitionTarget() !== ['local', 'mongodb', 'campus_virtual']
            || $expectedCreate === null || $expectedCreate < 1 || ! is_string($planHash)
            || ! preg_match('/^[a-f0-9]{64}$/', $planHash)) {
            throw new \RuntimeException('Explicit local target, positive expected count and plan hash are required.');
        }
        $this->assertPreCutover();
        $this->assertIndexes();

        return DB::connection('mongodb')->transaction(function () use ($expectedCreate, $planHash) {
            $this->assertPreCutover();
            $state = DB::connection('mongodb')->getCollection('identity_transition_state');
            if ($state->findOne(['_id' => 'development_legacy_transition'])) {
                throw new \RuntimeException('Development legacy transition already completed.');
            }
            $plan = $this->developmentPlan();
            $dry = $plan['dry_run'];
            $parity = $plan['parity'];
            $reconciliation = $parity['reconciliation'];
            if (! hash_equals($planHash, $plan['plan_hash']) || $dry['assignments_to_create'] !== $expectedCreate
                || $dry['quarantined'] || $dry['invalid'] || $dry['unsupported'] || $dry['conflicts']
                || $parity['not_comparable'] || $parity['legacy_false_shadow_true']
                || $parity['mismatches'] !== $expectedCreate || $parity['legacy_true_shadow_false'] !== $expectedCreate
                || $reconciliation['LEGACY_PRESENT_SHADOW_MISSING'] !== $expectedCreate
                || $reconciliation['LEGACY_REMOVED_SHADOW_PRESENT'] || $reconciliation['SHADOW_CONFLICT']
                || $reconciliation['INVALID_LEGACY'] || $reconciliation['FOUNDATION_ROLE_IN_LEGACY_SOURCE']) {
                throw new \RuntimeException('Stale or unsafe development transition plan.');
            }
            $created = 0;
            foreach (User::withTrashed()->orderBy('_id')->cursor() as $user) {
                foreach ($this->analyzer->analyze($user) as $decision) {
                    if ($decision['decision'] === 'MIGRATE' && $this->state($decision['attributes']) === 'MISSING') {
                        if ($this->persistDecision($user, $decision) !== 'CREATED') {
                            throw new \RuntimeException('Transition write failed; rolling back batch.');
                        }
                        $created++;
                    }
                }
            }
            $after = $this->developmentPlan();
            if ($created !== $expectedCreate || $after['dry_run']['assignments_to_create'] !== 0
                || $after['dry_run']['conflicts'] || $after['parity']['mismatches']) {
                throw new \RuntimeException('Transition postcondition failed; rolling back batch.');
            }
            $state->insertOne(['_id' => 'development_legacy_transition', 'plan_hash' => $planHash,
                'created' => $created, 'completed_at' => new \MongoDB\BSON\UTCDateTime()]);

            return ['mode' => 'DEVELOPMENT_TRANSITION', 'created' => $created, 'postflight' => $after];
        });
    }

    public function state(array $attributes): string
    {
        $source = RoleAssignment::where('origin', 'legacy_backfill')->where('source_key', $attributes['source_key'])->first();
        $current = RoleAssignment::where('user_id', $attributes['user_id'])->where('role_key', $attributes['role_key'])
            ->where('scope_type', $attributes['scope_type'])->where('scope_id', $attributes['scope_id'])
            ->where('is_current', true)->first();
        if ($source) {
            $matches = $current && (string) $current->getKey() === (string) $source->getKey()
                && $source->status === 'active' && $source->revision === 1
                && $source->starts_at === null && $source->ends_at === null
                && $source->source_fingerprint === $attributes['source_fingerprint']
                && $source->assigned_by === $attributes['assigned_by']
                && $source->assigned_at?->format('U.u') === $attributes['assigned_at']?->format('U.u');

            return $matches ? 'SKIP_ALREADY_MIGRATED' : 'SHADOW_CONFLICT';
        }

        return $current ? 'SHADOW_CONFLICT' : 'MISSING';
    }

    private function issue(User $user, array $decision, string $category): array
    {
        // --user resolves this stable reference without dumping names, emails, scopes or raw entries.
        return ['user_ref' => hash('sha256', (string) $user->getKey()), 'entry' => $decision['entry'],
            'category' => $category, 'decision' => $decision['decision'] === 'MIGRATE' ? 'QUARANTINE' : $decision['decision']];
    }
}
