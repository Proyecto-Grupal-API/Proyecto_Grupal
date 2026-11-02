<?php

namespace App\Services\Directory;

/** Derived reference condition, not authentication or a new persisted User state. */
final class DirectoryAccountCondition
{
    public static function classify(array $account, bool $hasProfile): ?array
    {
        if (($account['deleted_at'] ?? null) !== null) {
            return ['state' => 'deleted', 'usable' => false, 'has_student_profile' => null];
        }
        $activation = $account['account_activation_pending'] ?? null;
        $initial = $account['must_change_password'] ?? null;
        // Missing legacy flags retain existing E1 semantics; malformed values do not.
        foreach ([$activation, $initial] as $value) {
            if (! in_array($value, [null, false, true, 0, 1], true)) {
                return null;
            }
        }
        if ((bool) $activation && (bool) $initial) {
            return null; // Existing creation/reissue flows never establish this combination.
        }
        $state = $activation ? 'activation_pending' : ($initial ? 'initial_password_change_required' : 'operational');

        return ['state' => $state, 'usable' => $state === 'operational', 'has_student_profile' => $hasProfile];
    }
}
