<?php

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\E1TestTarget;

beforeEach(function () {
    // The E1 integrated profile attests every alias, including platform, before any I/O.
    E1TestTarget::requireIntegrated();
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    $this->travelTo(now()->setDate(2026, 11, 11)->setTime(12, 0));
});

function communityAnalyticsAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $admin->assignRole(Role::ADMIN);

    return withConfirmedTestTwoFactor($admin);
}

function communityAnalyticsEvent(array $payload = [], array $envelope = []): AnalyticsEvent
{
    $name = $envelope['event_name'] ?? 'community.report.published.v1';
    $closed = is_string($name) && str_contains($name, '.closed.');
    $id = 'abcdef123456abcdef123456';
    $body = [
        'event_id' => (string) Str::uuid(), 'source_domain' => 'community', 'event_name' => $name,
        'aggregate_id' => $id, 'occurred_at' => now()->subHour()->toISOString(),
        'payload' => array_replace($closed ? [
            'organization_id' => 'aabbcc123456aabbcc123456', 'consultation_id' => $id,
            'revision' => 1, 'eligible_count' => 10, 'participation_count' => 6,
            'closed_at' => now()->subHours(2)->toISOString(),
        ] : [
            'organization_id' => 'aabbcc123456aabbcc123456', 'report_id' => $id, 'version' => 1,
            'period' => ['start' => '2026-11-01', 'end' => '2026-11-10'],
            'generated_at' => now()->subHours(2)->toISOString(),
            'metrics' => ['eventos_iniciados' => 3, 'asistencias' => 8, 'encuestas_participaciones' => 6,
                'monto_pendiente_centavos' => 125050],
        ], $payload),
    ];
    $body = array_replace($body, $envelope);
    $sort = function (array $value) use (&$sort): array {
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as &$entry) { if (is_array($entry)) { $entry = $sort($entry); } }

        return $value;
    };

    return AnalyticsEvent::create([
        ...$body, 'processed' => false,
        'envelope_hash' => array_key_exists('envelope_hash', $envelope) ? $envelope['envelope_hash']
            : hash('sha256', json_encode($sort($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ]);
}

test('community analytics requires a session and the existing analytics permission', function () {
    $url = '/api/v1/recompensas/analytics/community';
    $this->getJson($url)->assertUnauthorized();
    $student = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $student->assignRole(Role::ESTUDIANTE);
    $this->actingAs($student)->getJson($url)->assertForbidden();
    $this->actingAs(communityAnalyticsAdmin())->getJson($url)->assertOk();
    $this->postJson($url)->assertStatus(405);
});

test('community analytics validates filters instead of coercing invalid values', function (array $query, string $field) {
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['organization_id' => 'not-an-object-id'], 'organization_id'],
    [['organization_id' => ['$ne' => 'organization']], 'organization_id'],
    [['days' => 0], 'days'], [['days' => 367], 'days'], [['days' => '2026-02-30'], 'days'],
    [['days' => '1.5'], 'days'], [['page' => 0], 'page'], [['per_page' => 101], 'per_page'],
]);

test('community analytics distinguishes no linked data from a confirmed empty window', function () {
    $this->actingAs(communityAnalyticsAdmin());
    $url = '/api/v1/recompensas/analytics/community?days=1';
    $this->getJson($url)->assertOk()->assertJsonPath('linked', false)
        ->assertJsonPath('stats', null)->assertJsonPath('reports.total', 0);
    communityAnalyticsEvent([], ['occurred_at' => now()->subDays(3)->toISOString(),
        'payload' => [
            'organization_id' => 'aabbcc123456aabbcc123456', 'report_id' => 'abcdef123456abcdef123456', 'version' => 1,
            'period' => ['start' => '2026-11-01', 'end' => '2026-11-05'],
            'generated_at' => now()->subDays(4)->toISOString(), 'metrics' => ['eventos_iniciados' => 2],
        ]]);
    $this->getJson($url)->assertOk()->assertJsonPath('linked', true)
        ->assertJsonPath('stats.report_count', 0)->assertJsonPath('stats.closed_surveys', 0);
});

test('community reports deduplicate canonical organization and report ids before pagination without summing snapshots', function () {
    communityAnalyticsEvent(['generated_at' => now()->subHours(4)->toISOString()], ['occurred_at' => now()->subHours(3)->toISOString()]);
    communityAnalyticsEvent(['organization_id' => 'AABBCC123456AABBCC123456', 'report_id' => 'ABCDEF123456ABCDEF123456',
        'metrics' => ['eventos_iniciados' => 7]], ['aggregate_id' => 'ABCDEF123456ABCDEF123456']);
    communityAnalyticsEvent(['organization_id' => '112233123456112233123456']);
    communityAnalyticsEvent(['report_id' => '000000000000000000000001'], ['aggregate_id' => '000000000000000000000001']);
    $this->actingAs(communityAnalyticsAdmin());
    $url = '/api/v1/recompensas/analytics/community?organization_id=AABBCC123456AABBCC123456&per_page=1';
    $first = $this->getJson($url)->assertOk()->assertJsonPath('stats.report_count', 2)
        ->assertJsonPath('reports.total', 2)->assertJsonPath('reports.last_page', 2)->assertJsonCount(1, 'reports.data');
    $second = $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'reports.data');
    $rows = array_merge($first->json('reports.data'), $second->json('reports.data'));
    $report = collect($rows)->firstWhere('report_id', 'abcdef123456abcdef123456');
    expect($report['organization_id'])->toBe('aabbcc123456aabbcc123456')
        ->and($report['metrics']['eventos_iniciados'])->toBe(7)
        ->and($first->json('stats'))->toHaveKeys(['report_count', 'closed_surveys', 'closed_elections'])
        ->and(count($first->json('stats')))->toBe(3);
    $this->getJson('/api/v1/recompensas/analytics/community')->assertJsonPath('stats.report_count', 3);
});

