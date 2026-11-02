<?php

namespace App\Services\Directory;

use App\Exceptions\DirectoryFailure;

/** MongoDB admission only: no cache fallback, initialization or application retry. */
final class DirectoryQuota
{
    public function __construct(private DirectoryQuotaStore $store) {}

    public static function limits(string $operation, int $references, int $daily): array
    {
        if (! in_array($operation, ['resolve', 'batch'], true) || $references < 1 || $references > 100 || $daily < 1) {
            throw new DirectoryFailure(503, 'directory_quota_unavailable');
        }

        return [
            'requests:'.$operation => [30, 60, 1],
            'references:minute' => [3000, 60, $references],
            'references:day' => [$daily, 86400, $references],
        ];
    }

    public function consume(string $client, array $input, array $profile): void
    {
        if (config('identity_directory.quota_store_verified') !== true
            || ! is_string($deployment = config('identity_directory.quota_deployment'))
            || ! is_string($generation = config('identity_directory.quota_generation'))) {
            throw new DirectoryFailure(503, 'directory_quota_unavailable');
        }
        $result = $this->store->consume($deployment, $generation, $client,
            $input['operation'], $input['reference_count'], $profile['daily_reference_limit']);
        if ($result['status'] === DirectoryQuotaStore::ADMITTED) { return; }
        if ($result['status'] === DirectoryQuotaStore::EXHAUSTED) {
            throw new DirectoryFailure(429, 'directory_rate_limited', $result['retry_after']);
        }
        // An uncertain consumption may have been charged. Do not retry or refund it.
        throw new DirectoryFailure(503, 'directory_quota_unavailable');
    }
}
