<?php

namespace App\Services\Directory;

use App\Exceptions\DirectoryFailure;
use App\Support\StudentIdentityInput;

/** Pure validation. Invalid batch references are results, not query operators. */
final class DirectoryInput
{
    public const MAX_BATCH = 100;
    public const MAX_REQUEST_BYTES = 32768;
    public const MAX_RESPONSE_BYTES = 131072;

    public static function identifier(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 100 && preg_match('/^[^\x00-\x20\x7f]+$/uD', $id) === 1;
    }

    public static function enrollment(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 128) {
            return null;
        }
        $normalized = StudentIdentityInput::normalize(['enrollment_number' => $value])['enrollment_number'];

        return preg_match('/^[A-Z0-9-]{1,30}$/D', $normalized) === 1 ? $normalized : null;
    }

    public static function parse(array $body, string $operation): array
    {
        self::check(in_array($operation, ['resolve', 'batch'], true));
        $allowed = $operation === 'resolve'
            ? ['enrollment_number', 'purpose', 'context']
            : ['user_ids', 'projection', 'include_enrollment', 'purpose', 'context'];
        self::check(array_diff(array_keys($body), $allowed) === []);
        self::check(is_string($body['purpose'] ?? null)
            && preg_match('/^[a-z][a-z_]{0,63}$/D', $body['purpose']) === 1);
        $context = $body['context'] ?? null;
        self::check(is_array($context) && count($context) === 2
            && array_diff(array_keys($context), ['type', 'id']) === []
            && in_array($context['type'] ?? null, ['organization', 'event', 'scholarship_call', 'committee', 'campaign', 'roster'], true)
            && self::identifier($context['id'] ?? null));
        $input = ['operation' => $operation, 'purpose' => $body['purpose'], 'context' => $context];
        if ($operation === 'resolve') {
            $enrollment = self::enrollment($body['enrollment_number'] ?? null);
            self::check($enrollment !== null);

            return $input + ['enrollment_number' => $enrollment, 'projection' => ['identity'], 'include_enrollment' => true, 'reference_count' => 1];
        }
        $ids = $body['user_ids'] ?? null;
        self::check(is_array($ids) && array_is_list($ids) && count($ids) >= 1 && count($ids) <= self::MAX_BATCH);
        $projection = $body['projection'] ?? ['identity'];
        self::check(is_array($projection) && array_is_list($projection) && count($projection) >= 1 && count($projection) <= 2);
        foreach ($projection as $field) {
            self::check(is_string($field) && in_array($field, ['identity', 'account_condition'], true));
        }
        self::check(count(array_unique($projection)) === count($projection));
        $enrollment = $body['include_enrollment'] ?? false;
        self::check(is_bool($enrollment) && (! $enrollment || in_array('identity', $projection, true)));

        return $input + ['user_ids' => $ids, 'projection' => $projection, 'include_enrollment' => $enrollment, 'reference_count' => count($ids)];
    }

    private static function check(bool $valid): void
    {
        if (! $valid) {
            throw new DirectoryFailure(422, 'directory_invalid_request');
        }
    }
}
