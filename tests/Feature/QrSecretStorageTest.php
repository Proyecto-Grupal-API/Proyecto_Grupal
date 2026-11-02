<?php

use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\IdentityService;
use App\Support\QrLookupHash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function secretStorageStudent(): User
{
    $user = User::factory()->create();
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'SECRET-'.str_replace('-', '', (string) $user->getKey()),
        'academic_status' => 'active',
    ]);

    return $user;
}

function rawQrByHash(string $hash): array
{
    return (array) DB::connection('mongodb')->getCollection('qr_tokens')->findOne(['code_hash' => $hash]);
}

test('new dynamic secrets exist only in the issuance result and remain valid by both presentations', function () {
    $service = app(IdentityService::class);
    $issued = $service->issueDynamicQrToken(secretStorageStudent());
    $document = rawQrByHash(QrLookupHash::code($issued->presentedCode));
    $payload = $service->buildQrPayload($issued);

    expect($document)->toHaveKeys(['code_hash', 'short_code_hash', 'short_code_claimed'])
        ->not->toHaveKeys(['code', 'short_code'])
        ->and($document['short_code_claimed'])->toBeTrue()
        ->and($document['short_code_hash'])->toBe(QrLookupHash::shortCode($issued->shortCode))
        ->and($payload)->toBe('CAMPUSDIGITAL:'.$issued->presentedCode.'.'.$service->signCode($issued->presentedCode));

    expect($service->validateQrCode($issued->shortCode, null, null, null)['result'])->toBe('valid')
        ->and($service->validateQrCode($payload, null, null, null)['result'])->toBe('consumed')
        ->and($issued->token->fresh()->consumed_at)->not->toBeNull();

    $audit = json_encode([
        QrValidation::get()->toArray(),
        SecurityEvent::get()->toArray(),
        $issued->token->toArray(),
    ]);
    expect($audit)->not->toContain($issued->presentedCode)
        ->not->toContain($issued->shortCode);
});

test('new identification secret is encrypted and can be rendered again from a reloaded token', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $issued = $service->issueIdentificationQrToken($user);
    $document = rawQrByHash(QrLookupHash::code($issued->presentedCode));
    $second = $service->issueIdentificationQrToken($user);

    expect($document)->toHaveKeys(['code_hash', 'code_encrypted'])
        ->not->toHaveKey('code')
        ->and(Crypt::decryptString($document['code_encrypted']))->toBe($issued->presentedCode)
        ->and($second->token->getKey())->toBe($issued->token->getKey())
        ->and($service->buildQrPayload($second))->toBe($service->buildQrPayload($issued))
        ->and($service->validateQrCode($service->buildQrPayload($second), null, null, null)['result'])->toBe('valid');

    $serialized = json_encode($issued->token);
    expect($serialized)->not->toContain($issued->presentedCode)
        ->not->toContain($document['code_encrypted'])
        ->not->toContain($document['code_hash']);
});

test('corrupt identification ciphertext fails closed and is revoked for safe reissuance', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $issued = $service->issueIdentificationQrToken($user);
    $issued->token->update(['code_encrypted' => 'corrupted']);

    $replacement = $service->issueIdentificationQrToken($user);
    expect($issued->token->fresh()->revoked_at)->not->toBeNull()
        ->and(SecurityEvent::where('type', 'qr_secret_integrity_failed')->count())->toBe(1);
    expect($replacement->token->getKey())->not->toBe($issued->token->getKey());
});

test('encrypted code and stored hash mismatch fails closed without returning identity', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $issued = $service->issueIdentificationQrToken($user);
    $issued->token->update(['code_encrypted' => Crypt::encryptString('DIFFERENT-CODE')]);

    expect($service->validateQrCode($service->buildQrPayload($issued), null, null, null))
        ->toBe(['ok' => false, 'result' => 'not_found', 'identity' => null])
        ->and(QrValidation::latest('created_at')->first()->user_id)->toBeNull();
    $replacement = $service->issueIdentificationQrToken($user);
    expect($replacement->token->getKey())->not->toBe($issued->token->getKey());
});

