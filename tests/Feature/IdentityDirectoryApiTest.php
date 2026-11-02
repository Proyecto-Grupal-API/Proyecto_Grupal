<?php

use App\Models\SecurityEvent;
use App\Models\ServiceClient;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Directory\DirectoryPolicy;
use App\Services\Directory\DirectoryQuotaStore;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\ConnectionException;

/** PERSISTENT TESTS: prepared only; require E1MongoTestTarget and separate execution approval. */
beforeEach(function () {
    // TestCase has already attested the testing identity before its isolated database drop.
    (require database_path('migrations/2026_09_22_000900_add_student_profile_unique_indexes.php'))->up();
    config(['oauth.signing_key' => str_repeat('synthetic-directory-test-', 3),
        'identity_directory.enrollment_integrity_verified' => true,
        'identity_directory.quota_deployment' => 'synthetic-directory-http',
        'identity_directory.quota_generation' => 'fixture-v1', 'identity_directory.quota_store_verified' => true]);
    $this->clientId = 'directory-fixture-'.Str::uuid();
    $this->scopes = [DirectoryPolicy::RESOLVE, DirectoryPolicy::READ, DirectoryPolicy::CONDITION, DirectoryPolicy::ENROLLMENT];
    $this->client = ServiceClient::create(['client_id' => $this->clientId, 'name' => 'Synthetic directory consumer',
        'secret_hash' => Hash::make('synthetic-directory-fixture'), 'active' => true, 'scopes' => $this->scopes]);
    config(['identity_directory.clients' => [$this->clientId => ['daily_reference_limit' => 500,
        'resolve' => ['purposes' => ['membership_management' => ['projections' => ['identity'], 'context_types' => ['organization'], 'enrollment' => true]]],
        'batch' => ['purposes' => ['historical_reference' => ['projections' => ['identity', 'account_condition'], 'context_types' => ['organization'], 'enrollment' => true]]],
    ]]]);
    // PREPARED ONLY: explicit isolated fixture provisioning, never runtime initialization.
    \Tests\Support\E1TestTarget::assertApplication();
    DB::connection('mongodb')->getDatabase()->createCollection(DirectoryQuotaStore::COLLECTION);
    DB::connection('mongodb')->getCollection(DirectoryQuotaStore::COLLECTION)->insertOne(
        DirectoryQuotaStore::initialState('synthetic-directory-http', 'fixture-v1', $this->clientId, 500));
    $this->headers = ['Authorization' => 'Bearer '.app(OAuthTokenService::class)->issue($this->clientId, 'synthetic-directory-fixture', $this->scopes)['access_token']];
    $this->person = User::factory()->create(['name' => 'Synthetic directory subject', 'account_activation_pending' => false]);
    $this->person->forceFill(['must_change_password' => false])->save();
    StudentProfile::create(['user_id' => (string) $this->person->getKey(), 'enrollment_number' => 'DIR-001', 'academic_status' => 'suspended']);
    $this->resolveBody = ['enrollment_number' => ' dir-001 ', 'purpose' => 'membership_management', 'context' => ['type' => 'organization', 'id' => 'synthetic-org']];
    $this->batchBody = ['user_ids' => [(string) $this->person->getKey()], 'purpose' => 'historical_reference',
        'context' => ['type' => 'organization', 'id' => 'synthetic-org'], 'projection' => ['identity', 'account_condition'], 'include_enrollment' => true];
});

test('directory resolves exact normalized enrollment without using academic status as account condition', function () {
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)
        ->assertOk()->assertJsonPath('data.result', 'resolved')->assertJsonPath('data.identity.user_id', (string) $this->person->getKey())
        ->assertJsonPath('data.identity.enrollment_number', 'DIR-001')->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', [...$this->resolveBody, 'enrollment_number' => 'DIR-00'], $this->headers)
        ->assertOk()->assertJsonPath('data.result', 'not_found')->assertJsonPath('data.identity', null);
});

test('directory resolve redacts accounts with either initial access restriction', function (string $flag) {
    $this->person->forceFill([$flag => true])->save();
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)
        ->assertOk()->assertJsonPath('data.result', 'not_usable')->assertJsonPath('data.identity', null);
})->with(['account_activation_pending', 'must_change_password']);

