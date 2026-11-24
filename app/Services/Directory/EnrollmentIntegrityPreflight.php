<?php

namespace App\Services\Directory;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Preflight de sólo lectura que exige el contrato del directorio (docs/integration/E1-DIRECTORY-D1-D4-LOCAL.md, D1) antes de
 * atestar `enrollment_integrity_verified`: unicidad e integridad de user_id/enrollment_number, normalización histórica
 * (incluidas variantes de mayúsculas o espacios) y ausencia de perfiles huérfanos. No repara ni migra datos, no cambia
 * configuración y nunca devuelve matrículas, nombres ni identificadores: sólo conteos por comprobación.
 */
final class EnrollmentIntegrityPreflight
{
    /** @return array{passed: bool, profiles: int, checks: array<string, array{passed: bool, count: int}>} */
    public function run(): array
    {
        $collection = DB::connection('mongodb')->getCollection('student_profiles');
        $indexes = [];
        foreach ($collection->listIndexes() as $index) {
            $keys = array_keys((array) $index->getKey());
            if (count($keys) === 1 && $index->isUnique()) {
                $indexes[] = $keys[0];
            }
        }
        $checks = [
            'unique_index_user_id' => ['passed' => in_array('user_id', $indexes, true), 'count' => 0],
            'unique_index_enrollment_number' => ['passed' => in_array('enrollment_number', $indexes, true), 'count' => 0],
        ];
        $profiles = 0; $nonCanonical = 0; $invalidUser = 0; $orphans = 0;
        $seenEnrollment = []; $seenUser = []; $duplicateEnrollment = 0; $duplicateUser = 0; $userIds = [];
        // Raw documents: an integrity scan must see the stored values, not model casts.
        foreach ($collection->find([], ['projection' => ['user_id' => 1, 'enrollment_number' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]) as $profile) {
            $profiles++;
            $raw = $profile['enrollment_number'] ?? null;
            $canonical = DirectoryInput::enrollment($raw);
            if (! is_string($raw) || $canonical === null || $canonical !== $raw) {
                $nonCanonical++;
            }
            // Variantes históricas: dos perfiles que normalizan a la misma matrícula aunque su texto literal difiera.
            $key = $canonical ?? ('invalid:'.$profiles);
            isset($seenEnrollment[$key]) ? $duplicateEnrollment++ : $seenEnrollment[$key] = true;
            $user = $profile['user_id'] ?? null;
            $user = is_string($user) ? $user : (is_object($user) ? (string) $user : null);
            if ($user === null || ! DirectoryInput::identifier($user)) {
                $invalidUser++;

                continue;
            }
            isset($seenUser[$user]) ? $duplicateUser++ : $seenUser[$user] = true;
            $userIds[] = $user;
        }
        foreach (array_chunk(array_values(array_unique($userIds)), 500) as $chunk) {
            // Same lookup as the directory batch: soft-deleted accounts still exist and are not orphans.
            $found = User::withTrashed()->whereIn('_id', $chunk)->get(['_id'])->map(fn (User $u) => (string) $u->getKey())->all();
            $orphans += count(array_diff($chunk, $found));
        }
        $checks += [
            'canonical_enrollment_numbers' => ['passed' => $nonCanonical === 0, 'count' => $nonCanonical],
            'no_duplicate_enrollment_after_normalization' => ['passed' => $duplicateEnrollment === 0, 'count' => $duplicateEnrollment],
            'valid_user_ids' => ['passed' => $invalidUser === 0, 'count' => $invalidUser],
            'one_profile_per_user' => ['passed' => $duplicateUser === 0, 'count' => $duplicateUser],
            'no_orphan_profiles' => ['passed' => $orphans === 0, 'count' => $orphans],
        ];

        return ['passed' => ! in_array(false, array_column($checks, 'passed'), true), 'profiles' => $profiles, 'checks' => $checks];
    }
}