test('community uses occurred at for the rolling cut and current withdrawal state without anticipating future events', function () {
    communityAnalyticsEvent(['generated_at' => now()->subHours(5)->toISOString()], ['occurred_at' => now()->subHours(4)->toISOString()]);
    communityAnalyticsEvent(['generated_at' => now()->subHours(5)->toISOString()], ['event_name' => 'community.report.withdrawn.v1', 'occurred_at' => now()->subHour()->toISOString()]);
    communityAnalyticsEvent(['report_id' => '000000000000000000000002'], [
        'aggregate_id' => '000000000000000000000002', 'occurred_at' => now()->toISOString(),
    ]);
    communityAnalyticsEvent(['report_id' => '000000000000000000000002'], [
        'aggregate_id' => '000000000000000000000002', 'event_name' => 'community.report.withdrawn.v1',
        'occurred_at' => now()->addSecond()->toISOString(),
    ]);
    communityAnalyticsEvent(['report_id' => '000000000000000000000003'], [
        'aggregate_id' => '000000000000000000000003', 'occurred_at' => now()->addSecond()->toISOString(),
    ]);
    // A recently received old event is not recent activity; created_at must never set the cut.
    communityAnalyticsEvent(['report_id' => '000000000000000000000004',
        'generated_at' => now()->subDays(3)->toISOString(), 'period' => ['start' => '2026-11-01', 'end' => '2026-11-05']], [
        'aggregate_id' => '000000000000000000000004', 'occurred_at' => now()->subDays(2)->subSecond()->toISOString(),
    ]);
    communityAnalyticsEvent(['report_id' => '000000000000000000000005',
        'generated_at' => now()->subDays(3)->toISOString(), 'period' => ['start' => '2026-11-01', 'end' => '2026-11-05']], [
        'aggregate_id' => '000000000000000000000005', 'occurred_at' => now()->subDays(2)->toISOString(),
    ]);
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community?days=2')
        ->assertOk()->assertJsonPath('stats.report_count', 2)->assertJsonPath('reports.data.0.report_id', '000000000000000000000002')
        ->assertJsonPath('window.basis', 'occurred_at')->assertJsonPath('window.report_state', 'current_until_now');
});