test('directory does not guess which contradictory initial account restriction applies', function () {
    $this->person->forceFill(['account_activation_pending' => true, 'must_change_password' => true])->save();
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)->assertStatus(503);
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.result', 'unavailable')->assertJsonPath('data.items.0.identity', null)->assertJsonPath('meta.partial', true);
});

test('directory preserves batch order duplicates and per-reference errors', function () {
    $noProfile = User::factory()->create();
    $id = (string) $this->person->getKey();
    $ids = [$id, (string) $noProfile->getKey(), 'absent-reference', $id, ['$ne' => null]];
    $this->postJson('/api/v1/identity/directory/batch', [...$this->batchBody, 'user_ids' => $ids], $this->headers)
        ->assertOk()->assertJsonCount(5, 'data.items')->assertJsonPath('data.items.0.identity.user_id', $id)
        ->assertJsonPath('data.items.1.account_condition.has_student_profile', false)
        ->assertJsonPath('data.items.1.identity.enrollment_number', null)
        ->assertJsonPath('data.items.2.result', 'not_found')->assertJsonPath('data.items.3.identity.user_id', $id)
        ->assertJsonPath('data.items.4.result', 'invalid_id')->assertJsonPath('meta.partial', true);
});

test('directory deleted accounts reveal no old identity even to an authorized condition reader', function () {
    $this->person->delete();
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.result', 'deleted')->assertJsonPath('data.items.0.identity', null)
        ->assertJsonPath('data.items.0.account_condition.usable', false);
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)
        ->assertOk()->assertJsonPath('data.result', 'not_usable')->assertJsonPath('data.identity', null);
});

test('directory separates condition-only from identity and enrollment', function () {
    $response = $this->postJson('/api/v1/identity/directory/batch', [...$this->batchBody, 'projection' => ['account_condition'], 'include_enrollment' => false], $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.account_condition.state', 'operational');
    expect($response->json('data.items.0'))->not->toHaveKey('identity');
});

test('directory revalidates persistent grants and active client after issuing a token', function (string $case) {
    $this->client->update($case === 'inactive' ? ['active' => false] : ['scopes' => []]);
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)->assertForbidden();
})->with(['inactive', 'removed_grant']);

test('directory refuses invalid or absent tokens and academic scopes cannot become directory access', function () {
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody)->assertUnauthorized();
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, ['Authorization' => 'Bearer invalid-fixture'])->assertUnauthorized();
    $this->client->update(['scopes' => ['students:read']]);
    $token = app(OAuthTokenService::class)->issue($this->clientId, 'synthetic-directory-fixture', ['students:read'])['access_token'];
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, ['Authorization' => 'Bearer '.$token])->assertForbidden();
});

test('directory policy has no implicit client purpose context or projection access', function (string $case) {
    $body = $this->batchBody;
    if ($case === 'client') { config(['identity_directory.clients' => []]); }
    if ($case === 'purpose') { $body['purpose'] = 'unapproved'; }
    if ($case === 'context') { $body['context']['type'] = 'event'; }
    if ($case === 'projection') {
        $profiles = config('identity_directory.clients');
        $profiles[$this->clientId]['batch']['purposes']['historical_reference']['enrollment'] = false;
        config(['identity_directory.clients' => $profiles]);
    }
    $this->postJson('/api/v1/identity/directory/batch', $body, $this->headers)->assertForbidden();
})->with(['client', 'purpose', 'context', 'projection']);

test('directory refuses oversized batches and invented authority inputs', function () {
    $this->postJson('/api/v1/identity/directory/batch', [...$this->batchBody, 'user_ids' => array_fill(0, 101, 'id')], $this->headers)->assertUnprocessable();
    $this->postJson('/api/v1/identity/directory/batch', [...$this->batchBody, 'actor_id' => 'admin'], $this->headers)->assertUnprocessable();
});

