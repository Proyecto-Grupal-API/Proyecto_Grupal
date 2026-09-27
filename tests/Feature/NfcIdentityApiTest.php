<?php

use App\Models\CredentialEvent;
use App\Models\EventOutbox;
use App\Models\NfcCard;
use App\Models\SecurityEvent;
use App\Models\ServiceClient;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function nfcApiToken($test, array $allowedScopes = ['identity:nfc:validate'], string $requestedScope = 'identity:nfc:validate'): string
{
    $client = ServiceClient::create([
        'name' => 'NFC contract test service',
        'client_id' => 'svc_nfc_'.Str::lower(Str::random(16)),
        'secret_hash' => Hash::make('nfc-contract-secret'),
        'scopes' => $allowedScopes,
        'active' => true,
    ]);

    return $test->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'nfc-contract-secret',
        'scope' => $requestedScope,
    ])->assertOk()->json('access_token');
}

function nfcApiHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function nfcApiStudent(): User
{
    $user = User::factory()->create(['name' => 'Estudiante NFC']);
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'NFC-'.(string) $user->getKey(),
        'academic_status' => 'active',
        'personal_email' => 'private@example.test',
    ]);

    return $user;
}

function nfcApiCard(string $userId, string $status = 'active', string $uid = '04AABBCC'): NfcCard
{
    return NfcCard::create([
        'user_id' => $userId,
        'uid' => $uid,
        'status' => $status,
        'registered_at' => now(),
    ]);
}

test('the versioned NFC contract identifies an active card with the external User ID and a minimal projection', function () {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    $card = nfcApiCard((string) $student->getKey());
    $profileId = (string) $student->studentProfile->getKey();

    $response = $this->postJson('/api/v1/identity/nfc-validate', [
        'credential_uid' => '04AABBCC',
    ], nfcApiHeaders($token))->assertOk()->assertExactJson([
        'ok' => true,
        'result' => 'valid',
        'credential' => ['status' => 'active'],
        'student_id' => (string) $student->getKey(),
        'identity' => $student->fresh()->displayIdentity(),
    ]);

    expect($response->getContent())->not->toContain($profileId)
        ->not->toContain('private@example.test')
        ->not->toContain($student->email)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('two_factor_secret')
        ->not->toContain('roles')
        ->and($card->fresh()->status)->toBe('active');
});

test('the NFC endpoint requires a service bearer token even if a web user is authenticated', function () {
    $student = nfcApiStudent();
    nfcApiCard((string) $student->getKey());

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'])
        ->assertUnauthorized();

    $this->actingAs($student)->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'])
        ->assertUnauthorized();
});

test('students read and QR validation scopes do not grant NFC validation', function (string $scope) {
    $token = nfcApiToken($this, [$scope], $scope);

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'], nfcApiHeaders($token))
        ->assertForbidden();
})->with(['students:read', 'identity:qr:validate']);

test('NFC validation reuses canonical and legacy UID normalization', function (string $storedUid) {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    nfcApiCard((string) $student->getKey(), 'active', $storedUid);

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '  04aabbcc  '], nfcApiHeaders($token))
        ->assertOk()
        ->assertJsonPath('student_id', (string) $student->getKey())
        ->assertJsonPath('credential.status', 'active');
})->with(['04AABBCC', ' 04aabbcc ']);

test('an unknown UID fails without exposing identity', function () {
    $token = nfcApiToken($this);

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => 'UNKNOWN'], nfcApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson([
            'ok' => false,
            'result' => 'not_found',
            'credential' => null,
            'student_id' => null,
            'identity' => null,
        ]);
});

test('non-operational NFC statuses are rejected without owner identity', function (string $status) {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    $card = nfcApiCard((string) $student->getKey(), $status);

    $response = $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => $card->uid], nfcApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson([
            'ok' => false,
            'result' => $status,
            'credential' => ['status' => $status],
            'student_id' => null,
            'identity' => null,
        ]);

    expect($response->getContent())->not->toContain((string) $student->getKey())
        ->not->toContain($student->name);
})->with(['blocked', 'suspended', 'replaced']);

test('an active card with no owner or a deactivated owner fails closed', function (string $ownerState) {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    $card = nfcApiCard((string) $student->getKey());

    if ($ownerState === 'missing') {
        $student->forceDelete();
    } else {
        $student->delete();
    }

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => $card->uid], nfcApiHeaders($token))
        ->assertUnprocessable()
        ->assertExactJson([
            'ok' => false,
            'result' => 'revoked',
            'credential' => null,
            'student_id' => null,
            'identity' => null,
        ]);
})->with(['missing', 'soft-deleted']);

test('an active card without a StudentProfile fails closed', function () {
    $token = nfcApiToken($this);
    $student = User::factory()->create();
    nfcApiCard((string) $student->getKey());

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'], nfcApiHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('result', 'revoked')
        ->assertJsonPath('student_id', null)
        ->assertJsonPath('identity', null);
});

test('an ambiguous legacy UID fails closed', function () {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    nfcApiCard((string) $student->getKey(), 'active', '04AABBCC');
    nfcApiCard((string) $student->getKey(), 'active', ' 04aabbcc ');

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'], nfcApiHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('result', 'not_found')
        ->assertJsonPath('identity', null);
});

test('empty and malformed NFC requests fail validation', function () {
    $token = nfcApiToken($this);

    foreach ([
        [],
        ['credential_uid' => '  '],
        ['credential_uid' => ['04AABBCC']],
        ['credential_uid' => str_repeat('A', 256)],
    ] as $payload) {
        $this->postJson('/api/v1/identity/nfc-validate', $payload, nfcApiHeaders($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential_uid');
    }
});

test('NFC validation records security audit without changing lifecycle or publishing an outbox event', function () {
    $token = nfcApiToken($this);
    $student = nfcApiStudent();
    $card = nfcApiCard((string) $student->getKey());
    $before = $card->fresh()->toArray();

    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => '04AABBCC'], nfcApiHeaders($token))
        ->assertOk();
    $this->postJson('/api/v1/identity/nfc-validate', ['credential_uid' => 'UNKNOWN'], nfcApiHeaders($token))
        ->assertUnprocessable();

    expect($card->fresh()->toArray())->toBe($before)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0)
        ->and(SecurityEvent::where('type', 'nfc_validated')->count())->toBe(1)
        ->and(SecurityEvent::where('type', 'nfc_validation_failed')->count())->toBe(1)
        ->and(SecurityEvent::where('type', 'nfc_validated')->first()->metadata['credential_id'])->toBe((string) $card->getKey());

    $audits = SecurityEvent::get();
    foreach ($audits as $audit) {
        expect(json_encode($audit->metadata))->not->toContain('04AABBCC')
            ->not->toContain('UNKNOWN');
    }
});
