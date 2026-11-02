<?php

namespace App\Services\Directory;

/** Closed DTO construction; never serialize an Eloquent model or its appends. */
final class DirectoryProjection
{
    public static function item(int $index, mixed $id, ?array $account, array $profiles, array $input): array
    {
        $item = ['index' => $index, 'requested_user_id' => DirectoryInput::identifier($id) ? $id : null];
        $conditionRequested = in_array('account_condition', $input['projection'], true);
        $identityRequested = in_array('identity', $input['projection'], true);
        $empty = ($identityRequested ? ['identity' => null] : []) + ($conditionRequested ? ['account_condition' => null] : []);
        if (! DirectoryInput::identifier($id)) { return $item + ['result' => 'invalid_id'] + $empty; }
        if ($account === null) { return $item + ['result' => 'not_found'] + $empty; }
        $condition = DirectoryAccountCondition::classify($account, $profiles !== []);
        if ($condition === null || (count($profiles) > 1 && $condition['state'] !== 'deleted')) {
            return $item + ['result' => 'unavailable'] + $empty;
        }
        if ($condition['state'] === 'deleted') {
            return $item + ['result' => $conditionRequested ? 'deleted' : 'not_found']
                + ($identityRequested ? ['identity' => null] : [])
                + ($conditionRequested ? ['account_condition' => $condition] : []);
        }
        $result = $item + ['result' => 'found'];
        if ($identityRequested) {
            $name = $account['name'] ?? null;
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
                return $item + ['result' => 'unavailable'] + $empty;
            }
            $identity = ['user_id' => $id, 'display_name' => $name];
            if ($input['include_enrollment']) {
                $enrollment = $profiles[0]['enrollment_number'] ?? null;
                if ($profiles !== [] && (! is_string($enrollment) || DirectoryInput::enrollment($enrollment) !== $enrollment)) {
                    return $item + ['result' => 'unavailable'] + $empty;
                }
                $identity['enrollment_number'] = $enrollment;
            }
            $result['identity'] = $identity;
        }
        if ($conditionRequested) { $result['account_condition'] = $condition; }

        return $result;
    }
}