test('community withdrawal wins equal timestamps independently of insertion order and suppresses an older publication', function () {
    communityAnalyticsEvent([], ['event_name' => 'community.report.withdrawn.v1']);
    communityAnalyticsEvent();
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community')
        ->assertOk()->assertJsonPath('linked', true)->assertJsonPath('stats.report_count', 0)->assertJsonCount(0, 'reports.data');
});

test('community counts closed processes separately by kind organization and consultation keeping the highest revision', function () {
    communityAnalyticsEvent(['encuestas_cerradas' => 8]); // Extra fields are invalid, never a source of process counts.
    communityAnalyticsEvent(['metrics' => ['encuestas_cerradas' => 30, 'votaciones_cerradas' => 20]]);
    communityAnalyticsEvent(['revision' => 2], ['event_name' => 'community.survey.closed.v1']);
    communityAnalyticsEvent(['revision' => 1], ['event_name' => 'community.survey.closed.v1', 'occurred_at' => now()->toISOString()]);
    communityAnalyticsEvent([], ['event_name' => 'community.election.closed.v1']);
    communityAnalyticsEvent(['organization_id' => '112233123456112233123456'], ['event_name' => 'community.survey.closed.v1']);
    communityAnalyticsEvent([], ['event_name' => 'community.election.closed.v1', 'aggregate_id' => '000000000000000000000099']);
    // A replay of an older revision inside the window cannot make an old closure recent again.
    communityAnalyticsEvent(['consultation_id' => '000000000000000000000002', 'revision' => 2,
        'closed_at' => now()->subDays(4)->toISOString()], ['event_name' => 'community.survey.closed.v1',
        'aggregate_id' => '000000000000000000000002', 'occurred_at' => now()->subDays(3)->toISOString()]);
    communityAnalyticsEvent(['consultation_id' => '000000000000000000000002', 'revision' => 1], [
        'event_name' => 'community.survey.closed.v1', 'aggregate_id' => '000000000000000000000002']);
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community?days=2')
        ->assertOk()->assertJsonPath('stats.report_count', 1)->assertJsonPath('stats.closed_surveys', 2)
        ->assertJsonPath('stats.closed_elections', 1);
});

test('community excludes malformed or untrusted envelopes and payloads', function (array $payload, array $envelope) {
    communityAnalyticsEvent($payload, $envelope);
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community')
        ->assertOk()->assertJsonPath('linked', false)->assertJsonPath('stats', null)->assertJsonCount(0, 'reports.data');
})->with([
    'legacy untrusted' => [[], ['envelope_hash' => null]],
    'invalid hash' => [[], ['envelope_hash' => 'forged']],
    'invalid event id' => [[], ['event_id' => 'not-a-uuid']],
    'foreign source' => [[], ['source_domain' => 'services']],
    'array source' => [[], ['source_domain' => ['community']]],
    'array event name' => [[], ['event_name' => ['community.report.published.v1']]],
    'noncanonical name' => [[], ['event_name' => 'community.report.published']],
    'invalid aggregate' => [[], ['aggregate_id' => 'report-1']],
    'mismatched aggregate' => [[], ['aggregate_id' => '000000000000000000000099']],
    'invalid organization' => [['organization_id' => 'org-1'], []],
    'numeric string version' => [['version' => '1'], []],
    'unsupported version' => [['version' => 2], []],
    'string metric' => [['metrics' => ['asistencias' => '4']], []],
    'negative metric' => [['metrics' => ['asistencias' => -1]], []],
    'boolean metric' => [['metrics' => ['asistencias' => true]], []],
    'float metric' => [['metrics' => ['asistencias' => 1.2]], []],
    'nested metric' => [['metrics' => ['asistencias' => ['count' => 3]]], []],
    'invalid calendar date' => [['period' => ['start' => '2026-02-30', 'end' => '2026-03-01']], []],
    'inverted period' => [['period' => ['start' => '2026-11-10', 'end' => '2026-11-01']], []],
    'overlong period' => [['period' => ['start' => '2025-01-01', 'end' => '2026-11-01']], []],
    'future period' => [['period' => ['start' => '2026-11-01', 'end' => '2027-01-01']], []],
    'invalid generated date' => [['generated_at' => '2026-02-30T10:00:00Z'], []],
    'non ISO generated date' => [['generated_at' => 'yesterday'], []],
    'future generated date' => [['generated_at' => '2099-01-01T10:00:00Z'], []],
    'generation after occurrence' => [['generated_at' => '2026-11-11T23:59:59Z'], []],
    'names' => [['name' => 'Private Name'], []],
    'criteria' => [['criteria' => 'Private criteria'], []],
    'voter roll' => [['voter_ids' => ['private-id']], []],
    'processing rejected' => [[], ['processing_error' => 'sobre_invalido']],
    'revision zero' => [['revision' => 0], ['event_name' => 'community.survey.closed.v1']],
    'revision string' => [['revision' => '1'], ['event_name' => 'community.survey.closed.v1']],
    'boolean revision' => [['revision' => true], ['event_name' => 'community.survey.closed.v1']],
    'participation above eligible' => [['participation_count' => 11], ['event_name' => 'community.survey.closed.v1']],
    'negative eligible' => [['eligible_count' => -1], ['event_name' => 'community.election.closed.v1']],
    'string participation' => [['participation_count' => '6'], ['event_name' => 'community.election.closed.v1']],
    'selection in closure' => [['selections' => ['private-choice']], ['event_name' => 'community.election.closed.v1']],
    'invalid closed date' => [['closed_at' => '2026-02-30T10:00:00Z'], ['event_name' => 'community.survey.closed.v1']],
    'future closed date' => [['closed_at' => '2099-01-01T10:00:00Z'], ['event_name' => 'community.survey.closed.v1']],
]);