test('directory cannot enable resolve or quotas by relying only on a database name', function (string $case) {
    config([$case === 'integrity' ? 'identity_directory.enrollment_integrity_verified' : 'identity_directory.quota_store_verified' => false]);
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)->assertStatus(503);
})->with(['integrity', 'quota']);

test('directory audit is mandatory and never logs the lookup payload', function () {
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)->assertOk();
    $event = SecurityEvent::where('type', 'identity_directory_consulted')->firstOrFail();
    expect($event->severity)->toBe('info');
    expect($event->metadata['client_id'])->toBe($this->clientId);
    $metadata = json_encode($event->metadata);
    expect($metadata)->not->toContain('DIR-001')->not->toContain('Synthetic directory subject')->not->toContain('synthetic-org');
    SecurityEvent::creating(function ($event) {
        if ($event->type === 'identity_directory_consulted') { throw new RuntimeException('private-audit-failure'); }
    });
    $response = $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)
        ->assertStatus(503)->assertJsonPath('error_code', 'directory_audit_unavailable');
    expect($response->getContent())->not->toContain('private-audit-failure')->not->toContain('Synthetic directory subject');
    expect($response->json())->not->toHaveKey('data');
});

test('directory refuses data when mandatory audit creation is silently cancelled', function (string $modelEvent) {
    SecurityEvent::{$modelEvent}(function ($event) {
        if ($event->type === 'identity_directory_consulted') {
            // Even a preassigned ID must not be interpreted as a saved audit.
            $event->setAttribute('_id', 'synthetic-unsaved-audit');

            return false;
        }
    });
    $response = $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)
        ->assertStatus(503)->assertJsonPath('error_code', 'directory_audit_unavailable');
    expect($response->json())->not->toHaveKey('data');
    expect($response->getContent())->not->toContain('Synthetic directory subject')->not->toContain('DIR-001');
    expect(SecurityEvent::where('type', 'identity_directory_consulted')->exists())->toBeFalse();
})->with(['saving', 'creating']);

test('directory refuses data when audit readback is unavailable or inconsistent', function (string $case) {
    SecurityEvent::retrieved(function ($event) use ($case) {
        if ($event->type !== 'identity_directory_consulted') { return; }
        if ($case === 'unavailable') { throw new ConnectionException('private-audit-readback-failure'); }
        $event->metadata = ['request_id' => 'inconsistent-synthetic-audit'];
    });
    $response = $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)
        ->assertStatus(503)->assertJsonPath('error_code', 'directory_audit_unavailable');
    expect($response->json())->not->toHaveKey('data');
    expect($response->getContent())->not->toContain('private-audit-readback-failure')->not->toContain('Synthetic directory subject');
})->with(['unavailable', 'inconsistent']);

test('directory sanitizes database failures instead of inventing nonexistent accounts', function () {
    User::retrieved(fn () => throw new ConnectionException('private-connection-string'));
    $response = $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)->assertStatus(503);
    expect($response->getContent())->not->toContain('private-connection-string')->not->toContain('not_found');
});

test('directory rate limits queries per stable client rather than per rotated token', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)->assertOk();
    }
    $token = app(OAuthTokenService::class)->issue($this->clientId, 'synthetic-directory-fixture', $this->scopes)['access_token'];
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, ['Authorization' => 'Bearer '.$token])
        ->assertStatus(429)->assertHeader('Retry-After');
});

test('directory enforces a total daily reference budget across operations', function () {
    $clients = config('identity_directory.clients'); $clients[$this->clientId]['daily_reference_limit'] = 1;
    config(['identity_directory.clients' => $clients]);
    \Tests\Support\E1TestTarget::assertApplication();
    DB::connection('mongodb')->getCollection(DirectoryQuotaStore::COLLECTION)->updateOne(
        ['_id' => DirectoryQuotaStore::initialState('synthetic-directory-http', 'fixture-v1', $this->clientId, 1)['_id']],
        ['$set' => ['limits.daily' => 1]]);
    $this->postJson('/api/v1/identity/directory/resolve-enrollment', $this->resolveBody, $this->headers)->assertOk();
    $this->postJson('/api/v1/identity/directory/batch', $this->batchBody, $this->headers)->assertStatus(429);
});
