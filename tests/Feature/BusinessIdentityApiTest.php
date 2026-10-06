<?php

use App\Models\BusinessMembership;
use App\Models\BusinessOwnerClaim;
use App\Models\BusinessOwnerProvision;
use App\Models\RoleAssignment;
use App\Models\ServiceClient;
use App\Models\User;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\CommandException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_10_06_000100_create_business_owner_provision_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    config(['oauth.business_owner_clients' => ['svc_team3']]);
    $this->subject = User::factory()->create();
    $this->payload = ['subject_id' => (string) $this->subject->getKey(), 'business_id' => 'business-A', 'operation_id' => 'op-1'];
});

function b5Token($test, string $scope, ?string $requestScope = null, string $clientId = 'svc_team3'): array
{
    ServiceClient::firstOrCreate(['client_id' => $clientId], ['name' => 'Contract testing',
        'secret_hash' => Hash::make('test-secret'), 'scopes' => [$scope], 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials',
        'client_id' => $clientId, 'client_secret' => 'test-secret', 'scope' => $requestScope ?? $scope])
        ->assertOk()->json('access_token');

    return ['Authorization' => 'Bearer '.$token];
}

function b5Membership(User $subject, string $business = 'business-A', string $status = 'active'): BusinessMembership
{
    return BusinessMembership::create(['user_id' => (string) $subject->getKey(), 'business_id' => $business, 'status' => $status]);
}

function b5Role(User $subject, string $role = 'business_owner', string $business = 'business-A'): RoleAssignment
{
    return RoleAssignment::create(['user_id' => (string) $subject->getKey(), 'role_key' => $role,
        'scope_type' => 'business', 'scope_id' => $business, 'status' => 'active']);
}

function b5DocumentHash(string $collection, $record): string
{
    return hash('sha256', serialize(DB::connection('mongodb')->getCollection($collection)
        ->findOne(['_id' => new ObjectId((string) $record->getKey())])));
}

function b5Request($test, string $endpoint, array $headers = [], ?array $payload = null)
{
    return match ($endpoint) {
        'check' => $test->postJson('/api/v1/identity/authorization/check', $payload ?? [
            'subject_id' => (string) $test->subject->getKey(), 'scope_type' => 'business',
            'scope_id' => 'business-A', 'capability' => 'business.manage',
        ], $headers),
        'read' => $test->getJson('/api/v1/identity/assignments?'.http_build_query($payload ?? [
            'subject_id' => (string) $test->subject->getKey(), 'scope_type' => 'business', 'scope_id' => 'business-A',
        ]), $headers),
        'provision' => $test->postJson('/api/v1/identity/business-owner/provision', $payload ?? $test->payload, $headers),
    };
}

