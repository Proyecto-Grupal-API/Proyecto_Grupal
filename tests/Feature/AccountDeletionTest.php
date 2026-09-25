<?php

use App\Actions\Users\DeleteUserAccount;
use App\Models\AcademicStatusHistory;
use App\Models\CommunicationPreference;
use App\Models\Consent;
use App\Models\CredentialEvent;
use App\Models\Device;
use App\Models\EventOutbox;
use App\Models\NfcCard;
use App\Models\QrToken;
use App\Models\QrValidation;
use App\Models\Role;
use App\Models\ServiceClient;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserSession;
use App\Services\IdentityService;
use App\Services\StudentStatusService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function deletionStudent(): array
{
    $user = User::factory()->create();
    $profile = StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'DEL-'.(string) $user->getKey(),
        'academic_status' => 'active',
    ]);

    return [$user, $profile];
}

test('account closure is logical, requires the password, and excludes the owner from authentication and operational student queries', function () {
    [$user, $profile] = deletionStudent();
    $user->forceFill([
        'two_factor_secret' => 'test-only-encrypted-secret',
        'two_factor_recovery_codes' => 'test-only-recovery-codes',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $other = User::factory()->create();
    AcademicStatusHistory::create([
        'student_profile_id' => (string) $profile->getKey(),
        'from_status' => 'active', 'to_status' => 'suspended',
        'reason' => 'Historical record', 'changed_by' => (string) $other->getKey(), 'changed_at' => now(),
    ]);
    $consent = Consent::create([
        'user_id' => (string) $user->getKey(), 'student_profile_id' => (string) $profile->getKey(),
        'type' => 'privacy', 'status' => 'accepted', 'accepted_at' => now(),
        'actor_id' => (string) $user->getKey(),
    ]);
    CommunicationPreference::create([
        'user_id' => (string) $user->getKey(), 'email' => true,
    ]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($user)->from('/profile')->delete('/profile', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')->assertRedirect('/profile');
    expect(User::find($user->getKey()))->not->toBeNull();

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect('/');
    $this->assertGuest();
    $deleted = User::withTrashed()->findOrFail($user->getKey());
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->password)->toBeNull()
        ->and($deleted->remember_token)->toBeNull()
        ->and($deleted->two_factor_secret)->toBeNull()
        ->and($deleted->two_factor_recovery_codes)->toBeNull()
        ->and($deleted->two_factor_confirmed_at)->toBeNull()
        ->and(User::find($user->getKey()))->toBeNull()
        ->and(Auth::getProvider()->retrieveById($user->getKey()))->toBeNull()
        ->and(StudentProfile::find($profile->getKey()))->not->toBeNull()
        ->and(AcademicStatusHistory::count())->toBe(1)
        ->and(Consent::find($consent->getKey())->status)->toBe('accepted')
        ->and(CommunicationPreference::where('user_id', (string) $user->getKey())->count())->toBe(1)
        ->and(User::find($other->getKey()))->not->toBeNull();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors();
    $this->actingAs($admin)->get(route('students.index'))
        ->assertInertia(fn ($page) => $page->where('statistics.total', 0));
    expect(fn () => app(StudentStatusService::class)->forUserId((string) $user->getKey()))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('closure revokes identification, dynamic and legacy QR and disables all devices and Mongo sessions', function () {
    [$user] = deletionStudent();
    [$other] = deletionStudent();
    $service = app(IdentityService::class);
    $identification = $service->issueIdentificationQrToken($user);
    $dynamic = $service->issueDynamicQrToken($user);
    $legacy = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(),
    ]);
    $expired = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->subHour(),
    ]);
    $consumed = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(), 'consumed_at' => now()->subMinute(),
    ]);
    $alreadyRevoked = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(), 'revoked_at' => now()->subMinute(),
    ]);
    $originalRevokedAt = $alreadyRevoked->revoked_at->toISOString();
    $otherQr = $service->issueIdentificationQrToken($other);
    $device = Device::create([
        'user_id' => (string) $user->getKey(), 'fingerprint' => 'del-device',
        'is_trusted' => true, 'device_name' => 'Test',
    ]);
    $sessions = collect([1, 2])->map(fn ($number) => UserSession::create([
        'user_id' => (string) $user->getKey(), 'device_id' => (string) $device->getKey(),
        'started_at' => now(), 'last_activity_at' => now(), 'ip_address' => '127.0.0.1',
        'user_agent' => 'Test '.$number,
    ]));
    DB::connection('mongodb')->table('password_reset_tokens')->insert([
        'email' => $user->email, 'token' => 'test-only-reset', 'created_at' => now(),
    ]);

    app(DeleteUserAccount::class)->execute($user);

    foreach ([$identification->token, $dynamic->token, $legacy] as $token) {
        expect($token->fresh()->revoked_at)->not->toBeNull();
    }
    expect($expired->fresh()->revoked_at)->toBeNull()
        ->and($consumed->fresh()->revoked_at)->toBeNull()
        ->and($consumed->fresh()->consumed_at)->not->toBeNull()
        ->and($alreadyRevoked->fresh()->revoked_at->toISOString())->toBe($originalRevokedAt);
    expect($otherQr->token->fresh()->revoked_at)->toBeNull();
    expect($device->fresh()->is_trusted)->toBeFalse()
        ->and($device->fresh()->revoked_at)->not->toBeNull()
        ->and(DB::connection('mongodb')->table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
    foreach ($sessions as $session) {
        expect($session->fresh()->revoked_at)->not->toBeNull()
            ->and($session->fresh()->revoked_reason)->toBe('account_deleted');
    }
    expect($service->validateQrCode($identification->presentedCode, null, null, null)['result'])->toBe('revoked')
        ->and($service->validateQrCode($dynamic->presentedCode, null, null, null)['result'])->toBe('revoked');
});

