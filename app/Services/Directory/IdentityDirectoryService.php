<?php

namespace App\Services\Directory;

use App\Exceptions\DirectoryFailure;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use MongoDB\Driver\Exception\Exception as MongoException;

final class IdentityDirectoryService
{
    private const USER_FIELDS = ['_id', 'name', 'deleted_at', 'account_activation_pending', 'must_change_password'];

    private function account(User $user): array
    {
        $result = [];
        foreach (self::USER_FIELDS as $field) {
            $result[$field] = $field === '_id' ? (string) $user->getKey() : $user->getRawOriginal($field);
        }

        return $result;
    }

    public function resolve(array $input): array
    {
        // Does not scan/repair historical data. A separate read-only preflight must attest it.
        if (config('identity_directory.enrollment_integrity_verified') !== true) {
            throw new DirectoryFailure(503, 'directory_integrity_not_attested');
        }
        try {
            $profiles = StudentProfile::where('enrollment_number', $input['enrollment_number'])
                ->limit(2)->get(['user_id', 'enrollment_number']);
            if ($profiles->count() > 1) { throw new DirectoryFailure(409, 'directory_ambiguous_reference'); }
            if ($profiles->isEmpty()) { return ['result' => 'not_found', 'identity' => null]; }
            $id = $profiles[0]->user_id;
            if (! DirectoryInput::identifier($id)) { throw new DirectoryFailure(409, 'directory_inconsistent_reference'); }
            $user = User::withTrashed()->whereKey($id)->first(self::USER_FIELDS);
            if (! $user) { throw new DirectoryFailure(409, 'directory_inconsistent_reference'); }
            $condition = DirectoryAccountCondition::classify($this->account($user), true);
            if ($condition === null) { throw new DirectoryFailure(503, 'directory_condition_unavailable'); }
            if (! $condition['usable']) { return ['result' => 'not_usable', 'identity' => null]; }
            $item = DirectoryProjection::item(0, (string) $user->getKey(), $this->account($user),
                [['enrollment_number' => $profiles[0]->enrollment_number]], $input);
            if ($item['result'] !== 'found') { throw new DirectoryFailure(503, 'directory_condition_unavailable'); }

            return ['result' => 'resolved', 'identity' => $item['identity']];
        } catch (QueryException|MongoException) {
            throw new DirectoryFailure(503, 'directory_unavailable');
        }
    }

    public function batch(array $input): array
    {
        $ids = array_values(array_unique(array_filter($input['user_ids'], DirectoryInput::identifier(...))));
        $accounts = []; $profiles = [];
        try {
            if ($ids !== []) {
                foreach (User::withTrashed()->whereIn('_id', $ids)->get(self::USER_FIELDS) as $user) {
                    $accounts[(string) $user->getKey()] = $this->account($user);
                }
                if ($input['include_enrollment'] || in_array('account_condition', $input['projection'], true)) {
                    // Bound even in an unhealthy database with missing unique indexes.
                    $rows = StudentProfile::whereIn('user_id', $ids)->limit(count($ids) + 1)->get(['user_id', 'enrollment_number']);
                    if ($rows->count() > count($ids)) { throw new DirectoryFailure(503, 'directory_inconsistent_reference'); }
                    foreach ($rows as $profile) {
                        $profiles[(string) $profile->user_id][] = ['enrollment_number' => $profile->enrollment_number];
                    }
                }
            }
        } catch (QueryException|MongoException) {
            // Never return invented missing identities after a failed query.
            throw new DirectoryFailure(503, 'directory_unavailable');
        }
        $items = [];
        foreach ($input['user_ids'] as $index => $id) {
            $valid = DirectoryInput::identifier($id);
            $items[] = DirectoryProjection::item($index, $id, $valid ? ($accounts[$id] ?? null) : null,
                $valid ? ($profiles[$id] ?? []) : [], $input);
        }

        return ['items' => $items];
    }
}