test('each identity contract requires bearer scope and current client grants', function (string $endpoint, string $scope) {
    b5Request($this, $endpoint)->assertUnauthorized();
    b5Request($this, $endpoint, ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    $empty = b5Token($this, $scope, '');
    b5Request($this, $endpoint, $empty)->assertForbidden();
    $wrong = b5Token($this, 'students:read', null, 'svc_other');
    b5Request($this, $endpoint, $wrong)->assertForbidden();
    $right = b5Token($this, $scope);
    b5Request($this, $endpoint, $right)->assertStatus($endpoint === 'provision' ? 201 : 200);
    ServiceClient::where('client_id', 'svc_team3')->update(['scopes' => []]);
    b5Request($this, $endpoint, $right)->assertForbidden();
})->with([
    ['check', 'identity:authorization:check'], ['read', 'identity:assignments:read'],
    ['provision', 'identity:business-owner:provision'],
]);

test('nominal owner scope on a different service is insufficient', function () {
    $headers = b5Token($this, 'identity:business-owner:provision', null, 'svc_not_team3');
    b5Request($this, 'provision', $headers)->assertForbidden();
    expect(BusinessOwnerProvision::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
});

test('external errors never expose exception internals even with debug enabled', function () {
    config(['app.debug' => true]);
    $this->getJson('/api/v1/identity/%61ssignments')->assertUnauthorized()
        ->assertExactJson(['message' => 'A valid service token is required.']);
    $this->getJson('/api/v1/identity/%61uthorization/check')->assertStatus(405)
        ->assertExactJson(['message' => 'Method not allowed.']);
    b5Request($this, 'check', ['Authorization' => 'Bearer invalid'])->assertUnauthorized()
        ->assertExactJson(['message' => 'A valid service token is required.']);
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertCreated();
    b5Request($this, 'provision', $headers, [...$this->payload, 'business_id' => 'different'])->assertConflict()
        ->assertExactJson(['message' => 'Initial owner provisioning conflicts with existing state.']);
});

test('revoked service clients cannot continue using previously issued tokens', function () {
    $headers = b5Token($this, 'identity:authorization:check');
    ServiceClient::where('client_id', 'svc_team3')->update(['active' => false]);
    b5Request($this, 'check', $headers)->assertForbidden();
});

test('each new scope grants only its own route', function (string $scope, string $other) {
    $headers = b5Token($this, $scope);
    b5Request($this, $other, $headers)->assertForbidden();
})->with([
    ['identity:authorization:check', 'read'], ['identity:authorization:check', 'provision'],
    ['identity:assignments:read', 'check'], ['identity:assignments:read', 'provision'],
    ['identity:business-owner:provision', 'check'], ['identity:business-owner:provision', 'read'],
]);

test('new scopes are separate and cannot be requested without persisted grants', function (string $scope) {
    b5Token($this, 'students:read');
    $this->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'svc_team3',
        'client_secret' => 'test-secret', 'scope' => $scope])->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
})->with(['identity:authorization:check', 'identity:assignments:read', 'identity:business-owner:provision']);

test('authorization HTTP contract delegates the canonical matrix', function (string $role, string $capability, bool $allowed) {
    $headers = b5Token($this, 'identity:authorization:check');
    b5Membership($this->subject);
    b5Role($this->subject, $role);
    b5Request($this, 'check', $headers, ['subject_id' => (string) $this->subject->getKey(),
        'scope_type' => 'business', 'scope_id' => 'business-A', 'capability' => $capability])
        ->assertOk()->assertExactJson(['authorized' => $allowed]);
})->with([
    ['business_owner', 'business.manage', true], ['business_manager', 'business.operate', true],
    ['cashier', 'business.sales.operate', true], ['inventory_manager', 'business.inventory.operate', true],
    ['buyer', 'business.manage', false],
]);

test('negative business queries return the same minimal decision', function (string $condition) {
    $headers = b5Token($this, 'identity:authorization:check');
    $membership = b5Membership($this->subject);
    b5Role($this->subject);
    $query = ['subject_id' => (string) $this->subject->getKey(), 'scope_type' => 'business',
        'scope_id' => 'business-A', 'capability' => 'business.manage'];
    match ($condition) {
        'suspended' => $membership->transitionTo('suspended'),
        'unknown-subject' => $query['subject_id'] = 'nonexistent',
        'scope-mismatch' => $query['scope_id'] = 'business-B',
        'scope-type' => $query['scope_type'] = 'campus',
        'unknown-capability' => $query['capability'] = 'bonus.unsupported',
        'legacy-fallback' => (function () use ($membership) {
            $membership->transitionTo('revoked');
            User::whereKey($this->subject->getKey())->update(['roles' => [['name' => 'business_owner', 'scope_type' => 'business', 'scope_id' => 'business-A']]]);
        })(),
    };
    b5Request($this, 'check', $headers, $query)->assertOk()->assertExactJson(['authorized' => false]);
})->with(['suspended', 'unknown-subject', 'scope-mismatch', 'scope-type', 'unknown-capability', 'legacy-fallback']);

test('identity contracts reject missing or malformed required fields', function (string $endpoint, string $scope) {
    $headers = b5Token($this, $scope);
    b5Request($this, $endpoint, $headers, [])->assertUnprocessable();
    b5Request($this, $endpoint, $headers, ['subject_id' => ['bad']])->assertUnprocessable();
})->with([
    ['check', 'identity:authorization:check'], ['read', 'identity:assignments:read'], ['provision', 'identity:business-owner:provision'],
]);

