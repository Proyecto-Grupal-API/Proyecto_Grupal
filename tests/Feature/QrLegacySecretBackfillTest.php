<?php

use App\Models\QrToken;
use App\Models\User;
use App\Services\IdentityService;
use App\Services\QrLegacySecretBackfill;
use App\Support\QrLookupHash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function legacyQr(array $fields = []): QrToken
{
    return QrToken::create(array_merge([
        'user_id' => (string) User::factory()->create()->getKey(),
        'type' => 'dynamic',
        'code' => QrToken::generateCode(),
        'short_code' => (string) random_int(100000, 999999),
        'expires_at' => now()->addMinute(),
    ], $fields));
}

function rawLegacyQr(QrToken $token): array
{
    return (array) DB::connection('mongodb')->getCollection('qr_tokens')->findOne(['code' => $token->code]);
}

function backfillReport(array $options = []): array
{
    $exit = Artisan::call('qr:secrets-backfill', $options);
    return [$exit, Artisan::output()];
}

test('dry run inventories legacy tokens without modifying them or exposing secrets', function () {
    $token = legacyQr(['short_code' => '123456']);
    [$exit, $output] = backfillReport(['--dry-run' => true, '--batch' => 1]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('mode=DRY_RUN', 'scanned=1', 'legacy_dynamic_convertible=1', 'converted=0', 'READY=false')
        ->not->toContain($token->code, $token->short_code)
        ->and(rawLegacyQr($token))->not->toHaveKeys(['code_hash', 'short_code_hash']);
});

test('dynamic backfill adds hashes and an active claim but retains plaintext and functional timestamps', function () {
    $token = legacyQr(['short_code' => '234567']);
    $before = rawLegacyQr($token);
    [$exit, $output] = backfillReport(['--apply' => true, '--batch' => 1]);
    $after = rawLegacyQr($token);

    expect($exit)->toBe(0)
        ->and($output)->toContain('converted=1', 'READY=true')
        ->and($after['code'])->toBe($token->code)
        ->and($after['short_code'])->toBe('234567')
        ->and($after['code_hash'])->toBe(QrLookupHash::code($token->code))
        ->and($after['short_code_hash'])->toBe(QrLookupHash::shortCode('234567'))
        ->and($after['short_code_claimed'])->toBeTrue()
        ->and($after['expires_at'])->toEqual($before['expires_at'])
        ->and($after['updated_at'])->toEqual($before['updated_at']);
});

test('identification backfill verifies ciphertext and preserves it on rerun', function () {
    $token = legacyQr([
        'type' => 'identification', 'short_code' => null, 'expires_at' => now()->addDay(),
    ]);
    [$exit, $firstOutput] = backfillReport(['--apply' => true]);
    $first = rawLegacyQr($token);
    [$secondExit, $secondOutput] = backfillReport(['--apply' => true]);
    $second = rawLegacyQr($token);

    expect($exit)->toBe(0)
        ->and($firstOutput)->toContain('legacy_identification_convertible=1', 'converted=1', 'READY=true')
        ->and($first['code'])->toBe($token->code)
        ->and($first['code_hash'])->toBe(QrLookupHash::code($token->code))
        ->and(Crypt::decryptString($first['code_encrypted']))->toBe($token->code)
        ->and($secondExit)->toBe(0)
        ->and($secondOutput)->toContain('hybrid_valid=1', 'converted=0')
        ->and($second['code_encrypted'])->toBe($first['code_encrypted'])
        ->and($second['updated_at'])->toEqual($first['updated_at']);
});

test('valid hybrid is left untouched and mismatched hash or ciphertext is reported', function () {
    $code = QrToken::generateCode();
    $hybrid = legacyQr([
        'code' => $code, 'short_code' => '345678',
        'code_hash' => QrLookupHash::code($code),
        'short_code_hash' => QrLookupHash::shortCode('345678'),
        'short_code_claimed' => true,
    ]);
    $before = rawLegacyQr($hybrid);
    $badHash = legacyQr(['code_hash' => QrLookupHash::code('different')]);
    $badCipher = legacyQr([
        'type' => 'identification', 'short_code' => null,
        'code_encrypted' => Crypt::encryptString('different'),
    ]);

    [$exit, $output] = backfillReport(['--apply' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('hybrid_valid=1', 'inconsistent=2', 'READY=false')
        ->and(rawLegacyQr($hybrid))->toEqual($before)
        ->and(rawLegacyQr($badHash))->toHaveKey('code_hash')
        ->and(rawLegacyQr($badCipher))->not->toHaveKey('code_hash');
});

test('missing secrets are never invented and verification refuses an incomplete collection', function () {
    $token = legacyQr(['short_code' => null]);
    [$exit, $output] = backfillReport(['--apply' => true]);
    [$verifyExit, $verifyOutput] = backfillReport(['--verify' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('incomplete=1', 'converted=0', 'READY=false')
        ->and($verifyExit)->toBe(1)
        ->and($verifyOutput)->toContain('mode=VERIFY', 'READY=false')
        ->and(rawLegacyQr($token))->not->toHaveKey('code_hash');
});

test('revoked or consumed dynamic without a short code is explicitly terminal-convertible', function (array $state) {
    $token = legacyQr(array_merge([
        'short_code' => null,
        'expires_at' => now()->addMinute(),
    ], $state));
    $analysis = app(QrLegacySecretBackfill::class)->analyze(rawLegacyQr($token));

    expect($analysis['status'])->toBe('LEGACY_DYNAMIC_TERMINAL_CONVERTIBLE')
        ->and($analysis['updates'])->toBe(['code_hash' => QrLookupHash::code($token->code)]);
})->with([
    [['revoked_at' => now()]],
    [['consumed_at' => now()]],
]);

test('active or merely expired dynamic without a short code stays incomplete', function (array $state) {
    $token = legacyQr(array_merge(['short_code' => null], $state));
    $analysis = app(QrLegacySecretBackfill::class)->analyze(rawLegacyQr($token));

    expect($analysis['status'])->toBe('INCOMPLETE')
        ->and($analysis['updates'])->toBe([]);
})->with([
    [[]],
    [['expires_at' => now()->subMinute()]],
]);

test('terminal dynamic dry run is non-mutating and apply adds only a code hash', function () {
    $token = legacyQr([
        'short_code' => null,
        'revoked_at' => now(),
        'expires_at' => now()->subMinute(),
    ]);
    $before = rawLegacyQr($token);
    [$dryExit, $dryOutput] = backfillReport(['--dry-run' => true]);

    expect($dryExit)->toBe(0)
        ->and($dryOutput)->toContain('legacy_dynamic_terminal_convertible=1', 'converted=0', 'READY=false')
        ->not->toContain($token->code)
        ->and(rawLegacyQr($token))->toEqual($before);

    [$applyExit, $applyOutput] = backfillReport(['--apply' => true]);
    $after = rawLegacyQr($token);
    expect($applyExit)->toBe(0)
        ->and($applyOutput)->toContain('legacy_dynamic_terminal_convertible=1', 'converted=1', 'READY=true')
        ->and(array_keys(array_diff_key($after, $before)))->toBe(['code_hash'])
        ->and($after['code_hash'])->toBe(QrLookupHash::code($token->code))
        ->and($after['code'])->toBe($before['code'])
        ->and($after)->not->toHaveKeys(['short_code_hash', 'short_code_claimed'])
        ->and($after['expires_at'])->toEqual($before['expires_at'])
        ->and($after['revoked_at'])->toEqual($before['revoked_at'])
        ->and($after['consumed_at'] ?? null)->toBeNull();

    [$secondExit, $secondOutput] = backfillReport(['--apply' => true]);
    expect($secondExit)->toBe(0)
        ->and($secondOutput)->toContain('legacy_dynamic_terminal_prepared=1', 'converted=0', 'READY=true')
        ->and(rawLegacyQr($token))->toEqual($after);
});

test('prepared terminal dynamic preserves historical full-code result and has no short-code lookup', function (array $state, string $result) {
    $token = legacyQr(array_merge([
        'short_code' => null,
        'expires_at' => now()->addMinute(),
    ], $state));
    backfillReport(['--apply' => true]);
    $service = app(IdentityService::class);

    expect($service->validateQrCode($service->buildQrPayload($token), null, null, null)['result'])->toBe($result)
        ->and($service->validateQrCode('999999', null, null, null)['result'])->toBe('not_found')
        ->and(rawLegacyQr($token)['short_code'] ?? null)->toBeNull()
        ->and(rawLegacyQr($token))->not->toHaveKeys(['short_code_hash', 'short_code_claimed']);
})->with([
    [['revoked_at' => now()], 'revoked'],
    [['consumed_at' => now()], 'consumed'],
]);

test('terminal without short code is not prepared if an unverifiable hash or active claim exists', function (array $extra, string $expected) {
    $token = legacyQr(array_merge([
        'short_code' => null,
        'revoked_at' => now(),
    ], $extra));
    $analysis = app(QrLegacySecretBackfill::class)->analyze(rawLegacyQr($token));

    expect($analysis['status'])->toBe($expected)
        ->and($analysis['updates'])->toBe([]);
})->with([
    [['short_code_hash' => str_repeat('a', 64)], 'INCOMPLETE'],
    [['short_code_claimed' => true], 'CONFLICT'],
]);

test('a code hash collision is detected before writing either legacy document', function () {
    $legacy = legacyQr();
    QrToken::create([
        'user_id' => (string) User::factory()->create()->getKey(),
        'type' => 'dynamic', 'code_hash' => QrLookupHash::code($legacy->code),
        'short_code_hash' => QrLookupHash::shortCode('456789'),
        'short_code_claimed' => true, 'expires_at' => now()->addMinute(),
    ]);

    [, $output] = backfillReport(['--apply' => true]);
    expect($output)->toContain('conflicts=1', 'READY=false')
        ->and(rawLegacyQr($legacy))->not->toHaveKey('code_hash');
});

test('a short claim collision and two active legacy codes are not resolved automatically', function () {
    $first = legacyQr(['short_code' => '567890']);
    $second = legacyQr(['short_code' => '567890']);

    [, $output] = backfillReport(['--apply' => true]);
    expect($output)->toContain('conflicts=2', 'converted=0', 'READY=false')
        ->and(rawLegacyQr($first))->not->toHaveKey('short_code_hash')
        ->and(rawLegacyQr($second))->not->toHaveKey('short_code_hash');
});

test('an existing claim prevents a competing legacy claim', function () {
    $legacy = legacyQr(['short_code' => '678901']);
    QrToken::create([
        'user_id' => (string) User::factory()->create()->getKey(),
        'type' => 'dynamic', 'code_hash' => QrLookupHash::code(QrToken::generateCode()),
        'short_code_hash' => QrLookupHash::shortCode('678901'),
        'short_code_claimed' => true, 'expires_at' => now()->addMinute(),
    ]);
    [, $output] = backfillReport(['--apply' => true]);

    expect($output)->toContain('conflicts=1', 'READY=false')
        ->and(rawLegacyQr($legacy))->not->toHaveKey('short_code_hash');
});

test('expired consumed and revoked legacy tokens keep state and receive no active claim', function (array $state) {
    $token = legacyQr($state);
    $before = rawLegacyQr($token);
    [$exit] = backfillReport(['--apply' => true]);
    $after = rawLegacyQr($token);

    expect($exit)->toBe(0)
        ->and($after['code_hash'])->toBe(QrLookupHash::code($token->code))
        ->and($after)->not->toHaveKey('short_code_claimed')
        ->and($after['expires_at'])->toEqual($before['expires_at'])
        ->and($after['consumed_at'] ?? null)->toEqual($before['consumed_at'] ?? null)
        ->and($after['revoked_at'] ?? null)->toEqual($before['revoked_at'] ?? null);
})->with([
    [['expires_at' => now()->subMinute()]],
    [['consumed_at' => now()]],
    [['revoked_at' => now()]],
]);

test('compare-and-set refuses a document changed after inspection', function () {
    $token = legacyQr();
    $service = app(QrLegacySecretBackfill::class);
    $snapshot = rawLegacyQr($token);
    $analysis = $service->analyze($snapshot);
    $token->update(['revoked_at' => now()]);

    expect($service->apply($snapshot, $analysis))->toBeFalse()
        ->and(rawLegacyQr($token))->not->toHaveKey('code_hash');
});

test('compare-and-set detects a concurrent structural change even when field count is unchanged', function () {
    $token = legacyQr();
    $service = app(QrLegacySecretBackfill::class);
    $collection = $service->collection();
    $collection->updateOne(['code' => $token->code], ['$set' => ['legacy_note' => null]]);
    $snapshot = rawLegacyQr($token);
    $analysis = $service->analyze($snapshot);
    $collection->updateOne(['code' => $token->code], [
        '$unset' => ['legacy_note' => ''],
        '$set' => ['another_note' => 'changed'],
    ]);

    expect($service->apply($snapshot, $analysis))->toBeFalse()
        ->and(rawLegacyQr($token))->not->toHaveKey('code_hash');
});

test('verification is ready for secure and valid hybrid documents without changing QR-B.3 tokens', function () {
    $service = app(IdentityService::class);
    $issued = $service->issueDynamicQrToken(User::factory()->create());
    $before = (array) DB::connection('mongodb')->getCollection('qr_tokens')
        ->findOne(['code_hash' => $issued->token->code_hash]);
    $hybrid = legacyQr();
    backfillReport(['--apply' => true]);

    [$exit, $output] = backfillReport(['--verify' => true, '--batch' => 1]);
    $after = (array) DB::connection('mongodb')->getCollection('qr_tokens')
        ->findOne(['code_hash' => $issued->token->code_hash]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('already_secure=1', 'hybrid_valid=1', 'READY=true')
        ->and($after)->toEqual($before)
        ->and(rawLegacyQr($hybrid))->toHaveKeys(['code', 'short_code', 'code_hash', 'short_code_hash']);
});