test('an existing password reset token cannot reactivate a closed account', function () {
    [$user] = deletionStudent();
    $token = Password::createToken($user);
    app(DeleteUserAccount::class)->execute($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
    ])->assertSessionHasErrors('email');

    $closed = User::withTrashed()->findOrFail($user->getKey());
    expect($closed->trashed())->toBeTrue()
        ->and($closed->password)->toBeNull()
        ->and(User::find($user->getKey()))->toBeNull();
});

test('orphan and soft-deleted owner QR fail closed without consumption or valid audit', function () {
    $service = app(IdentityService::class);
    $orphan = QrToken::create([
        'user_id' => '000000000000000000000001', 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(),
    ]);
    $result = $service->validateQrCode($orphan->code, null, 'test', null);
    expect($result)->toBe(['ok' => false, 'result' => 'revoked', 'identity' => null])
        ->and($orphan->fresh()->consumed_at)->toBeNull()
        ->and(QrValidation::where('qr_token_id', (string) $orphan->getKey())->where('result', 'valid')->count())->toBe(0);

    [$user] = deletionStudent();
    $issued = $service->issueDynamicQrToken($user);
    $user->delete();
    $result = $service->validateQrCode($issued->presentedCode, null, 'test', null);
    expect($result)->toBe(['ok' => false, 'result' => 'revoked', 'identity' => null])
        ->and($issued->token->fresh()->consumed_at)->toBeNull();
});

test('the OAuth QR API returns 422 rather than 500 for an orphan credential', function () {
    $client = ServiceClient::create([
        'name' => 'Deletion test validator',
        'client_id' => 'del_'.Str::lower(Str::random(16)),
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['identity:qr:validate'], 'active' => true,
    ]);
    $bearer = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'identity:qr:validate',
    ])->assertOk()->json('access_token');
    $orphan = QrToken::create([
        'user_id' => '000000000000000000000002', 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(),
    ]);

    $this->postJson('/api/v1/identity/qr-validate', ['code' => $orphan->code], [
        'Authorization' => 'Bearer '.$bearer,
    ])->assertUnprocessable()->assertExactJson([
        'ok' => false, 'result' => 'revoked', 'identity' => null,
    ]);
    expect($orphan->fresh()->consumed_at)->toBeNull()
        ->and(QrValidation::where('qr_token_id', (string) $orphan->getKey())->where('result', 'valid')->count())->toBe(0);
});