test('assignment read is business isolated minimal and effective only', function () {
    $headers = b5Token($this, 'identity:assignments:read');
    b5Membership($this->subject);
    b5Membership($this->subject, 'business-B');
    $role = b5Role($this->subject, 'cashier');
    b5Role($this->subject, 'business_manager', 'business-B');
    b5Role($this->subject, 'buyer')->transitionTo('suspended');
    b5Request($this, 'read', $headers)->assertOk()->assertExactJson([
        'subject_id' => (string) $this->subject->getKey(), 'scope_type' => 'business', 'scope_id' => 'business-A',
        'membership_status' => 'active', 'roles' => [['role_key' => 'cashier', 'status' => 'active',
            'starts_at' => null, 'ends_at' => null, 'assigned_at' => $role->assigned_at->toIso8601String()]],
    ]);
});

test('assignment reads distinguish membership lifecycle without historical role dumps', function (string $state) {
    $headers = b5Token($this, 'identity:assignments:read');
    $membership = b5Membership($this->subject, 'business-A', $state === 'pending' ? 'pending' : 'active');
    b5Role($this->subject);
    if (in_array($state, ['suspended', 'revoked'], true)) {
        $membership->transitionTo($state);
    }
    b5Request($this, 'read', $headers)->assertOk()->assertJsonPath('membership_status', $state)
        ->assertJsonCount($state === 'active' ? 1 : 0, 'roles');
})->with(['active', 'pending', 'suspended', 'revoked']);

test('unknown subjects and missing memberships have a stable empty assignment response', function (bool $unknown) {
    $headers = b5Token($this, 'identity:assignments:read');
    $subject = $unknown ? 'nonexistent' : (string) $this->subject->getKey();
    b5Request($this, 'read', $headers, ['subject_id' => $subject, 'scope_type' => 'business', 'scope_id' => 'business-A'])
        ->assertOk()->assertExactJson(['subject_id' => $subject, 'scope_type' => 'business',
            'scope_id' => 'business-A', 'membership_status' => null, 'roles' => []]);
})->with([true, false]);

test('owner provisioning is atomic audited requester bound and retry idempotent', function () {
    $headers = b5Token($this, 'identity:business-owner:provision');
    $before = b5DocumentHash('users', $this->subject);
    $approver = User::factory()->create();
    b5Request($this, 'provision', [...$headers, 'X-User-ID' => (string) $approver->getKey(), 'X-Actor-ID' => (string) $approver->getKey()])
        ->assertCreated()->assertExactJson(['subject_id' => $this->payload['subject_id'], 'business_id' => 'business-A', 'result' => 'provisioned']);
    b5Request($this, 'provision', $headers)->assertOk();
    expect(BusinessMembership::count())->toBe(1)->and(RoleAssignment::count())->toBe(1)
        ->and(BusinessOwnerClaim::count())->toBe(1)->and(BusinessOwnerProvision::count())->toBe(1);
    $membership = BusinessMembership::first();
    $owner = RoleAssignment::first();
    $receipt = BusinessOwnerProvision::first();
    expect($membership->user_id)->toBe($this->payload['subject_id'])->and($membership->status)->toBe('active')
        ->and($membership->is_current)->toBeTrue()->and($membership->generation)->toBe(1)
        ->and($owner->user_id)->toBe($this->payload['subject_id'])->and($owner->role_key)->toBe('business_owner')
        ->and($owner->status)->toBe('active')->and($owner->scope_id)->toBe('business-A')->and($owner->generation)->toBe(1)
        ->and($receipt->client_id)->toBe('svc_team3')->and($receipt->operation_id)->toBe('op-1')
        ->and($receipt->created_at)->not->toBeNull()->and(b5DocumentHash('users', $this->subject))->toBe($before)
        ->and(RoleAssignment::where('user_id', (string) $approver->getKey())->count())->toBe(0);
});