test('community returns only safe metric keys and units without personal fields or raw metadata', function () {
    communityAnalyticsEvent(['metrics' => ['asistencias' => 0, 'monto_pendiente_centavos' => 125050, 'private_person_ana' => 1]]);
    $response = $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('reports.data.0.metrics.asistencias', 0)
        ->assertJsonPath('reports.data.0.metrics.monto_pendiente_centavos', 125050)
        ->assertJsonPath('metric_units.asistencias', 'count')->assertJsonPath('metric_units.monto_pendiente_centavos', 'centavos_mxn');
    $row = $response->json('reports.data.0');
    expect(array_keys($row))->toBe(['organization_id', 'report_id', 'version', 'period', 'generated_at', 'occurred_at', 'metrics'])
        ->and($response->getContent())->not->toContain('private_person_ana', 'envelope_hash', 'event_id', 'payload', 'email', 'voter_ids');
});

test('community ignores missing malformed and array occurrence dates without trusting model casts', function () {
    $event = communityAnalyticsEvent();
    $raw = $event->getAttributes();
    unset($raw['_id'], $raw['id']);
    $event->delete();
    $collection = (new AnalyticsEvent)->getConnection()->getCollection('analytics_events');
    foreach (['not-a-date', null, [new \MongoDB\BSON\UTCDateTime(now()->subHour())]] as $invalid) {
        $collection->insertOne([...$raw, 'event_id' => (string) Str::uuid(), 'occurred_at' => $invalid]);
    }
    unset($raw['occurred_at']);
    $collection->insertOne([...$raw, 'event_id' => (string) Str::uuid()]);
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community')
        ->assertOk()->assertJsonPath('linked', false)->assertJsonPath('stats', null);
});

test('community accepts source ISO offsets and fractional dates at MongoDB millisecond precision', function () {
    communityAnalyticsEvent(['generated_at' => '2026-11-10T12:00:00.123999-06:00'],
        ['occurred_at' => '2026-11-10T12:00:00.123999-06:00']);
    communityAnalyticsEvent(['eligible_count' => 0, 'participation_count' => 0], ['event_name' => 'community.survey.closed.v1']);
    $this->actingAs(communityAnalyticsAdmin())->getJson('/api/v1/recompensas/analytics/community?days=366')
        ->assertOk()->assertJsonPath('stats.report_count', 1)->assertJsonPath('stats.closed_surveys', 1)
        ->assertJsonPath('reports.data.0.generated_at', '2026-11-10T18:00:00.123999Z');
});