test('closure blocks only operational NFC cards and preserves terminal cards and history', function () {
    [$user] = deletionStudent();
    $cards = [];
    foreach (['active', 'suspended', 'blocked', 'replaced'] as $status) {
        $cards[$status] = NfcCard::create([
            'user_id' => (string) $user->getKey(), 'uid' => 'DEL-NFC-'.$status,
            'registered_by' => (string) $user->getKey(), 'registered_at' => now(),
            'status' => $status,
        ]);
    }

    app(DeleteUserAccount::class)->execute($user);
    foreach (['active', 'suspended'] as $status) {
        expect($cards[$status]->fresh()->status)->toBe('blocked')
            ->and($cards[$status]->fresh()->blocked_at)->not->toBeNull();
    }
    expect($cards['blocked']->fresh()->status)->toBe('blocked')
        ->and($cards['replaced']->fresh()->status)->toBe('replaced')
        ->and(CredentialEvent::count())->toBe(2)
        ->and(EventOutbox::count())->toBe(2);
    foreach (CredentialEvent::all() as $event) {
        expect($event->event_type)->toBe('blocked')
            ->and($event->new_status)->toBe('blocked')
            ->and($event->reason)->toBe('Baja de cuenta del propietario')
            ->and((string) $event->performed_by)->toBe((string) $user->getKey());
    }
    foreach (EventOutbox::all() as $outbox) {
        expect($outbox->event_name)->toBe('identity.credential.changed.v1')
            ->and($outbox->payload['operation'])->toBe('status_changed')
            ->and($outbox->payload['status'])->toBe('blocked');
    }

    app(DeleteUserAccount::class)->execute($user);
    expect(CredentialEvent::count())->toBe(2)->and(EventOutbox::count())->toBe(2);
});

test('a closed owner cannot receive a new NFC card or have an existing card reactivated', function () {
    [$owner] = deletionStudent();
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $card = NfcCard::create([
        'user_id' => (string) $owner->getKey(), 'uid' => 'DEL-CLOSED-NFC',
        'registered_by' => (string) $admin->getKey(), 'registered_at' => now(), 'status' => 'active',
    ]);
    app(DeleteUserAccount::class)->execute($owner);

    $this->actingAs($admin)->post(route('nfc-cards.store'), [
        'user_id' => (string) $owner->getKey(), 'uid' => 'DEL-NEW-NFC',
    ])->assertSessionHasErrors('user_id');
    $this->actingAs($admin)->patch(route('nfc-cards.update-status', $card), [
        'status' => 'active', 'reason' => 'Attempted reactivation',
    ])->assertSessionHasErrors('status');

    expect($card->fresh()->status)->toBe('blocked')
        ->and(NfcCard::count())->toBe(1)
        ->and(CredentialEvent::count())->toBe(1)
        ->and(EventOutbox::count())->toBe(1);
});

test('a transaction failure restores user, QR and NFC state and does not log partial changes', function () {
    [$user] = deletionStudent();
    $qr = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(),
    ]);
    $card = NfcCard::create([
        'user_id' => (string) $user->getKey(), 'uid' => 'DEL-ROLLBACK',
        'registered_by' => (string) $user->getKey(), 'registered_at' => now(), 'status' => 'active',
    ]);
    CredentialEvent::creating(function (): void {
        throw new RuntimeException('Deliberate deletion history failure');
    });

    try {
        expect(fn () => app(DeleteUserAccount::class)->execute($user))
            ->toThrow(RuntimeException::class, 'Deliberate deletion history failure');
        expect(User::find($user->getKey()))->not->toBeNull()
            ->and($qr->fresh()->revoked_at)->toBeNull()
            ->and($card->fresh()->status)->toBe('active')
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        CredentialEvent::flushEventListeners();
    }
});

test('an outbox persistence failure rolls back the full account closure', function () {
    [$user] = deletionStudent();
    $qr = QrToken::create([
        'user_id' => (string) $user->getKey(), 'code' => QrToken::generateCode(),
        'type' => 'dynamic', 'expires_at' => now()->addHour(),
    ]);
    $card = NfcCard::create([
        'user_id' => (string) $user->getKey(), 'uid' => 'DEL-OUTBOX-ROLLBACK',
        'registered_by' => (string) $user->getKey(), 'registered_at' => now(), 'status' => 'active',
    ]);
    EventOutbox::creating(function (): void {
        throw new RuntimeException('Deliberate deletion outbox failure');
    });

    try {
        expect(fn () => app(DeleteUserAccount::class)->execute($user))
            ->toThrow(RuntimeException::class, 'Deliberate deletion outbox failure');
        expect(User::find($user->getKey()))->not->toBeNull()
            ->and($qr->fresh()->revoked_at)->toBeNull()
            ->and($card->fresh()->status)->toBe('active')
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }
});
