<?php

use App\Contracts\BonusAuthorization;
use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use App\Models\ServiceClient;
use App\Models\User;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\Hash;
use MongoDB\Driver\Exception\ConnectionException;
use Tests\Support\FinancialPolicyFixtures as Fixtures;

beforeEach(function () {
    Fixtures::seed($this);
    $this->authority = User::factory()->create();
    $this->subject = User::factory()->create();
    Fixtures::writer($this->authority)->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Synthetic designated grant');
    $this->client = ServiceClient::create(['client_id' => 'financial-test-client', 'name' => 'Isolated financial fixture',
        'secret_hash' => Hash::make('synthetic-test-secret'), 'active' => true,
        'scopes' => ['identity:financial-authorization:check', 'identity:authorization:check']]);
    $this->token = app(OAuthTokenService::class)->issue('financial-test-client', 'synthetic-test-secret', ['identity:financial-authorization:check'])['access_token'];
    $this->payload = ['subject_id' => (string) $this->subject->getKey(), 'bonus_type' => 'BENEFICIO', 'operation' => 'issue', 'scope_type' => null, 'scope_id' => null];
    $this->headers = ['Authorization' => 'Bearer '.$this->token];
});

test('financial S2S returns only a boolean without claiming a human session or revealing grants', function () {
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, [...$this->headers, 'X-Actor-ID' => (string) $this->authority->getKey()])
        ->assertOk()->assertExactJson(['authorized' => true]);
    foreach (['missing-user', 'financial-test-client'] as $id) {
        $this->postJson('/api/v1/identity/financial-authorization/check', [...$this->payload, 'subject_id' => $id], $this->headers)
            ->assertOk()->assertExactJson(['authorized' => false]);
    }
    $this->postJson('/api/v1/identity/financial-authorization/check', [...$this->payload, 'bonus_type' => 'CATEGORIA'], $this->headers)
        ->assertOk()->assertExactJson(['authorized' => false]);
});

test('public client credentials issuance carries only the requested financial scope into the financial adapter', function () {
    $token = $this->postJson('/api/oauth/token', ['grant_type' => 'client_credentials',
        'client_id' => 'financial-test-client', 'client_secret' => 'synthetic-test-secret',
        'scope' => 'identity:financial-authorization:check'])
        ->assertOk()->assertJsonPath('scope', 'identity:financial-authorization:check')->json('access_token');
    $headers = ['Authorization' => 'Bearer '.$token];
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $headers)->assertOk()->assertExactJson(['authorized' => true]);
    $this->postJson('/api/v1/identity/authorization/check', [], $headers)->assertForbidden();
});

test('financial transport enforces token scope and present persistent client grants', function (string $case, int $status) {
    $headers = $this->headers;
    if ($case === 'no token') {
        $headers = [];
    } elseif ($case === 'invalid token') {
        $headers = ['Authorization' => 'Bearer invalid-fixture'];
    } elseif ($case === 'scope absent') {
        $token = app(OAuthTokenService::class)->issue('financial-test-client', 'synthetic-test-secret', ['identity:authorization:check'])['access_token'];
        $headers = ['Authorization' => 'Bearer '.$token];
    } elseif ($case === 'grant removed') {
        $this->client->update(['scopes' => ['identity:authorization:check']]);
    } else {
        $this->client->update(['active' => false]);
    }
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $headers)->assertStatus($status);
})->with([['no token', 401], ['invalid token', 401], ['scope absent', 403], ['grant removed', 403], ['inactive client', 403]]);

test('financial payload rejects unsupported types contexts and client-declared authority', function (array $changes) {
    $this->postJson('/api/v1/identity/financial-authorization/check', [...$this->payload, ...$changes], $this->headers)->assertUnprocessable();
})->with([
    [['bonus_type' => 'UNKNOWN']], [['operation' => 'transfer']], [['subject_id' => '']],
    [['scope_type' => 'business']], [['scope_id' => 'A']], [['scope_type' => 'global']],
    [['bonus_type' => 'CAMPANIA', 'scope_type' => 'campaign', 'scope_id' => 'A']],
    [['bonus_type' => 'BECA', 'scope_type' => 'academic_program', 'scope_id' => 'P']],
    [['role_key' => 'admin']], [['capability' => 'bonuses.beneficio.issue']], [['actor_id' => 'admin']], [['membership' => ['status' => 'active']]],
]);