test('operation reuse with changed material payload conflicts', function (string $field) {
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertCreated();
    $other = User::factory()->create();
    $payload = [...$this->payload, $field => $field === 'subject_id' ? (string) $other->getKey() : 'business-B'];
    b5Request($this, 'provision', $headers, $payload)->assertConflict();
    expect(RoleAssignment::count())->toBe(1)->and(BusinessMembership::count())->toBe(1)->and(BusinessOwnerProvision::count())->toBe(1);
})->with(['subject_id', 'business_id']);

test('an operation receipt is also bound to its authenticated service client', function () {
    config(['oauth.business_owner_clients' => ['svc_team3', 'svc_team3_replacement']]);
    b5Request($this, 'provision', b5Token($this, 'identity:business-owner:provision'))->assertCreated();
    $headers = b5Token($this, 'identity:business-owner:provision', null, 'svc_team3_replacement');
    b5Request($this, 'provision', $headers)->assertConflict();
    expect(BusinessOwnerProvision::count())->toBe(1)->and(RoleAssignment::count())->toBe(1);
});

test('receipt retries never reactivate a subsequently revoked membership or owner', function () {
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertCreated();
    BusinessMembership::first()->transitionTo('revoked');
    RoleAssignment::first()->transitionTo('revoked');
    b5Request($this, 'provision', $headers)->assertOk();
    b5Request($this, 'provision', $headers, [...$this->payload, 'operation_id' => 'op-2'])->assertConflict();
    expect(BusinessMembership::first()->status)->toBe('revoked')->and(RoleAssignment::first()->status)->toBe('revoked')
        ->and(BusinessMembership::count())->toBe(1)->and(RoleAssignment::count())->toBe(1);
});

test('a different initial owner cannot take over the business', function () {
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertCreated();
    $other = User::factory()->create();
    b5Request($this, 'provision', $headers, [...$this->payload, 'subject_id' => (string) $other->getKey(), 'operation_id' => 'op-2'])->assertConflict();
    expect(RoleAssignment::count())->toBe(1)->and(BusinessOwnerProvision::count())->toBe(1);
});

test('existing active membership and same valid owner are reused without new generations', function (bool $existingOwner) {
    $headers = b5Token($this, 'identity:business-owner:provision');
    $membership = b5Membership($this->subject);
    $owner = $existingOwner ? b5Role($this->subject) : null;
    b5Request($this, 'provision', $headers)->assertCreated();
    b5Request($this, 'provision', $headers, [...$this->payload, 'operation_id' => 'op-2'])->assertCreated();
    expect(BusinessMembership::count())->toBe(1)->and(RoleAssignment::count())->toBe(1)
        ->and((string) BusinessMembership::first()->getKey())->toBe((string) $membership->getKey())
        ->and(RoleAssignment::first()->generation)->toBe(1)->and(BusinessOwnerProvision::count())->toBe(2);
    if ($owner) {
        expect((string) RoleAssignment::first()->getKey())->toBe((string) $owner->getKey());
    }
})->with([true, false]);

test('pending suspended and revoked memberships require independent lifecycle resolution', function (string $state) {
    $headers = b5Token($this, 'identity:business-owner:provision');
    $membership = b5Membership($this->subject, 'business-A', $state === 'pending' ? 'pending' : 'active');
    if ($state !== 'pending') {
        $membership->transitionTo($state);
    }
    $before = b5DocumentHash('business_memberships', $membership);
    b5Request($this, 'provision', $headers)->assertConflict();
    expect(b5DocumentHash('business_memberships', $membership))->toBe($before)->and(RoleAssignment::count())->toBe(0)
        ->and(BusinessOwnerProvision::count())->toBe(0)->and(BusinessOwnerClaim::count())->toBe(0);
})->with(['pending', 'suspended', 'revoked']);

