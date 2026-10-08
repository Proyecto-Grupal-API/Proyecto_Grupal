<?php

use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\ServiceClient;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\IdentityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function identityApiClient(array $scopes): ServiceClient
{
    return ServiceClient::create([
        'name' => 'Identity API service',
        'client_id' => 'svc_identity_'.Str::lower(Str::random(16)),
        'secret_hash' => Hash::make('identity-secret'),
        'scopes' => $scopes,
        'active' => true,
    ]);
}

function identityApiStudent(): User
{
    $user = User::factory()->create(['name' => 'Ana Identity']);

    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'API-'.str_replace('-', '', (string) $user->getKey()),
        'academic_status' => 'active',
    ]);

    return $user;
}

function identityApiTokenResponse($test, ServiceClient $client, ?string $scope = 'identity:qr:validate')
{
    $payload = [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'identity-secret',
    ];

    if ($scope !== null) {
        $payload['scope'] = $scope;
    }

    return $test->postJson('/api/oauth/token', $payload);
}

function identityApiHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

test('an authorized service validates a dynamic QR and records the OAuth client as its label', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $service = app(IdentityService::class);
    $student = identityApiStudent();
    $qrToken = $service->issueDynamicQrToken($student);

    $this->postJson('/api/v1/identity/qr-validate', [
        'code' => $service->buildQrPayload($qrToken),
        'context' => 'biblioteca',
        'validator_label' => 'spoofed-label',
    ], identityApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('result', 'valid')
        ->assertJsonPath('identity.user_id', (string) $student->getKey())
        ->assertJsonPath('identity.name', 'Ana Identity')
        ->assertJsonPath('identity.student.enrollment_number', 'API-'.str_replace('-', '', (string) $student->getKey()));

    $validation = QrValidation::where('qr_token_id', (string) $qrToken->token->getKey())->firstOrFail();

    expect($validation->validated_by_user_id)->toBeNull()
        ->and($validation->validator_label)->toBe($client->client_id)
        ->and($validation->validator_label)->not->toBe('spoofed-label')
        ->and($validation->context)->toBe('biblioteca');
});

test('the identity QR validation endpoint requires an OAuth service token', function () {
    $this->postJson('/api/v1/identity/qr-validate', ['code' => 'anything'])
        ->assertUnauthorized();
});

test('a token with only students read cannot validate a QR', function () {
    $client = identityApiClient(['students:read']);
    $token = identityApiTokenResponse($this, $client, 'students:read')->assertOk()->json('access_token');

    $this->postJson('/api/v1/identity/qr-validate', ['code' => 'anything'], identityApiHeaders($token))
        ->assertForbidden();
});

test('a token with an empty scope cannot validate a QR', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client, null)
        ->assertOk()
        ->assertJsonPath('scope', '')
        ->json('access_token');

    $this->postJson('/api/v1/identity/qr-validate', ['code' => 'anything'], identityApiHeaders($token))
        ->assertForbidden();
});

test('the identity QR validation endpoint validates its request payload', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');

    $this->postJson('/api/v1/identity/qr-validate', [], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/identity/qr-validate', [
        'code' => 'anything',
        'context' => str_repeat('x', 151),
    ], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('context');
});

test('the identity QR validation endpoint maps invalid signatures to an unprocessable contract response', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $service = app(IdentityService::class);
    $qrToken = $service->issueDynamicQrToken(identityApiStudent());

    $this->postJson('/api/v1/identity/qr-validate', [
        'code' => $service->buildQrPayload($qrToken).'altered',
    ], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson(['ok' => false, 'result' => 'invalid_signature', 'identity' => null]);
});

test('the identity QR validation endpoint maps expired and revoked QR tokens to unprocessable responses', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $service = app(IdentityService::class);
    $student = identityApiStudent();
    $expired = QrToken::create([
        'user_id' => (string) $student->getKey(),
        'code' => QrToken::generateCode(),
        'type' => 'dynamic',
        'expires_at' => now()->subSecond(),
    ]);
    $revoked = QrToken::create([
        'user_id' => (string) $student->getKey(),
        'code' => QrToken::generateCode(),
        'type' => 'dynamic',
        'expires_at' => now()->addMinute(),
        'revoked_at' => now(),
    ]);

    $this->postJson('/api/v1/identity/qr-validate', [
        'code' => $service->buildQrPayload($expired),
    ], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson(['ok' => false, 'result' => 'expired', 'identity' => null]);

    $this->postJson('/api/v1/identity/qr-validate', [
        'code' => $service->buildQrPayload($revoked),
    ], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson(['ok' => false, 'result' => 'revoked', 'identity' => null]);
});

test('the identity QR validation endpoint consumes dynamic QR tokens once', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $service = app(IdentityService::class);
    $qrToken = $service->issueDynamicQrToken(identityApiStudent());
    $payload = ['code' => $service->buildQrPayload($qrToken)];

    $this->postJson('/api/v1/identity/qr-validate', $payload, identityApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('result', 'valid');

    $this->postJson('/api/v1/identity/qr-validate', $payload, identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson(['ok' => false, 'result' => 'consumed', 'identity' => null]);
});

test('the identity QR validation endpoint keeps identification QR tokens reusable', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $service = app(IdentityService::class);
    $qrToken = $service->issueIdentificationQrToken(identityApiStudent());
    $payload = ['code' => $service->buildQrPayload($qrToken)];

    $this->postJson('/api/v1/identity/qr-validate', $payload, identityApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('result', 'valid');

    $this->postJson('/api/v1/identity/qr-validate', $payload, identityApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('result', 'valid');
});

test('the identity QR validation endpoint accepts valid short codes and rejects unknown ones', function () {
    $client = identityApiClient(['identity:qr:validate']);
    $token = identityApiTokenResponse($this, $client)->assertOk()->json('access_token');
    $qrToken = app(IdentityService::class)->issueDynamicQrToken(identityApiStudent());

    $this->postJson('/api/v1/identity/qr-validate', ['code' => $qrToken->shortCode], identityApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('result', 'valid');

    $this->postJson('/api/v1/identity/qr-validate', ['code' => '000000'], identityApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson(['ok' => false, 'result' => 'not_found', 'identity' => null]);
});
