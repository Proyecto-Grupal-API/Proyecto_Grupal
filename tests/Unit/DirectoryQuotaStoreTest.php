<?php

namespace Tests\Unit;

use App\Exceptions\DirectoryFailure;
use App\Services\Directory\DirectoryQuota;
use App\Services\Directory\DirectoryQuotaStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure expression double, NOT a MongoDB atomicity/concurrency or durability test. */
final class QuotaExpressionStore extends DirectoryQuotaStore
{
    public int $now = 1700000000000;
    public int $calls = 0;
    public string $behavior = 'normal';
    public array $lastCommand = [];

    public function __construct(public ?array $state) {}

    protected function execute(array $command): mixed
    {
        $this->calls++;
        $this->lastCommand = $command;
        if ($this->behavior === 'timeout') { throw new RuntimeException('synthetic-timeout'); }
        if ($this->behavior === 'empty') { return null; }
        if ($this->behavior === 'rejected') { return false; }
        if ($this->state === null) { return null; }
        foreach ($command['filter'] as $key => $value) {
            if ($key === '$expr') {
                if (! $this->evaluate($value, $this->state)) { return null; }
            } elseif ($this->path($this->state, $key) !== $value) { return null; }
        }
        foreach ($command['pipeline'] as $stage) {
            if (isset($stage['$set'])) {
                $previous = $this->state;
                foreach ($stage['$set'] as $key => $value) {
                    $this->set($this->state, $key, $this->evaluate($value, $previous));
                }
            } else { unset($this->state[$stage['$unset']]); }
        }
        if ($this->behavior === 'lost_response') { throw new RuntimeException('synthetic-lost-response'); }
        $response = $this->state;
        if ($this->behavior === 'nonce') { $response['last_result']['nonce'] = str_repeat('0', 32); }
        if ($this->behavior === 'false_exhausted') { $response['last_result']['decision'] = self::EXHAUSTED; }
        if ($this->behavior === 'malformed') { return ['last_result' => null]; }

        return $response;
    }

    private function path(array $document, string $path): mixed
    {
        $value = $document;
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) { return new \stdClass; }
            $value = $value[$part];
        }

        return $value;
    }

    private function set(array &$document, string $path, mixed $value): void
    {
        $target = &$document;
        $parts = explode('.', $path);
        foreach ($parts as $part) { $target = &$target[$part]; }
        $target = $value;
    }

    private function evaluate(mixed $expression, array $document): mixed
    {
        if ($expression === '$$NOW') { return new UTCDateTime($this->now); }
        if (is_string($expression) && str_starts_with($expression, '$')) { return $this->path($document, substr($expression, 1)); }
        if (! is_array($expression)) { return $expression; }
        $operator = array_key_first($expression);
        if (! is_string($operator) || ! str_starts_with($operator, '$')) {
            return array_map(fn ($value) => $this->evaluate($value, $document), $expression);
        }
        $arguments = $expression[$operator];
        if ($operator === '$cond') {
            return $this->evaluate($arguments[$this->evaluate($arguments[0], $document) ? 1 : 2], $document);
        }
        if ($operator === '$type') {
            $value = $this->evaluate($arguments, $document);

            return match (true) {
                $value instanceof UTCDateTime => 'date', $value instanceof \stdClass => 'missing',
                $value === null => 'null', is_int($value) => 'long', is_float($value) => 'double',
                is_string($value) => 'string', is_bool($value) => 'bool',
                is_array($value) && ! array_is_list($value) => 'object', default => 'array',
            };
        }
        $values = array_map(fn ($value) => $this->evaluate($value, $document), $arguments);
        $number = fn ($value) => $value instanceof UTCDateTime ? (int) (string) $value : $value;

        return match ($operator) {
            '$and' => ! in_array(false, array_map('boolval', $values), true),
            '$or' => in_array(true, array_map('boolval', $values), true),
            '$in' => in_array($values[0], $values[1], true),
            '$eq' => $number($values[0]) === $number($values[1]),
            '$lte' => $number($values[0]) <= $number($values[1]),
            '$gte' => $number($values[0]) >= $number($values[1]),
            '$add' => $values[0] instanceof UTCDateTime
                ? new UTCDateTime($number($values[0]) + $values[1]) : array_sum($values),
            '$regexMatch' => preg_match('/'.$values['regex'].'/D', $values['input']) === 1,
            default => throw new RuntimeException('Unsupported test expression.'),
        };
    }
}

final class DirectoryQuotaStoreTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['identity_directory' => [
            'quota_store_verified' => true, 'quota_deployment' => 'pure-test', 'quota_generation' => 'generation-1',
        ]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    private function store(int $daily = 10000): QuotaExpressionStore
    {
        return new QuotaExpressionStore(DirectoryQuotaStore::initialState('pure-test', 'generation-1', 'synthetic-client', $daily));
    }

    private function consume(QuotaExpressionStore $store, string $operation = 'batch', int $positions = 1, int $daily = 10000): array
    {
        return $store->consume('pure-test', 'generation-1', 'synthetic-client', $operation, $positions, $daily);
    }

    public function test_library_accepts_pipeline_and_explicit_acknowledgement_without_connecting(): void
    {
        $command = DirectoryQuotaStore::command('pure-test', 'generation-1', 'synthetic-client', 'batch', 2, 100, str_repeat('a', 32));
        new FindOneAndUpdate('synthetic_database', DirectoryQuotaStore::COLLECTION, $command['filter'], $command['pipeline'], $command['options']);
        self::assertFalse($command['options']['upsert']);
        self::assertSame(FindOneAndUpdate::RETURN_DOCUMENT_AFTER, $command['options']['returnDocument']);
        self::assertSame('majority', $command['options']['writeConcern']->getW());
        self::assertTrue($command['options']['writeConcern']->getJournal());
        self::assertSame(5000, $command['options']['writeConcern']->getWtimeout());
        self::assertStringContainsString('$$NOW', json_encode([$command['filter'], $command['pipeline']]));
    }

    public function test_admission_updates_all_and_only_affected_windows_in_one_command(): void
    {
        $store = $this->store();
        self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store, positions: 3)['status']);
        self::assertSame(1, $store->calls);
        self::assertSame(1, $store->state['windows']['requests_batch']['used']);
        self::assertSame(0, $store->state['windows']['requests_resolve']['used']);
        self::assertSame(3, $store->state['windows']['references_minute']['used']);
        self::assertSame(3, $store->state['windows']['references_day']['used']);
        self::assertArrayNotHasKey('_directory_quota_admit', $store->state);
    }

    public function test_modality_exhaustion_does_not_partially_charge_reference_budgets(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 30; $i++) { self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store)['status']); }
        $windows = $store->state['windows'];
        self::assertSame(['status' => DirectoryQuotaStore::EXHAUSTED, 'retry_after' => 60], $this->consume($store));
        self::assertEquals($windows, $store->state['windows']);
        self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store, 'resolve')['status']);
    }

    public function test_reference_minute_budget_is_shared_by_both_modalities(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 29; $i++) { $this->consume($store, positions: 100); }
        $this->consume($store, 'resolve');
        self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store, positions: 99)['status']);
        self::assertSame(3000, $store->state['windows']['references_minute']['used']);
        self::assertSame(DirectoryQuotaStore::EXHAUSTED, $this->consume($store, 'resolve')['status']);
        self::assertSame(3000, $store->state['windows']['references_day']['used']);
    }

    public function test_daily_budget_is_joint_and_a_batch_larger_than_the_entire_budget_is_denied(): void
    {
        $store = $this->store(3);
        self::assertSame(DirectoryQuotaStore::EXHAUSTED, $this->consume($store, positions: 4, daily: 3)['status']);
        self::assertSame(0, $store->state['windows']['references_day']['used']);
        self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store, positions: 2, daily: 3)['status']);
        self::assertSame(DirectoryQuotaStore::ADMITTED, $this->consume($store, 'resolve', daily: 3)['status']);
        self::assertSame(86400, $this->consume($store, daily: 3)['retry_after']);
    }

    public function test_windows_expire_independently_and_at_the_exact_end_instant(): void
    {
        $store = $this->store(); $start = $store->now;
        $this->consume($store, 'resolve');
        $store->now += 30000; $this->consume($store, positions: 2);
        $store->now += 30000; $this->consume($store, 'resolve');
        self::assertSame(1, $store->state['windows']['requests_resolve']['used']);
        self::assertSame($start + 60000, (int) (string) $store->state['windows']['requests_resolve']['starts_at']);
        self::assertSame($start + 30000, (int) (string) $store->state['windows']['requests_batch']['starts_at']);
        self::assertSame(1, $store->state['windows']['references_minute']['used']);
        self::assertSame(4, $store->state['windows']['references_day']['used']);
        self::assertSame($start, (int) (string) $store->state['windows']['references_day']['starts_at']);
        $store->now = $start + 86400000 - 30000; $this->consume($store, positions: 2);
        $store->now = $start + 86400000; $this->consume($store);
        self::assertSame(1, $store->state['windows']['references_day']['used']);
        self::assertSame(2, $store->state['windows']['requests_batch']['used']);
        self::assertSame(3, $store->state['windows']['references_minute']['used']);
    }

    public static function invalidStates(): iterable
    {
        foreach (['absent', 'generation', 'schema', 'negative', 'missing_field', 'string_counter', 'double_counter',
            'bad_dates', 'future', 'receipt', 'limits'] as $case) { yield $case => [$case]; }
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_is_never_an_automatic_new_budget(string $case): void
    {
        $store = $this->store();
        if ($case === 'absent') { $store->state = null; }
        if ($case === 'generation') { $store->state['generation'] = 'other'; }
        if ($case === 'schema') { $store->state['schema_version'] = 2; }
        if ($case === 'negative') { $store->state['windows']['references_day']['used'] = -1; }
        if ($case === 'missing_field') { unset($store->state['windows']['requests_batch']['ends_at']); }
        if ($case === 'string_counter') { $store->state['windows']['references_day']['used'] = '0'; }
        if ($case === 'double_counter') { $store->state['windows']['references_day']['used'] = 0.0; }
        if ($case === 'bad_dates') { $store->state['windows']['references_day']['starts_at'] = 'invalid'; }
        if ($case === 'future') {
            $store->state['windows']['references_day'] = ['used' => 1, 'starts_at' => new UTCDateTime($store->now + 1), 'ends_at' => new UTCDateTime($store->now + 86400001)];
        }
        if ($case === 'receipt') { $store->state['last_result'] = []; }
        if ($case === 'limits') { $store->state['limits']['daily'] = 20000; }
        $before = $store->state;
        self::assertSame(DirectoryQuotaStore::UNAVAILABLE, $this->consume($store)['status']);
        self::assertEquals($before, $store->state);
    }

    public static function responses(): iterable
    {
        yield ['empty', DirectoryQuotaStore::UNAVAILABLE];
        foreach (['rejected', 'timeout', 'lost_response', 'nonce', 'malformed', 'false_exhausted'] as $case) {
            yield [$case, DirectoryQuotaStore::UNCERTAIN];
        }
    }

    #[DataProvider('responses')]
    public function test_unconfirmed_results_do_not_become_admission_or_429(string $behavior, string $status): void
    {
        $store = $this->store(); $store->behavior = $behavior;
        self::assertSame(['status' => $status, 'retry_after' => 0], $this->consume($store));
        self::assertSame(1, $store->calls); // No retry after an uncertain consumption.
        if ($behavior === 'lost_response') { self::assertSame(1, $store->state['windows']['references_day']['used']); }
    }

    public function test_http_mapping_only_uses_429_for_confirmed_exhaustion(): void
    {
        $store = $this->store(1); $quota = new DirectoryQuota($store);
        $input = ['operation' => 'batch', 'reference_count' => 1];
        $quota->consume('synthetic-client', $input, ['daily_reference_limit' => 1]);
        try { $quota->consume('synthetic-client', $input, ['daily_reference_limit' => 1]); self::fail('Expected quota exhaustion.'); }
        catch (DirectoryFailure $error) { self::assertSame(429, $error->status); self::assertSame(86400, $error->retryAfter); }
        $store->behavior = 'timeout';
        try { $quota->consume('synthetic-client', $input, ['daily_reference_limit' => 1]); self::fail('Expected unavailable quota.'); }
        catch (DirectoryFailure $error) { self::assertSame(503, $error->status); self::assertSame('directory_quota_unavailable', $error->getMessage()); }
    }

    public function test_duplicates_and_token_purpose_or_context_changes_do_not_create_another_budget(): void
    {
        $store = $this->store(4); $quota = new DirectoryQuota($store);
        $quota->consume('synthetic-client', ['operation' => 'batch', 'reference_count' => 3, 'user_ids' => ['same', 'same', 'same']], ['daily_reference_limit' => 4]);
        $key = $store->state['_id'];
        $quota->consume('synthetic-client', ['operation' => 'resolve', 'reference_count' => 1, 'token' => 'other', 'purpose' => 'other', 'context' => ['id' => 'other']], ['daily_reference_limit' => 4]);
        self::assertSame($key, $store->state['_id']);
        self::assertSame(4, $store->state['windows']['references_day']['used']);
        self::assertStringNotContainsString('other', json_encode($store->state));
        self::assertNotSame($key, DirectoryQuotaStore::initialState('other-deployment', 'generation-1', 'synthetic-client', 4)['_id']);
        self::assertNotSame($key, DirectoryQuotaStore::initialState('pure-test', 'generation-1', 'other-client', 4)['_id']);
    }

    public function test_unattested_configuration_stops_before_the_storage_boundary(): void
    {
        config(['identity_directory.quota_store_verified' => false]);
        $store = $this->store();
        try {
            (new DirectoryQuota($store))->consume('synthetic-client', ['operation' => 'batch', 'reference_count' => 1], ['daily_reference_limit' => 10000]);
            self::fail('Unattested storage must be blocked.');
        } catch (DirectoryFailure $error) { self::assertSame(503, $error->status); }
        self::assertSame(0, $store->calls);
    }

    public function test_large_integer_daily_limit_is_checked_without_overflowing_on_rejection(): void
    {
        $store = $this->store(PHP_INT_MAX);
        $store->state['windows']['references_day'] = ['used' => PHP_INT_MAX - 1,
            'starts_at' => new UTCDateTime($store->now), 'ends_at' => new UTCDateTime($store->now + 86400000)];
        self::assertSame(DirectoryQuotaStore::EXHAUSTED, $this->consume($store, positions: 2, daily: PHP_INT_MAX)['status']);
        self::assertSame(PHP_INT_MAX - 1, $store->state['windows']['references_day']['used']);
    }

    public function test_invalid_profile_or_cost_does_not_reach_storage(): void
    {
        $store = $this->store();
        foreach ([['', 'generation-1', 'batch', 1, 100], ['pure-test', 'bad generation', 'batch', 1, 100],
            ['pure-test', 'generation-1', 'unknown', 1, 100], ['pure-test', 'generation-1', 'batch', 0, 100],
            ['pure-test', 'generation-1', 'batch', 101, 100], ['pure-test', 'generation-1', 'batch', 1, 0]] as $case) {
            [$deployment, $generation, $operation, $positions, $daily] = $case;
            self::assertSame(DirectoryQuotaStore::UNAVAILABLE,
                $store->consume($deployment, $generation, 'synthetic-client', $operation, $positions, $daily)['status']);
        }
        self::assertSame(0, $store->calls);
    }
}
