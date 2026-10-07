<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Traversable;

/** Transformation only. Never changes the legacy source or grants authorization. */
class LegacyRoleEntryAnalyzer
{
    public function analyze(User $user): array
    {
        $raw = $user->getRawOriginal('roles');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [$this->issue(0, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE')];
            }
        }
        $raw = $raw instanceof Traversable ? iterator_to_array($raw) : $raw;
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [$this->issue(0, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE')];
        }
        $decisions = [];
        foreach ($raw as $index => $entry) {
            $decisions[] = $this->entry($user, $entry, $index);
        }
        $groups = [];
        foreach ($decisions as $index => $decision) {
            if ($decision['decision'] === 'MIGRATE') {
                $groups[$decision['attributes']['source_key']][] = $index;
            }
        }
        foreach ($groups as $indices) {
            $fingerprints = array_unique(array_map(fn ($i) => $decisions[$i]['attributes']['source_fingerprint'], $indices));
            if (count($fingerprints) > 1) {
                foreach ($indices as $i) {
                    $decisions[$i] = $this->issue($i, 'QUARANTINE', 'CONFLICTING_LEGACY_ENTRY', $decisions[$i]['role']);
                }
            } else {
                foreach (array_slice($indices, 1) as $i) {
                    $decisions[$i] = $this->issue($i, 'QUARANTINE', 'DUPLICATE_LEGACY_ENTRY', $decisions[$i]['role']);
                }
            }
        }

        return $decisions;
    }

    private function entry(User $user, mixed $entry, int $index): array
    {
        $entry = $entry instanceof Traversable ? iterator_to_array($entry) : $entry;
        if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
            return $this->issue($index, 'INVALID', 'MALFORMED_ENTRY');
        }
        $role = $entry['name'];
        if (in_array($role, Role::NEW_ROLES, true)) {
            return $this->issue($index, 'QUARANTINE', 'FOUNDATION_ROLE_IN_LEGACY_SOURCE', $role);
        }
        if (! in_array($role, Role::VALID_ROLES, true)) {
            return $this->issue($index, 'INVALID', 'UNKNOWN_ROLE', $role);
        }
        if (! $user->exists || $user->trashed()) {
            return $this->issue($index, 'INVALID', 'INVALID_USER_REFERENCE', $role);
        }
        if (array_diff(array_keys($entry), ['name', 'scope_type', 'scope_id', 'assigned_at', 'assigned_by'])) {
            return $this->issue($index, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE', $role);
        }
        $type = $entry['scope_type'] ?? null;
        $id = $entry['scope_id'] ?? null;
        if ($type !== null && ! in_array($type, Role::VALID_SCOPE_TYPES, true)) {
            return $this->issue($index, 'INVALID', 'INVALID_SCOPE_TYPE', $role);
        }
        if ($type !== null && $id === null) {
            return $this->issue($index, 'INVALID', 'MISSING_SCOPE_ID', $role);
        }
        if ($type === null && $id !== null) {
            return $this->issue($index, 'INVALID', 'UNEXPECTED_SCOPE', $role);
        }
        if ($id !== null) {
            if (! is_string($id) && ! is_int($id) && ! $id instanceof ObjectId) {
                return $this->issue($index, 'INVALID', 'UNEXPECTED_SCOPE', $role);
            }
            // hasRole compares stringified IDs. Never trim or case-fold external IDs.
            $id = (string) $id;
            if ($id === '' || strlen($id) > 100 || ! mb_check_encoding($id, 'UTF-8')
                || preg_match('/[\x00-\x20\x7f]/', $id)) {
                return $this->issue($index, 'INVALID', 'UNEXPECTED_SCOPE', $role);
            }
        }
        $date = $entry['assigned_at'] ?? null;
        if ($date !== null) {
            try {
                if ($date instanceof UTCDateTime) {
                    $date = $date->toDateTime();
                }
                if (! $date instanceof DateTimeInterface && (! is_string($date)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ].*)?$/', $date))) {
                    return $this->issue($index, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE', $role);
                }
                $parsed = CarbonImmutable::parse($date);
                if ((is_string($date) && $parsed->format('Y-m-d') !== substr($date, 0, 10))
                    || ((int) $parsed->format('u')) % 1000 !== 0) {
                    // MongoDB timestamps have millisecond precision; do not silently lose history.
                    return $this->issue($index, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE', $role);
                }
                $date = $parsed->utc();
            } catch (\Throwable) {
                return $this->issue($index, 'UNSUPPORTED', 'UNSUPPORTED_LEGACY_SHAPE', $role);
            }
        }
        $actor = $entry['assigned_by'] ?? null;
        if ($actor !== null) {
            if ((! is_string($actor) && ! $actor instanceof ObjectId)
                || ! ($actorUser = User::whereKey((string) $actor)->first())) {
                return $this->issue($index, 'INVALID', 'INVALID_USER_REFERENCE', $role);
            }
            $actor = (string) $actorUser->getKey();
        }
        $tuple = [(string) $user->getKey(), $role, $type, $id];
        $key = hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR));

        return ['entry' => $index, 'decision' => 'MIGRATE', 'category' => null, 'role' => $role,
            'attributes' => [
                'user_id' => $tuple[0], 'role_key' => $role, 'scope_type' => $type, 'scope_id' => $id,
                'status' => 'active', 'starts_at' => null, 'ends_at' => null,
                'assigned_at' => $date, 'assigned_by' => $actor, 'reason' => 'legacy_backfill',
                'origin' => 'legacy_backfill', 'source_key' => $key,
                'source_fingerprint' => hash('sha256', json_encode([$tuple, $date?->format('Y-m-d\TH:i:s.u\Z'), $actor], JSON_THROW_ON_ERROR)),
            ]];
    }

    private function issue(int $index, string $decision, string $category, ?string $role = null): array
    {
        return ['entry' => $index, 'decision' => $decision, 'category' => $category, 'role' => $role];
    }
}