test('failure after authoritative writes rolls back claim membership owner and receipt', function () {
    config(['app.debug' => true]);
    $headers = b5Token($this, 'identity:business-owner:provision');
    BusinessOwnerProvision::creating(fn () => throw new RuntimeException('Simulated receipt failure'));
    b5Request($this, 'provision', $headers)->assertStatus(500)->assertExactJson(['message' => 'Internal service error.']);
    expect(BusinessMembership::count())->toBe(0)->and(RoleAssignment::count())->toBe(0)
        ->and(BusinessOwnerClaim::count())->toBe(0)->and(BusinessOwnerProvision::count())->toBe(0);
});

test('provision schema is idempotent and fails closed when a required constraint is absent', function () {
    $migration = require database_path('migrations/2026_10_06_000100_create_business_owner_provision_indexes.php');
    $migration->up();
    $headers = b5Token($this, 'identity:business-owner:provision');
    DB::connection('mongodb')->getCollection('business_owner_claims')->dropIndex('initial_business_owner_unique');
    b5Request($this, 'provision', $headers)->assertStatus(503);
    expect(BusinessMembership::count())->toBe(0)->and(RoleAssignment::count())->toBe(0);
});

test('owner schema rerun preserves committed documents and rejects incompatible indexes', function () {
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertCreated();
    $receipt = BusinessOwnerProvision::first();
    $before = b5DocumentHash('business_owner_provisions', $receipt);
    $migration = require database_path('migrations/2026_10_06_000100_create_business_owner_provision_indexes.php');
    $migration->up();
    expect(b5DocumentHash('business_owner_provisions', $receipt))->toBe($before)
        ->and(BusinessMembership::count())->toBe(1)->and(RoleAssignment::count())->toBe(1);
    $collection = DB::connection('mongodb')->getCollection('business_owner_claims');
    $collection->dropIndex('initial_business_owner_unique');
    $collection->createIndex(['subject_id' => 1], ['name' => 'initial_business_owner_unique', 'unique' => true]);
    expect(fn () => $migration->up())->toThrow(CommandException::class);
    b5Request($this, 'provision', $headers)->assertStatus(503);
});

test('an unmigrated provisioning collection fails closed without creating authority', function () {
    DB::connection('mongodb')->getCollection('business_owner_claims')->drop();
    $headers = b5Token($this, 'identity:business-owner:provision');
    b5Request($this, 'provision', $headers)->assertStatus(503)
        ->assertExactJson(['message' => 'Owner provisioning schema is not ready.']);
    expect(RoleAssignment::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
});

test('concurrent HTTP provisioning has one logical winner and no partial or duplicate state', function (bool $differentSubject) {
    $headers = b5Token($this, 'identity:business-owner:provision');
    $second = $differentSubject ? [...$this->payload, 'subject_id' => (string) User::factory()->create()->getKey(), 'operation_id' => 'op-2'] : $this->payload;
    $processes = [];
    $barrier = sys_get_temp_dir().'/int1b5-race-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    foreach ([$this->payload, $second] as $index => $payload) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/business-provision-worker.php'),
            base64_encode(json_encode(['headers' => $headers, 'payload' => $payload, 'barrier' => $barrier, 'index' => $index]))], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mongodb', 'DB_DATABASE' => 'campus_virtual_testing',
                'MONGODB_DSN' => 'mongodb://127.0.0.1:27017', 'OAUTH2_BUSINESS_OWNER_CLIENTS' => 'svc_team3',
            ]);
        $process->setTimeout(30)->start();
        $processes[] = $process;
    }
    $statuses = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $statuses[] = (int) trim($process->getOutput());
    }
    foreach ([0, 1] as $index) {
        unlink($barrier.'/'.$index);
    }
    rmdir($barrier);
    sort($statuses);
    expect($statuses)->toBe($differentSubject ? [201, 409] : [200, 201])
        ->and(BusinessMembership::count())->toBe(1)->and(RoleAssignment::count())->toBe(1)
        ->and(BusinessOwnerClaim::count())->toBe(1)->and(BusinessOwnerProvision::count())->toBe(1)
        ->and(RoleAssignment::first()->generation)->toBe(1)
        ->and(RoleAssignment::first()->user_id)->toBe(BusinessMembership::first()->user_id);
})->with([false, true]);
