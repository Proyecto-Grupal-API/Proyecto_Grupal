<?php

use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use App\Models\ServiceClient;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\BusinessRoleAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SyntheticDomainFixtures as Fixtures;

beforeEach(function () {
    Fixtures::seed($this);
    $this->subject = User::factory()->create();
    $this->subjectId = (string) $this->subject->getKey();
    BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'A', 'status' => 'active']);
    BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'B', 'status' => 'active']);
    Fixtures::assignment($this->subject, 'fixture_financial_business', 'business', 'A');
    Fixtures::assignment($this->subject, 'fixture_rewards_business', 'business', 'A');
    ServiceClient::create(['client_id' => 'svc_domain_isolation_fixture', 'name' => 'Synthetic contract test',
        'secret_hash' => Hash::make('isolated-testing-secret'), 'active' => true,
        'scopes' => ['identity:assignments:read', 'identity:authorization:check']]);
    $token = $this->postJson('/api/oauth/token', ['grant_type' => 'client_credentials',
        'client_id' => 'svc_domain_isolation_fixture', 'client_secret' => 'isolated-testing-secret',
        'scope' => 'identity:assignments:read identity:authorization:check'])->assertOk()->json('access_token');
    $this->headers = ['Authorization' => 'Bearer '.$token];
});

test('published commercial read and check never discover synthetic roles or capabilities of another domain', function () {
    RoleAssignment::create(['user_id' => $this->subjectId, 'role_key' => 'cashier', 'scope_type' => 'business', 'scope_id' => 'A', 'status' => 'active']);
    $query = ['subject_id' => $this->subjectId, 'scope_type' => 'business', 'scope_id' => 'A'];
    $read = $this->getJson('/api/v1/identity/assignments?'.http_build_query($query), $this->headers)->assertOk();
    expect(array_column($read->json('roles'), 'role_key'))->toBe(['cashier'])
        ->and($read->getContent())->not->toContain('fixture_financial', 'fixture_rewards');
    foreach (['business.sales.operate' => true, 'bonuses.negocio.issue' => false, 'analytics.view' => false, 'rewards.redemption.deliver' => false] as $capability => $allowed) {
        $this->postJson('/api/v1/identity/authorization/check', [...$query, 'capability' => $capability], $this->headers)
            ->assertOk()->assertExactJson(['authorized' => $allowed]);
    }
    $this->getJson('/api/v1/identity/assignments?'.http_build_query([...$query, 'scope_id' => 'B']), $this->headers)
        ->assertOk()->assertJsonCount(0, 'roles');
    $this->getJson('/api/v1/identity/assignments?'.http_build_query([...$query, 'scope_type' => 'global']), $this->headers)->assertUnprocessable();
    $this->postJson('/api/v1/identity/financial-authorization/check', [], $this->headers)->assertForbidden();
    $this->postJson('/api/v1/identity/rewards-authorization/check', [], $this->headers)->assertNotFound();
});

test('commercial administration refuses domain roles and global admin does not acquire commercial capabilities', function () {
    $actor = User::factory()->create();
    BusinessMembership::create(['user_id' => (string) $actor->getKey(), 'business_id' => 'A', 'status' => 'active']);
    RoleAssignment::create(['user_id' => (string) $actor->getKey(), 'role_key' => 'business_owner', 'scope_type' => 'business', 'scope_id' => 'A', 'status' => 'active']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    BusinessMembership::create(['user_id' => (string) $admin->getKey(), 'business_id' => 'A', 'status' => 'active']);
    $before = RoleAssignment::count();
    foreach (['fixture_financial_business', 'fixture_rewards_business'] as $role) {
        expect(fn () => app(BusinessRoleAdministrationService::class)->assign($actor, $this->subject, 'A', $role))->toThrow(AuthorizationException::class);
    }
    expect(RoleAssignment::count())->toBe($before)
        ->and(app(BusinessAuthorizationService::class)->allows($admin, 'business.manage', 'business', 'A'))->toBeFalse();
});
