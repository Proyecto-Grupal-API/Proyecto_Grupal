<?php

namespace App\Services\Directory;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\WriteConcern;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

/** One document/command. No upsert, lease, initialization, secondary read or retry loop. */
class DirectoryQuotaStore
{
    public const COLLECTION = 'identity_directory_quota_state';
    public const ADMITTED = 'admitted';
    public const EXHAUSTED = 'exhausted';
    public const UNAVAILABLE = 'unavailable';
    public const UNCERTAIN = 'uncertain';

    private const DURATIONS = ['requests_resolve' => 60000, 'requests_batch' => 60000,
        'references_minute' => 60000, 'references_day' => 86400000];

    /** Pure preparation format, NOT called to initialize a missing runtime document. */
    public static function initialState(string $deployment, string $generation, string $client, int $daily): array
    {
        foreach ([$deployment, $generation] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid quota profile.');
            }
        }
        if ($client === '' || strlen($client) > 200 || $daily < 1) {
            throw new InvalidArgumentException('Invalid quota profile.');
        }
        $windows = [];
        foreach (self::DURATIONS as $key => $duration) {
            $windows[$key] = ['used' => 0, 'starts_at' => null, 'ends_at' => null];
        }

        return ['_id' => hash('sha256', json_encode([1, $deployment, $client], JSON_THROW_ON_ERROR)),
            'schema_version' => 1, 'deployment' => $deployment, 'generation' => $generation,
            'client_hash' => hash('sha256', $client),
            'limits' => ['requests' => 30, 'references_minute' => 3000, 'daily' => $daily],
            'windows' => $windows, 'last_result' => null];
    }

    /** Pure command construction; MongoDB 7 target, server-side $$NOW throughout. */
    public static function command(string $deployment, string $generation, string $client,
        string $operation, int $references, int $daily, string $nonce): array
    {
        $initial = self::initialState($deployment, $generation, $client, $daily);
        DirectoryQuota::limits($operation, $references, $daily);
        if (preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) { throw new InvalidArgumentException('Invalid quota receipt.'); }
        $filter = array_intersect_key($initial, array_flip(['_id', 'schema_version', 'deployment', 'generation', 'client_hash']));
        $valid = [['$in' => [['$type' => '$schema_version'], ['int', 'long']]]];
        foreach ($initial['limits'] as $key => $limit) {
            $filter['limits.'.$key] = $limit;
            $valid[] = ['$in' => [['$type' => '$limits.'.$key], ['int', 'long']]];
        }
        $valid[] = ['$or' => [
            ['$eq' => [['$type' => '$last_result'], 'null']],
            ['$cond' => [['$and' => [
                ['$eq' => [['$type' => '$last_result'], 'object']],
                ['$eq' => [['$type' => '$last_result.nonce'], 'string']],
                ['$eq' => [['$type' => '$last_result.at'], 'date']],
                ['$in' => [['$type' => '$last_result.references'], ['int', 'long']]],
            ]], ['$and' => [
                ['$regexMatch' => ['input' => '$last_result.nonce', 'regex' => '^[a-f0-9]{32}$']],
                ['$in' => ['$last_result.operation', ['resolve', 'batch']]],
                ['$in' => ['$last_result.decision', [self::ADMITTED, self::EXHAUSTED]]],
                ['$gte' => ['$last_result.references', 1]], ['$lte' => ['$last_result.references', 100]],
                ['$lte' => ['$last_result.at', '$$NOW']],
            ]], false]],
        ]];
        foreach (self::DURATIONS as $key => $duration) {
            $path = '$windows.'.$key;
            $limit = str_starts_with($key, 'requests_') ? 30 : ($key === 'references_minute' ? 3000 : $daily);
            $pristine = ['$and' => [['$eq' => [['$type' => $path.'.starts_at'], 'null']],
                ['$eq' => [['$type' => $path.'.ends_at'], 'null']], ['$eq' => [$path.'.used', 0]]]];
            $dates = ['$and' => [['$eq' => [['$type' => $path.'.starts_at'], 'date']],
                ['$eq' => [['$type' => $path.'.ends_at'], 'date']]]];
            // Guard arithmetic with $cond: $and is not relied upon for short-circuiting.
            $valid[] = ['$cond' => [['$and' => [
                ['$in' => [['$type' => $path.'.used'], ['int', 'long']]], ['$or' => [$pristine, $dates]],
            ]], ['$and' => [['$gte' => [$path.'.used', 0]], ['$lte' => [$path.'.used', $limit]],
                ['$cond' => [$pristine, true, ['$and' => [['$lte' => [$path.'.starts_at', '$$NOW']],
                    ['$eq' => [$path.'.ends_at', ['$add' => [$path.'.starts_at', $duration]]]]]]]],
            ]], false]];
        }
        $filter['$expr'] = ['$and' => $valid];
        $costs = ['requests_'.$operation => [30, 1], 'references_minute' => [3000, $references], 'references_day' => [$daily, $references]];
        $allowed = []; $updates = [];
        foreach ($costs as $key => [$maximum, $cost]) {
            $path = '$windows.'.$key;
            $expired = ['$or' => [['$eq' => [['$type' => $path.'.ends_at'], 'null']], ['$lte' => [$path.'.ends_at', '$$NOW']]]];
            $effective = ['$cond' => [$expired, 0, $path.'.used']];
            // Subtraction avoids overflowing a BSON integer when the daily limit is large.
            $allowed[] = ['$lte' => [$effective, $maximum - $cost]];
            $updates['windows.'.$key] = ['$cond' => ['$_directory_quota_admit', [
                'used' => ['$add' => [$effective, $cost]],
                'starts_at' => ['$cond' => [$expired, '$$NOW', $path.'.starts_at']],
                'ends_at' => ['$cond' => [$expired, ['$add' => ['$$NOW', self::DURATIONS[$key]]], $path.'.ends_at']],
            ], $path]];
        }
        $updates['last_result'] = ['nonce' => $nonce, 'operation' => $operation, 'references' => $references,
            'at' => '$$NOW', 'decision' => ['$cond' => ['$_directory_quota_admit', self::ADMITTED, self::EXHAUSTED]]];

        return ['filter' => $filter, 'pipeline' => [
            ['$set' => ['_directory_quota_admit' => ['$and' => $allowed]]],
            ['$set' => $updates], ['$unset' => '_directory_quota_admit'],
        ], 'options' => ['upsert' => false, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            'writeConcern' => new WriteConcern('majority', 5000, true), 'maxTimeMS' => 5000,
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]];
    }

    public function consume(string $deployment, string $generation, string $client,
        string $operation, int $references, int $daily): array
    {
        try {
            $nonce = bin2hex(random_bytes(16));
            $command = self::command($deployment, $generation, $client, $operation, $references, $daily, $nonce);
            $expected = self::initialState($deployment, $generation, $client, $daily);
        } catch (Throwable) { return $this->result(self::UNAVAILABLE); }
        try {
            $state = $this->execute($command);
        } catch (Throwable) {
            // A thrown write/timeout may have consumed quota. No new attempt or refund.
            return $this->result(self::UNCERTAIN);
        }
        if ($state === null) { return $this->result(self::UNAVAILABLE); }
        try {
            return $this->confirm($state, $expected, $operation, $references, $nonce);
        } catch (Throwable) { return $this->result(self::UNCERTAIN); }
    }

    /** Only this boundary accesses MongoDB; unit doubles override it without services. */
    protected function execute(array $command): mixed
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection(self::COLLECTION)
            ->findOneAndUpdate($command['filter'], $command['pipeline'], $command['options']);
    }

    private function confirm(mixed $state, array $expected, string $operation, int $references, string $nonce): array
    {
        if (! is_array($state)) { return $this->result(self::UNCERTAIN); }
        foreach (['_id', 'schema_version', 'deployment', 'generation', 'client_hash', 'limits'] as $key) {
            if (($state[$key] ?? null) !== $expected[$key]) { return $this->result(self::UNCERTAIN); }
        }
        $receipt = $state['last_result'] ?? null;
        if (! is_array($receipt) || ($receipt['nonce'] ?? null) !== $nonce
            || ($receipt['operation'] ?? null) !== $operation || ($receipt['references'] ?? null) !== $references
            || ! ($receipt['at'] ?? null) instanceof UTCDateTime
            || ! in_array($receipt['decision'] ?? null, [self::ADMITTED, self::EXHAUSTED], true)) {
            return $this->result(self::UNCERTAIN);
        }
        $now = (int) (string) $receipt['at'];
        foreach (self::DURATIONS as $key => $duration) {
            $window = $state['windows'][$key] ?? null;
            $maximum = str_starts_with($key, 'requests_') ? 30 : ($key === 'references_minute' ? 3000 : $expected['limits']['daily']);
            if (! is_array($window) || ! is_int($window['used'] ?? null) || $window['used'] < 0 || $window['used'] > $maximum
                || ! array_key_exists('starts_at', $window) || ! array_key_exists('ends_at', $window)) {
                return $this->result(self::UNCERTAIN);
            }
            if ($window['starts_at'] === null && $window['ends_at'] === null && $window['used'] === 0) { continue; }
            if (! $window['starts_at'] instanceof UTCDateTime || ! $window['ends_at'] instanceof UTCDateTime
                || (int) (string) $window['starts_at'] > $now
                || (int) (string) $window['ends_at'] - (int) (string) $window['starts_at'] !== $duration) {
                return $this->result(self::UNCERTAIN);
            }
        }
        $costs = ['requests_'.$operation => [30, 1], 'references_minute' => [3000, $references],
            'references_day' => [$expected['limits']['daily'], $references]];
        $retry = 0;
        foreach ($costs as $key => [$maximum, $cost]) {
            $window = $state['windows'][$key];
            $end = $window['ends_at'] === null ? null : (int) (string) $window['ends_at'];
            $effective = $end === null || $end <= $now ? 0 : $window['used'];
            if ($receipt['decision'] === self::ADMITTED) {
                if ($end === null || $end <= $now || $effective < $cost) { return $this->result(self::UNCERTAIN); }
            } elseif ($effective > $maximum - $cost) {
                $retry = max($retry, $end !== null && $end > $now ? (int) ceil(($end - $now) / 1000) : intdiv(self::DURATIONS[$key], 1000));
            }
        }
        if ($receipt['decision'] === self::ADMITTED) { return $this->result(self::ADMITTED); }

        return $retry > 0 ? $this->result(self::EXHAUSTED, $retry) : $this->result(self::UNCERTAIN);
    }

    private function result(string $status, int $retry = 0): array
    {
        return ['status' => $status, 'retry_after' => $retry];
    }
}
