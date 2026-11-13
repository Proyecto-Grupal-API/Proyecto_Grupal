<?php

namespace App\Services\Directory;

use App\Exceptions\DirectoryFailure;

/** Service grant policy only. A declared context never authenticates a human. */
final class DirectoryPolicy
{
    public const RESOLVE = 'identity:directory:resolve';
    public const READ = 'identity:directory:read';
    public const CONDITION = 'identity:accounts:condition:read';
    public const ENROLLMENT = 'identity:directory:enrollment:read';

    public static function scopes(array $input): array
    {
        if ($input['operation'] === 'resolve') {
            return [self::RESOLVE];
        }
        $scopes = [];
        if (in_array('identity', $input['projection'], true)) { $scopes[] = self::READ; }
        if (in_array('account_condition', $input['projection'], true)) { $scopes[] = self::CONDITION; }
        if ($input['include_enrollment']) { $scopes[] = self::ENROLLMENT; }

        return $scopes;
    }

    public static function authorize(string $client, array $tokenScopes, array $input, array $clients): array
    {
        $profile = $clients[$client] ?? null; // Exact key lookup, not Laravel's dot-path lookup.
        $grant = is_array($profile) ? ($profile[$input['operation']]['purposes'][$input['purpose']] ?? null) : null;
        if ($client === '' || ! is_array($grant)
            || ! is_int($profile['daily_reference_limit'] ?? null) || $profile['daily_reference_limit'] < 1
            || ! is_array($grant['projections'] ?? null) || ! is_array($grant['context_types'] ?? null)
            || ! in_array($input['context']['type'], $grant['context_types'], true)
            || (isset($grant['context_ids']) && (!is_array($grant['context_ids']) || !in_array($input['context']['id'],$grant['context_ids'],true)))
            || array_diff($input['projection'], $grant['projections']) !== []
            || ($input['include_enrollment'] && ($grant['enrollment'] ?? false) !== true)
            || array_diff(self::scopes($input), $tokenScopes) !== []) {
            throw new DirectoryFailure(403, 'directory_access_denied');
        }

        return $profile;
    }
}
