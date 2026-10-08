<?php

use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\IdentityService;
use App\Support\QrLookupHash;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function shortCodeStudent(): User
{
    $user = User::factory()->create();
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'SHORT-'.str_replace('-', '', (string) $user->getKey()),
        'academic_status' => 'active',
    ]);

    return $user;
}

function legacyShortCodeToken(User $user, string $shortCode, array $state = []): QrToken
{
    return QrToken::create(array_merge([
        'user_id' => (string) $user->getKey(),
        'code' => QrToken::generateCode(),
        'short_code' => $shortCode,
        'type' => 'dynamic',
        'expires_at' => now()->addMinute(),
    ], $state));
}

test('new dynamic tokens keep secrets outside the model and have separated keyed lookup hashes', function () {
    $identity = app(IdentityService::class);
    $token = $identity->issueDynamicQrToken(shortCodeStudent());

    expect($token->shortCode)->toMatch('/^[1-9]\d{5}$/')
        ->and($token->token->short_code_claimed)->toBeTrue()
        ->and($token->token->code_hash)->toBe(QrLookupHash::code($token->presentedCode))
        ->and($token->token->short_code_hash)->toBe(QrLookupHash::shortCode($token->shortCode))
        ->and($token->token->code_hash)->not->toBe($token->token->short_code_hash)
        ->and($identity->buildQrPayload($token))
        ->toBe('CAMPUSDIGITAL:'.$token->presentedCode.'.'.$identity->signCode($token->presentedCode));

    expect($identity->validateQrCode($token->shortCode, null, 'test', '127.0.0.1')['result'])->toBe('valid');
});

test('identification tokens retain their existing payload and gain a lookup hash', function () {
    $identity = app(IdentityService::class);
    $token = $identity->issueIdentificationQrToken(shortCodeStudent());

    expect($token->presentedCode)->not->toBeEmpty()
        ->and($token->token->code_hash)->toBe(QrLookupHash::code($token->presentedCode))
        ->and($identity->buildQrPayload($token))
        ->toBe('CAMPUSDIGITAL-ID:'.$token->presentedCode.'.'.$identity->signCode($token->presentedCode));
});

test('an old unusable short code cannot eclipse a new usable token', function (string $state) {
    $owner = shortCodeStudent();
    $other = shortCodeStudent();
    $attributes = match ($state) {
        'expired' => ['expires_at' => now()->subMinute()],
        'consumed' => ['consumed_at' => now()],
        'revoked' => ['revoked_at' => now()],
    };
    $old = legacyShortCodeToken($other, '123456', $attributes);
    $new = legacyShortCodeToken($owner, '123456');

    $result = app(IdentityService::class)->validateQrCode('123456', null, 'test', '127.0.0.1');

    expect($result['result'])->toBe('valid')
        ->and($result['identity']['user_id'])->toBe((string) $owner->getKey())
        ->and($new->fresh()->consumed_at)->not->toBeNull()
        ->and($old->fresh()->consumed_at !== null)->toBe($state === 'consumed');
})->with(['expired', 'consumed', 'revoked']);

test('a single historical short code retains its external result', function (string $state) {
    $attributes = match ($state) {
        'expired' => ['expires_at' => now()->subMinute()],
        'consumed' => ['consumed_at' => now()],
        'revoked' => ['revoked_at' => now()],
    };
    legacyShortCodeToken(shortCodeStudent(), '234567', $attributes);

    expect(app(IdentityService::class)->validateQrCode('234567', null, 'test', '127.0.0.1'))
        ->toBe(['ok' => false, 'result' => $state, 'identity' => null]);
})->with(['expired', 'consumed', 'revoked']);

test('two usable legacy candidates fail closed without consuming or revealing either owner', function () {
    $first = legacyShortCodeToken(shortCodeStudent(), '345678');
    $second = legacyShortCodeToken(shortCodeStudent(), '345678');

    $result = app(IdentityService::class)->validateQrCode('345678', null, 'test', '127.0.0.1');

    expect($result)->toBe(['ok' => false, 'result' => 'not_found', 'identity' => null])
        ->and($first->fresh()->consumed_at)->toBeNull()
        ->and($second->fresh()->consumed_at)->toBeNull()
        ->and(QrValidation::latest('created_at')->first()->user_id)->toBeNull();
});

test('conflicting historical states do not select an arbitrary identity', function () {
    legacyShortCodeToken(shortCodeStudent(), '456789', ['expires_at' => now()->subMinute()]);
    legacyShortCodeToken(shortCodeStudent(), '456789', ['revoked_at' => now()]);

    expect(app(IdentityService::class)->validateQrCode('456789', null, 'test', '127.0.0.1'))
        ->toBe(['ok' => false, 'result' => 'not_found', 'identity' => null]);
});

test('MongoDB creates the lookup and unique partial claim indexes', function () {
    $indexes = iterator_to_array(DB::connection('mongodb')->getCollection('qr_tokens')->listIndexes());
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index->getName()] = $index;
    }

    expect($byName['qr_code_hash_unique']->isUnique())->toBeTrue()
        ->and($byName['qr_short_code_hash_lookup']->isUnique())->toBeFalse()
        ->and($byName['qr_short_code_claim_unique']->isUnique())->toBeTrue()
        ->and($byName['qr_short_code_claim_unique']['partialFilterExpression']['short_code_claimed'])->toBeTrue();
});

test('the MongoDB claim index rejects competing issuers of the same short code', function () {
    $first = legacyShortCodeToken(shortCodeStudent(), '567890', [
        'short_code_hash' => QrLookupHash::shortCode('567890'),
        'short_code_claimed' => true,
    ]);

    $rejected = false;
    try {
        legacyShortCodeToken(shortCodeStudent(), '567890', [
            'short_code_hash' => QrLookupHash::shortCode('567890'),
            'short_code_claimed' => true,
        ]);
    } catch (BulkWriteException $exception) {
        $rejected = $exception->getCode() === 11000;
    }

    expect($rejected)->toBeTrue()
        ->and($first->fresh()->short_code_claimed)->toBeTrue()
        ->and(QrToken::where('short_code', '567890')->count())->toBe(1);
});

test('an expired claim can be reclaimed without deleting the historical token', function () {
    $old = legacyShortCodeToken(shortCodeStudent(), '678901', [
        'expires_at' => now()->subMinute(),
        'short_code_hash' => QrLookupHash::shortCode('678901'),
        'short_code_claimed' => true,
    ]);

    // The issuer's candidate cleanup is also constrained to this hash and
    // expiration. Assert the same MongoDB index admits reuse after release.
    $old->update(['short_code_claimed' => false]);
    $new = legacyShortCodeToken(shortCodeStudent(), '678901', [
        'short_code_hash' => QrLookupHash::shortCode('678901'),
        'short_code_claimed' => true,
    ]);

    expect($old->fresh()->short_code_claimed)->toBeFalse()
        ->and($new->short_code_claimed)->toBeTrue();
});

test('a missing lookup key fails visibly instead of using an unkeyed hash', function () {
    config()->set('qr.lookup_key', null);

    expect(fn () => QrLookupHash::shortCode('123456'))
        ->toThrow(RuntimeException::class);
});
