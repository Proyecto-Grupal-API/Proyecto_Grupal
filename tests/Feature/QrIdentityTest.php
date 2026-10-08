<?php

use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\IdentityService;

function qrIdentityUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana QR',
        'two_factor_secret' => 'two-factor-secret',
        'two_factor_recovery_codes' => '["recovery-code"]',
    ], $attributes));

    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'QR-'.str_replace('-', '', (string) $user->getKey()),
        'academic_status' => 'active',
        'personal_email' => 'private@example.test',
        'phone' => '+52 5555555555',
    ]);

    return $user;
}

it('creates a signed dynamic QR token with a short code and expiration', function () {
    $service = app(IdentityService::class);
    $user = qrIdentityUser();

    $token = $service->issueDynamicQrToken($user, 'biblioteca');

    expect($token->token->type)->toBe('dynamic')
        ->and($token->presentedCode)->not->toBeEmpty()
        ->and($token->shortCode)->toMatch('/^\d{6}$/')
        ->and($token->token->expires_at->isFuture())->toBeTrue()
        ->and($service->buildQrPayload($token))
        ->toBe('CAMPUSDIGITAL:'.$token->presentedCode.'.'.$service->signCode($token->presentedCode));
});

it('validates a correctly signed dynamic QR, returns safe identity, and records the validation', function () {
    $service = app(IdentityService::class);
    $user = qrIdentityUser(['password' => 'secret-password']);
    $validator = User::factory()->create();
    $token = $service->issueDynamicQrToken($user);

    $result = $service->validateQrCode(
        $service->buildQrPayload($token),
        $validator,
        'biblioteca-central',
        '127.0.0.1',
        'Terminal biblioteca'
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['result'])->toBe('valid')
        ->and($result['identity']['user_id'])->toBe((string) $user->getKey())
        ->and($result['identity']['student']['enrollment_number'])->toStartWith('QR-');

    expect(json_encode($result['identity']))
        ->not->toContain('secret-password')
        ->not->toContain('two-factor-secret')
        ->not->toContain('recovery-code')
        ->not->toContain('private@example.test')
        ->not->toContain('+52 5555555555');

    $validation = QrValidation::where('qr_token_id', (string) $token->token->getKey())->firstOrFail();

    expect($validation->result)->toBe('valid')
        ->and($validation->user_id)->toBe((string) $user->getKey())
        ->and($validation->validated_by_user_id)->toBe((string) $validator->getKey())
        ->and($validation->validator_label)->toBe('Terminal biblioteca')
        ->and($validation->context)->toBe('biblioteca-central');
});

it('consumes a dynamic QR after its first successful validation', function () {
    $service = app(IdentityService::class);
    $token = $service->issueDynamicQrToken(qrIdentityUser());
    $payload = $service->buildQrPayload($token);

    $first = $service->validateQrCode($payload, null, 'evento', '127.0.0.1');
    $second = $service->validateQrCode($payload, null, 'evento', '127.0.0.1');

    expect($first['result'])->toBe('valid')
        ->and($second)->toBe(['ok' => false, 'result' => 'consumed', 'identity' => null])
        ->and($token->token->fresh()->consumed_at)->not->toBeNull();
});

it('rejects a QR with a modified signature without returning identity', function () {
    $service = app(IdentityService::class);
    $token = $service->issueDynamicQrToken(qrIdentityUser());

    $result = $service->validateQrCode(
        $service->buildQrPayload($token).'altered',
        null,
        'evento',
        '127.0.0.1'
    );

    expect($result)->toBe(['ok' => false, 'result' => 'invalid_signature', 'identity' => null]);
});

it('reports expired and revoked QR tokens', function () {
    $service = app(IdentityService::class);
    $user = qrIdentityUser();
    $expired = QrToken::create([
        'user_id' => (string) $user->getKey(),
        'code' => QrToken::generateCode(),
        'short_code' => '100001',
        'type' => 'dynamic',
        'expires_at' => now()->subSecond(),
    ]);
    $revoked = QrToken::create([
        'user_id' => (string) $user->getKey(),
        'code' => QrToken::generateCode(),
        'short_code' => '100002',
        'type' => 'dynamic',
        'expires_at' => now()->addMinute(),
        'revoked_at' => now(),
    ]);

    expect($service->validateQrCode($service->buildQrPayload($expired), null, 'evento', '127.0.0.1'))
        ->toBe(['ok' => false, 'result' => 'expired', 'identity' => null])
        ->and($service->validateQrCode($service->buildQrPayload($revoked), null, 'evento', '127.0.0.1'))
        ->toBe(['ok' => false, 'result' => 'revoked', 'identity' => null]);
});

it('validates a dynamic short code and reports an unknown short code as not found', function () {
    $service = app(IdentityService::class);
    $token = $service->issueDynamicQrToken(qrIdentityUser());

    $valid = $service->validateQrCode($token->shortCode, null, 'caja', '127.0.0.1');
    $notFound = $service->validateQrCode('000000', null, 'caja', '127.0.0.1');

    expect($valid['ok'])->toBeTrue()
        ->and($valid['result'])->toBe('valid')
        ->and($notFound)->toBe(['ok' => false, 'result' => 'not_found', 'identity' => null]);
});

it('generates a reusable identification QR that remains valid after repeated validation', function () {
    $service = app(IdentityService::class);
    $user = qrIdentityUser();

    $token = $service->issueIdentificationQrToken($user);
    $sameToken = $service->issueIdentificationQrToken($user);
    $payload = $service->buildQrPayload($token);

    $first = $service->validateQrCode($payload, null, 'biblioteca', '127.0.0.1');
    $second = $service->validateQrCode($payload, null, 'biblioteca', '127.0.0.1');

    expect($token->token->type)->toBe('identification')
        ->and($token->token->expires_at->isAfter(now()->addHours(23)))->toBeTrue()
        ->and($sameToken->token->getKey())->toBe($token->token->getKey())
        ->and($payload)->toStartWith('CAMPUSDIGITAL-ID:')
        ->and($first['result'])->toBe('valid')
        ->and($second['result'])->toBe('valid')
        ->and($token->token->fresh()->consumed_at)->toBeNull();
});