test('legacy plaintext dynamic and identification tokens remain readable', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $dynamicCode = QrToken::generateCode();
    $dynamic = QrToken::create([
        'user_id' => (string) $user->getKey(), 'type' => 'dynamic',
        'code' => $dynamicCode, 'short_code' => '654321', 'expires_at' => now()->addMinute(),
    ]);
    $identificationCode = QrToken::generateCode();
    $identification = QrToken::create([
        'user_id' => (string) $user->getKey(), 'type' => 'identification',
        'code' => $identificationCode, 'expires_at' => now()->addDay(),
    ]);

    expect($service->validateQrCode('654321', null, null, null)['result'])->toBe('valid')
        ->and($service->validateQrCode($service->buildQrPayload($identification), null, null, null)['result'])->toBe('valid')
        ->and($service->buildQrPayload($dynamic))->toContain($dynamicCode);
});

test('inconsistent hybrid plaintext and hash fails closed and records no identity', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $code = QrToken::generateCode();
    QrToken::create([
        'user_id' => (string) $user->getKey(), 'type' => 'dynamic',
        'code' => $code, 'code_hash' => QrLookupHash::code('OTHER-CODE'),
        'expires_at' => now()->addMinute(),
    ]);

    expect($service->validateQrCode($code, null, null, null))
        ->toBe(['ok' => false, 'result' => 'not_found', 'identity' => null])
        ->and(SecurityEvent::where('type', 'qr_secret_integrity_failed')->count())->toBe(1);
});

test('consistent hybrid plaintext and hash remains readable during transition', function () {
    $service = app(IdentityService::class);
    $user = secretStorageStudent();
    $code = QrToken::generateCode();
    QrToken::create([
        'user_id' => (string) $user->getKey(), 'type' => 'dynamic',
        'code' => $code, 'code_hash' => QrLookupHash::code($code),
        'short_code' => '765432', 'short_code_hash' => QrLookupHash::shortCode('765432'),
        'expires_at' => now()->addMinute(),
    ]);

    expect($service->validateQrCode($code, null, null, null)['result'])->toBe('valid');
});

test('legacy short code cannot eclipse a usable hash-only token', function () {
    $service = app(IdentityService::class);
    $issued = $service->issueDynamicQrToken(secretStorageStudent());
    QrToken::create([
        'user_id' => (string) secretStorageStudent()->getKey(), 'type' => 'dynamic',
        'code' => QrToken::generateCode(), 'short_code' => $issued->shortCode,
        'expires_at' => now()->subMinute(),
    ]);

    expect($service->validateQrCode($issued->shortCode, null, null, null)['result'])->toBe('valid');
});

test('partial indexes allow multiple hash-only tokens while rejecting duplicate code hashes and claims', function () {
    $indexes = iterator_to_array(DB::connection('mongodb')->getCollection('qr_tokens')->listIndexes());
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index->getName()] = $index;
    }
    expect($byName['code_1']->isUnique())->toBeTrue()
        ->and($byName['code_1']['partialFilterExpression']['code']['$type'])->toBe('string')
        ->and($byName['qr_code_hash_unique']->isUnique())->toBeTrue();

    $service = app(IdentityService::class);
    $first = $service->issueDynamicQrToken(secretStorageStudent());
    $service->issueDynamicQrToken(secretStorageStudent());

    expect(fn () => QrToken::create([
        'user_id' => (string) secretStorageStudent()->getKey(), 'type' => 'dynamic',
        'code_hash' => $first->token->code_hash, 'expires_at' => now()->addMinute(),
    ]))->toThrow(BulkWriteException::class);
    expect(fn () => QrToken::create([
        'user_id' => (string) secretStorageStudent()->getKey(), 'type' => 'dynamic',
        'code_hash' => QrLookupHash::code(QrToken::generateCode()),
        'short_code_hash' => $first->token->short_code_hash,
        'short_code_claimed' => true, 'expires_at' => now()->addMinute(),
    ]))->toThrow(BulkWriteException::class);
});
