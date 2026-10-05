<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;

/** Offline role-representation comparison, not a capability authorization engine. */
class LegacyRoleParityService
{
    public function __construct(private LegacyRoleEntryAnalyzer $analyzer, private LegacyRoleBackfillService $backfill) {}

    public function compare(User $user, string $role, ?string $type = null, ?string $id = null): array
    {
        $decisions = $this->analyzer->analyze($user);
        $invalid = $user->trashed() || ! in_array($role, Role::VALID_ROLES, true)
            || collect($decisions)->contains(fn ($d) => $d['decision'] !== 'MIGRATE'
                && $d['category'] !== 'DUPLICATE_LEGACY_ENTRY'
                && ($d['role'] === null || $d['role'] === $role));
        if ($invalid) {
            return ['status' => 'NOT_COMPARABLE_INVALID_LEGACY', 'legacy' => null, 'shadow' => null];
        }
        $legacy = $user->hasRole($role, $type, $id);
        $shadow = false;
        if (($type === null) === ($id === null) && ($type === null || in_array($type, Role::VALID_SCOPE_TYPES, true))) {
            $shadow = RoleAssignment::where('user_id', (string) $user->getKey())
                ->where('origin', 'legacy_backfill')->where('role_key', $role)
                ->where('scope_type', $type)->where('scope_id', $id)
                ->where('is_current', true)->where('status', 'active')
                ->where('starts_at', null)->where('ends_at', null)->exists();
        }

        return ['status' => $legacy === $shadow ? 'MATCH' : 'MISMATCH', 'legacy' => $legacy, 'shadow' => $shadow];
    }

    public function report(?string $userId = null): array
    {
        $report = ['queries_compared' => 0, 'matches' => 0, 'mismatches' => 0, 'not_comparable' => 0,
            'legacy_true_shadow_false' => 0, 'legacy_false_shadow_true' => 0, 'deltas' => [],
            'reconciliation' => array_fill_keys(['IN_SYNC', 'LEGACY_PRESENT_SHADOW_MISSING',
                'LEGACY_REMOVED_SHADOW_PRESENT', 'SHADOW_CONFLICT', 'INVALID_LEGACY',
                'FOUNDATION_ROLE_IN_LEGACY_SOURCE'], 0)];
        $users = User::withTrashed()->orderBy('_id');
        if ($userId !== null) {
            $users->whereKey($userId);
        }
        foreach ($users->cursor() as $user) {
            $queries = array_map(fn ($role) => [$role, null, null], Role::VALID_ROLES);
            $keys = [];
            $decisions = $this->analyzer->analyze($user);
            foreach ($decisions as $decision) {
                if ($decision['decision'] !== 'MIGRATE') {
                    if ($decision['category'] !== 'DUPLICATE_LEGACY_ENTRY') {
                        $category = $decision['category'] === 'FOUNDATION_ROLE_IN_LEGACY_SOURCE'
                            ? $decision['category'] : 'INVALID_LEGACY';
                        $report['reconciliation'][$category]++;
                        $queries[] = [$decision['role'] ?? '__invalid_legacy__', null, null];
                    }
                    continue;
                }
                $attributes = $decision['attributes'];
                $keys[] = $attributes['source_key'];
                $state = $this->backfill->state($attributes);
                $category = match ($state) {
                    'MISSING' => 'LEGACY_PRESENT_SHADOW_MISSING',
                    'SKIP_ALREADY_MIGRATED' => 'IN_SYNC', default => 'SHADOW_CONFLICT',
                };
                $report['reconciliation'][$category]++;
                if ($category !== 'IN_SYNC') {
                    $report['deltas'][] = ['user_ref' => hash('sha256', (string) $user->getKey()),
                        'source_key' => $attributes['source_key'], 'entry' => $decision['entry'], 'category' => $category];
                }
                $queries[] = [$attributes['role_key'], $attributes['scope_type'], $attributes['scope_id']];
                if ($attributes['scope_type'] !== null) {
                    // A guaranteed different safe opaque ID, even if a fixture uses the usual sentinel.
                    $wrongId = $attributes['scope_id'] === '__parity_absent__' ? '__parity_absent_2__' : '__parity_absent__';
                    $queries[] = [$attributes['role_key'], $attributes['scope_type'], $wrongId];
                    $queries[] = [$attributes['role_key'], '__invalid_scope__', $attributes['scope_id']];
                }
            }
            foreach (RoleAssignment::where('user_id', (string) $user->getKey())->where('origin', 'legacy_backfill')->get() as $shadow) {
                if (! in_array($shadow->source_key, $keys, true)) {
                    $ambiguous = collect($decisions)->contains(fn ($d) => $d['decision'] !== 'MIGRATE'
                        && $d['category'] !== 'DUPLICATE_LEGACY_ENTRY'
                        && ($d['role'] === null || $d['role'] === $shadow->role_key));
                    // An invalid source cannot prove revocation; require human reconciliation.
                    $category = $ambiguous ? 'SHADOW_CONFLICT' : 'LEGACY_REMOVED_SHADOW_PRESENT';
                    $report['reconciliation'][$category]++;
                    $report['deltas'][] = ['user_ref' => hash('sha256', (string) $user->getKey()),
                        'source_key' => $shadow->source_key, 'entry' => null, 'category' => $category];
                }
                $queries[] = [$shadow->role_key, $shadow->scope_type, $shadow->scope_id];
            }
            foreach (array_unique(array_map(fn ($query) => json_encode($query, JSON_THROW_ON_ERROR), $queries)) as $query) {
                $result = $this->compare($user, ...json_decode($query, true));
                if ($result['status'] === 'NOT_COMPARABLE_INVALID_LEGACY') {
                    $report['not_comparable']++;
                    continue;
                }
                $report['queries_compared']++;
                $report[$result['status'] === 'MATCH' ? 'matches' : 'mismatches']++;
                if ($result['legacy'] && ! $result['shadow']) {
                    $report['legacy_true_shadow_false']++;
                } elseif (! $result['legacy'] && $result['shadow']) {
                    $report['legacy_false_shadow_true']++;
                }
            }
        }

        return $report;
    }
}