test('financial scope fields are required and no public financial role writer is exposed', function () {
    $payload = $this->payload;
    unset($payload['scope_id']);
    $this->postJson('/api/v1/identity/financial-authorization/check', $payload, $this->headers)->assertUnprocessable();
    $before = RoleAssignment::count();
    foreach (['financial-roles/grant', 'financial-roles/revoke'] as $path) {
        $this->postJson('/api/v1/identity/'.$path, $this->payload, $this->headers)->assertNotFound();
    }
    expect(RoleAssignment::count())->toBe($before);
});

test('financial infrastructure failures return unavailable instead of a legitimate denial', function () {
    $fake = Mockery::mock(BonusAuthorization::class);
    $fake->shouldReceive('allows')->once()->andThrow(new ConnectionException('Synthetic unavailable database'));
    app()->instance(BonusAuthorization::class, $fake);
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $this->headers)
        ->assertStatus(503)->assertExactJson(['message' => 'Authorization infrastructure unavailable.']);
});

test('contextual financial HTTP is unavailable without a trusted context provider even with global authority', function () {
    $global = Mockery::mock(BonusAuthorization::class);
    $global->shouldNotReceive('allows');
    app()->instance(BonusAuthorization::class, $global);
    $this->postJson('/api/v1/identity/financial-authorization/check', [...$this->payload,
        'bonus_type' => 'NEGOCIO', 'scope_type' => 'business', 'scope_id' => 'A'], $this->headers)
        ->assertStatus(503)->assertExactJson(['message' => 'Authorization infrastructure unavailable.']);
});

test('financial persistent client lookup failure is also unavailable before the engine is invoked', function () {
    ServiceClient::retrieved(function () {
        throw new ConnectionException('Synthetic persistent grant lookup failure');
    });
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $this->headers)
        ->assertStatus(503)->assertExactJson(['message' => 'Authorization infrastructure unavailable.']);
});

test('financial throttle bounds repeated authorization requests', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $this->headers)->assertOk();
    }
    $this->postJson('/api/v1/identity/financial-authorization/check', $this->payload, $this->headers)->assertStatus(429);
});

test('financial business grants do not leak through commercial queries or inherit another business', function () {
    $id = (string) $this->subject->getKey();
    $membership = BusinessMembership::create(['user_id' => $id, 'business_id' => 'A', 'status' => 'active']);
    BusinessMembership::create(['user_id' => $id, 'business_id' => 'B', 'status' => 'active']);
    Fixtures::writer($this->authority)->grant($this->authority, $this->subject, 'bonus_negocio_business_issuer', 'business', 'A', 'Synthetic business designation');
    $scopes = ['identity:financial-authorization:check', 'identity:assignments:read', 'identity:authorization:check'];
    $this->client->update(['scopes' => $scopes]);
    $headers = ['Authorization' => 'Bearer '.app(OAuthTokenService::class)->issue('financial-test-client', 'synthetic-test-secret', $scopes)['access_token']];
    $context = ['subject_id' => $id, 'scope_type' => 'business', 'scope_id' => 'A'];
    $this->getJson('/api/v1/identity/assignments?'.http_build_query($context), $headers)->assertOk()->assertJsonCount(0, 'roles');
    $this->postJson('/api/v1/identity/authorization/check', [...$context, 'capability' => 'business.manage'], $headers)->assertOk()->assertExactJson(['authorized' => false]);
    $payload = [...$this->payload, 'bonus_type' => 'NEGOCIO', 'scope_type' => 'business', 'scope_id' => 'A'];
    // This is a context-verified transport fixture, not a production commercial provider.
    app()->bind(\App\Contracts\FinancialContextValidator::class, fn () => new class implements \App\Contracts\FinancialContextValidator {
        public function validate(string $scopeType, string $scopeId): \App\Enums\FinancialContextValidation
        {
            return \App\Enums\FinancialContextValidation::Valid;
        }
    });
    $this->postJson('/api/v1/identity/financial-authorization/check', $payload, $headers)->assertOk()->assertExactJson(['authorized' => true]);
    $this->postJson('/api/v1/identity/financial-authorization/check', [...$payload, 'scope_id' => 'B'], $headers)->assertOk()->assertExactJson(['authorized' => false]);
    $membership->transitionTo('suspended');
    $this->postJson('/api/v1/identity/financial-authorization/check', $payload, $headers)->assertOk()->assertExactJson(['authorized' => false]);
});
