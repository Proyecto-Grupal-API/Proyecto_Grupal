<?php

use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Jobs\RunFinancialReconciliation;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Support\FinancialOperationsConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    config([
        'financial.business_timezone' => 'America/Mexico_City',
        'financial.reconciliation.schedule_enabled' => false,
        'financial.reconciliation.scheduler_cache_store' => 'financial_scheduler',
        'financial.reconciliation.job_timeout_seconds' => 600,
        'financial.reconciliation.lock_ttl_seconds' => 900,
        'financial.reconciliation.job_tries' => 3,
        'financial.reconciliation.job_backoff_seconds' => '60,300',
        'queue.connections.financial.retry_after' => 960,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    \Illuminate\Support\Carbon::setTestNow();
    fcCleanup();
});

test('financial dispatch pins yesterday in business timezone and uses its isolated queue', function () {
    Queue::fake();
    // In Mexico it is still Oct 9, although UTC is already Oct 10.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10T01:00:00Z'));
    $this->artisan('financial:reconcile', ['--dispatch' => true])->assertExitCode(0);
    Queue::assertPushed(RunFinancialReconciliation::class, fn ($job) =>
        $job->businessDate === '2026-10-08'
        && $job->connection === 'financial'
        && $job->queue === 'financial-reconciliation'
        && $job->timeout === 600
        && $job->tries === 3
        && $job->backoff === [60, 300]
    );
});

test('invalid or future dates are rejected before dispatch', function () {
    Queue::fake();
    foreach (['2026-02-30', '2026-13-01', 'bad-date', '2999-01-01'] as $date) {
        $this->artisan('financial:reconcile', ['date' => $date, '--dispatch' => true])->assertExitCode(2);
    }
    Queue::assertNothingPushed();
});

test('unsafe timeout retry and lock settings block dispatch and the worker', function () {
    Queue::fake();
    config(['queue.connections.financial.retry_after' => 600]);
    $this->artisan('financial:reconcile', ['--dispatch' => true])->assertExitCode(2);
    $this->artisan('financial:queue-work', ['--once' => true])->assertExitCode(2);
    Queue::assertNothingPushed();
});

test('daily scheduling includes weekends and invalid configuration registers no reconciliation event', function () {
    config([
        'financial.reconciliation.schedule_enabled' => true,
        'financial.reconciliation.daily_at' => '02:00',
        'financial.reconciliation.calendar' => 'DIARIO',
    ]);
    $schedule = new Schedule();
    \Illuminate\Support\Facades\Schedule::swap($schedule);
    require base_path('routes/console.php');
    $events = collect($schedule->events())->filter(fn ($event) => str_contains($event->command ?? '', 'financial:reconcile'));
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('0 2 * * *')
        ->and($event->timezone)->toBe('America/Mexico_City')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
    foreach (['2026-10-10T02:00:00-06:00', '2026-10-11T02:00:00-06:00'] as $time) {
        \Illuminate\Support\Carbon::setTestNow($time);
        expect($event->isDue(app()))->toBeTrue();
    }
    \Illuminate\Support\Carbon::setTestNow();
    config(['financial.reconciliation.calendar' => 'LUNES_A_VIERNES']);
    expect(app(FinancialOperationsConfiguration::class)->errors(true))->not->toBeEmpty();
    $invalid = new Schedule();
    \Illuminate\Support\Facades\Schedule::swap($invalid);
    require base_path('routes/console.php');
    expect(collect($invalid->events())->filter(fn ($event) => str_contains($event->command ?? '', 'financial:reconcile')))->toHaveCount(0);
});

test('operations diagnostics verify SQL tables and shared lock exclusivity', function () {
    $this->artisan('financial:operations-check')->assertExitCode(0);
    config(['financial.reconciliation.schedule_enabled' => false]);
    $this->artisan('financial:operations-check', ['--require-schedule' => true])->assertExitCode(1);
});

test('a real SQL queue job can be delivered without changing the default app queue', function () {
    $default = config('queue.default');
    $queue = Queue::connection('financial');
    $actor = 'test-fc-operational-' . Str::uuid();
    // Do not consume unrelated work in the test database.
    expect($queue->size('financial-reconciliation'))->toBe(0);
    $date = now(config('financial.business_timezone'))->subDay()->format('Y-m-d');
    $id = $queue->push(new RunFinancialReconciliation($date, $actor), '', 'financial-reconciliation');
    $job = null;
    try {
        $job = $queue->pop('financial-reconciliation');
        expect($job)->not->toBeNull();
        $job->fire();
        $run = Reconciliation::where('executed_by', $actor)->firstOrFail();
        expect($run->business_date->format('Y-m-d'))->toBe($date)
            ->and($run->trigger_source)->toBe(ReconciliationTrigger::PROGRAMADA)
            ->and($run->attempt)->toBe(1)
            ->and(config('queue.default'))->toBe($default);
    } finally {
        if ($job) { $job->delete(); }
        DB::connection('sqlsrv')->table('financial_queue_jobs')->where('id', $id)->delete();
    }
});

test('dedicated SQL failed job storage preserves diagnostic evidence', function () {
    $provider = new DatabaseUuidFailedJobProvider(app('db'), 'sqlsrv', 'financial_queue_failed_jobs');
    $uuid = (string) Str::uuid();
    $payload = json_encode(['uuid' => $uuid, 'displayName' => RunFinancialReconciliation::class]);
    try {
        $id = $provider->log('financial', 'financial-reconciliation', $payload, new RuntimeException('test-fc-failure'));
        $failed = $provider->find($id);
        expect(strtolower($id))->toBe($uuid)
            ->and($failed->connection)->toBe('financial')
            ->and($failed->queue)->toBe('financial-reconciliation')
            ->and($failed->exception)->toContain('test-fc-failure');
    } finally {
        DB::connection('sqlsrv')->table('financial_queue_failed_jobs')->where('uuid', $uuid)->delete();
    }
});
