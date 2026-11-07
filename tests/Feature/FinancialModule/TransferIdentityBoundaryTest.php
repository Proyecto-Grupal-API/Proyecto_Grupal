<?php

use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Str;

test('transfer identity rejects initial password state and revoked sessions', function () {
    $user = User::factory()->create();
    $id = (string) $user->getKey();
    $session = UserSession::create(['user_id' => $id, 'device_id' => 'test-device', 'started_at' => now()]);
    $accounts = app(TransferAccountProvider::class);
    $sessions = app(TransferSessionContextProvider::class);

    $accounts->assertActive($id);
    expect($sessions->resolve($id, (string) $session->getKey())['status'])->toBe('VERIFIED');
    $user->forceFill(['must_change_password' => true])->save();
    expect(fn () => $accounts->assertActive($id))->toThrow(InvalidArgumentException::class)
        ->and($sessions->resolve($id, (string) $session->getKey())['status'])->toBe('NOT_VERIFIED');

    $user->forceFill(['must_change_password' => false])->save();
    $session->update(['revoked_at' => now()]);
    expect($sessions->resolve($id, (string) $session->getKey())['status'])->toBe('NOT_VERIFIED');
});

test('transfer recipient by enrollment requires the E1 directory integrity gate', function () {
    $user = User::factory()->create();
    $id = (string) $user->getKey();
    $enrollment = 'TEST-'.Str::upper(Str::random(10));
    StudentProfile::create(['user_id' => $id, 'enrollment_number' => $enrollment]);
    $recipients = app(TransferRecipientProvider::class);

    config(['identity_directory.enrollment_integrity_verified' => false]);
    expect(fn () => $recipients->resolve('ENROLLMENT', $enrollment, $id, null))
        ->toThrow(FinancialDependencyUnavailableException::class);

    config(['identity_directory.enrollment_integrity_verified' => true]);
    expect($recipients->resolve('ENROLLMENT', strtolower($enrollment), $id, null))->toBe([
        'user_id' => $id, 'name' => $user->name, 'enrollment_number' => $enrollment,
    ]);
    expect($recipients->resolve('USER_ID', strtoupper($id), $id, null)['user_id'])->toBe($id);
    $user->forceFill(['email_verified_at' => null])->save();
    expect(fn () => $recipients->resolve('USER_ID', $id, $id, null))->toThrow(InvalidArgumentException::class);
});
