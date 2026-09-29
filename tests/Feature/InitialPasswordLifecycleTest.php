<?php

use App\Actions\Students\ReissueTemporaryPassword;
use App\Actions\Students\UpsertStudentProfile;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\EventOutbox;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserSession;
use App\Services\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

function initialPasswordStudentData(): array
{
    $campus = Campus::create(['code' => 'INIT', 'name' => 'Campus Inicial', 'is_active' => true]);
    $program = AcademicProgram::create([
        'campus_id' => (string) $campus->getKey(),
        'code' => 'INI',
        'name' => 'Programa Inicial',
        'is_active' => true,
    ]);

    return [
        'name' => 'Estudiante Inicial',
        'email' => 'initial@example.test',
        'enrollment_number' => 'INIT-001',
        'campus_id' => (string) $campus->getKey(),
        'academic_program_id' => (string) $program->getKey(),
        'current_semester' => 1,
        'academic_status' => 'active',
        'preferred_contact_channel' => 'institutional_email',
        'locale' => 'es-MX',
    ];
}

function initialPasswordAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    withConfirmedTestTwoFactor($admin);

    return $admin;
}

it('creates an individual student with a one-time CSPRNG credential and no persisted plaintext', function () {
    $admin = initialPasswordAdmin();
    $response = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())
        ->assertCreated()->assertJsonStructure(['student_id', 'temporary_password']);

    $temporary = $response->json('temporary_password');
    $student = User::findOrFail($response->json('student_id'));

    expect($temporary)->toMatch('/^[a-f0-9]{48}$/')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Hash::check($temporary, $student->password))->toBeTrue()
        ->and($student->password)->not->toBe($temporary)
        ->and($student->account_activation_pending)->toBeFalse()
        ->and($student->must_change_password)->toBeTrue()
        ->and($student->toArray())->not->toHaveKey('password')
        ->and($student->toArray())->not->toHaveKey('must_change_password')
        ->and(StudentProfile::where('user_id', (string) $student->getKey())->exists())->toBeTrue();

    foreach (SecurityEvent::get() as $event) {
        expect(json_encode($event->getAttributes()))->not->toContain($temporary);
    }
    foreach (EventOutbox::get() as $event) {
        expect(json_encode($event->getAttributes()))->not->toContain($temporary);
    }
    expect(SecurityEvent::where('type', 'temporary_credential_issued')->count())->toBe(1);
});

it('allows temporary login but blocks normal web and Fortify routes until the change', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $temporary = $created->json('temporary_password');
    $this->post('/logout');

    $this->post('/login', ['email' => $student->email, 'password' => $temporary])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($student);
    $this->get('/dashboard')->assertRedirect(route('password.initial.edit'));
    $this->get('/students')->assertRedirect(route('password.initial.edit'));
    $this->get('/profile')->assertRedirect(route('password.initial.edit'));
    $this->get('/password/initial')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/InitialPassword'));
    $this->put('/password', ['current_password' => $temporary, 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])
        ->assertRedirect(route('password.initial.edit'));
    $this->put('/user/password', ['current_password' => $temporary, 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])
        ->assertRedirect(route('password.initial.edit'));
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('requires the current temporary password and canonical new-password validation', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));

    $this->actingAs($student)->put('/password/initial', [
        'current_password' => 'wrong',
        'password' => 'Changed-password-123',
        'password_confirmation' => 'Changed-password-123',
    ])->assertSessionHasErrors('current_password');
    $this->put('/password/initial', [
        'current_password' => $created->json('temporary_password'),
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
    $this->put('/password/initial', [
        'current_password' => $created->json('temporary_password'),
        'password' => 'Changed-password-123',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
    expect($student->fresh()->must_change_password)->toBeTrue();
});

it('rejects reusing the temporary password without clearing the flag or touching sessions', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $temporary = $created->json('temporary_password');
    $originalHash = $student->password;
    $originalRememberToken = $student->remember_token;
    $currentSession = UserSession::create(['user_id' => (string) $student->getKey(), 'device_id' => 'current-device', 'started_at' => now()]);
    $otherSession = UserSession::create(['user_id' => (string) $student->getKey(), 'device_id' => 'other-device', 'started_at' => now()]);

    $this->actingAs($student)->withSession(['cd_session_id' => (string) $currentSession->getKey()])
        ->put('/password/initial', [
            'current_password' => $temporary,
            'password' => $temporary,
            'password_confirmation' => $temporary,
        ])->assertSessionHasErrors('password');

    $student = $student->fresh();
    expect($student->password)->toBe($originalHash)
        ->and(Hash::check($temporary, $student->password))->toBeTrue()
        ->and($student->must_change_password)->toBeTrue()
        ->and($student->remember_token)->toBe($originalRememberToken)
        ->and($currentSession->fresh()->revoked_at)->toBeNull()
        ->and($otherSession->fresh()->revoked_at)->toBeNull()
        ->and(SecurityEvent::where('type', 'mandatory_password_changed')->count())->toBe(0);
    $this->get('/dashboard')->assertRedirect(route('password.initial.edit'));
});

it('atomically completes the initial change and invalidates the temporary password', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $temporary = $created->json('temporary_password');
    $currentSession = UserSession::create(['user_id' => (string) $student->getKey(), 'device_id' => 'current-device', 'started_at' => now()]);
    $otherSession = UserSession::create(['user_id' => (string) $student->getKey(), 'device_id' => 'other-device', 'started_at' => now()]);

    $this->actingAs($student)->withSession(['cd_session_id' => (string) $currentSession->getKey()])->put('/password/initial', [
        'current_password' => $temporary,
        'password' => 'Changed-password-123',
        'password_confirmation' => 'Changed-password-123',
    ])->assertRedirect(route('dashboard'));

    $student = $student->fresh();
    expect($student->must_change_password)->toBeFalse()
        ->and(Hash::check('Changed-password-123', $student->password))->toBeTrue()
        ->and(Hash::check($temporary, $student->password))->toBeFalse()
        ->and(SecurityEvent::where('type', 'mandatory_password_changed')->count())->toBe(1)
        ->and($currentSession->fresh()->revoked_at)->toBeNull()
        ->and($otherSession->fresh()->revoked_at)->not->toBeNull();
    $this->get('/dashboard')->assertOk();
    $this->post('/logout');
    $this->post('/login', ['email' => $student->email, 'password' => $temporary])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $student->email, 'password' => 'Changed-password-123'])
        ->assertRedirect(route('dashboard', absolute: false));
});

it('preserves historical users and the legacy pending-account login gate', function () {
    $historical = User::factory()->create();
    expect($historical->must_change_password)->toBeFalse();
    $this->actingAs($historical)->get('/dashboard')->assertOk();
    $this->post('/logout');

    $pending = User::factory()->create(['account_activation_pending' => true]);
    $this->post('/login', ['email' => $pending->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('makes a successful reset definitive and leaves state untouched for an invalid token', function () {
    $student = User::factory()->create(['account_activation_pending' => true]);
    $student->forceFill(['must_change_password' => true])->save();
    $oldHash = $student->password;

    $this->post('/reset-password', [
        'token' => 'invalid-token',
        'email' => $student->email,
        'password' => 'Changed-password-123',
        'password_confirmation' => 'Changed-password-123',
    ])->assertSessionHasErrors('email');
    expect($student->fresh()->password)->toBe($oldHash)
        ->and($student->fresh()->account_activation_pending)->toBeTrue()
        ->and($student->fresh()->must_change_password)->toBeTrue();

    $token = Password::createToken($student);
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $student->email,
        'password' => 'Changed-password-123',
        'password_confirmation' => 'Changed-password-123',
    ])->assertRedirect(route('login'));
    expect($student->fresh()->account_activation_pending)->toBeFalse()
        ->and($student->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('Changed-password-123', $student->fresh()->password))->toBeTrue();
});

it('sends a required-role user to 2FA enrollment after changing the temporary password', function () {
    $user = User::factory()->create(['must_change_password' => true]);
    $user->assignRole(Role::MAESTRO);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.initial.edit'));
    $this->put('/password/initial', [
        'current_password' => 'password',
        'password' => 'Changed-password-123',
        'password_confirmation' => 'Changed-password-123',
    ])->assertRedirect(route('two-factor.enrollment'));
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
});

it('preserves an existing 2FA challenge before enforcing the initial change', function () {
    $user = User::factory()->create(['must_change_password' => true]);
    $user->assignRole(Role::MAESTRO);
    withConfirmedTestTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login', absolute: false));
    $this->assertGuest();
    $this->post('/two-factor-challenge', ['recovery_code' => 'fixture-recovery-code'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get('/dashboard')->assertRedirect(route('password.initial.edit'));
});

it('blocks human Sanctum API and authenticated passkey-management routes', function () {
    $user = User::factory()->create(['must_change_password' => true]);
    // Sanctum's guard accepts the existing authenticated web session.
    $this->actingAs($user);
    $this->getJson('/api/v1/students/'.(string) $user->getKey().'/consents')
        ->assertStatus(423)->assertJsonPath('code', 'initial_password_change_required');

    $this->get('/user/passkeys/options')->assertRedirect(route('password.initial.edit'));
});

it('prevents a soft-deleted account from login or initial-password change', function () {
    $user = User::factory()->create(['must_change_password' => true]);
    $user->delete();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->get('/password/initial')->assertRedirect(route('login'));
    expect(User::withTrashed()->findOrFail($user->getKey())->trashed())->toBeTrue();
});

it('reissues only an unfinished initial credential, without disclosing or retaining the old one', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $old = $created->json('temporary_password');

    $reissued = $this->postJson('/students/'.$student->getKey().'/temporary-password')
        ->assertOk()->assertJsonStructure(['student_id', 'temporary_password']);
    $new = $reissued->json('temporary_password');
    expect($new)->not->toBe($old)
        ->and($reissued->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Hash::check($old, $student->fresh()->password))->toBeFalse()
        ->and(Hash::check($new, $student->fresh()->password))->toBeTrue()
        ->and(SecurityEvent::where('type', 'temporary_credential_reissued')->count())->toBe(1);
    $this->post('/students/'.$student->getKey().'/temporary-password')->assertStatus(406);
});

it('rejects a stale reissue after a definitive password was set before the conditional write', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $staleStudent = User::findOrFail($created->json('student_id'));
    $definitivePassword = 'Definitive-password-123';
    $staleStudent->fresh()->forceFill([
        'password' => Hash::make($definitivePassword),
        'account_activation_pending' => false,
        'must_change_password' => false,
    ])->save();

    $generator = new class extends TemporaryPasswordGenerator {
        public function generate(): string
        {
            return 'Generated-but-rejected-123';
        }
    };
    expect(fn () => (new ReissueTemporaryPassword($generator))->execute($staleStudent, $admin))
        ->toThrow(ConflictHttpException::class);

    $persisted = $staleStudent->fresh();
    expect(Hash::check($definitivePassword, $persisted->password))->toBeTrue()
        ->and(Hash::check('Generated-but-rejected-123', $persisted->password))->toBeFalse()
        ->and($persisted->must_change_password)->toBeFalse()
        ->and(SecurityEvent::where('type', 'temporary_credential_reissued')->count())->toBe(0);
});

it('returns HTTP 409 without a credential when reissue is no longer eligible', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $definitivePassword = 'Definitive-password-123';
    $student->forceFill([
        'password' => Hash::make($definitivePassword),
        'account_activation_pending' => false,
        'must_change_password' => false,
    ])->save();

    $response = $this->postJson('/students/'.$student->getKey().'/temporary-password')->assertStatus(409);

    expect($response->json())->not->toHaveKey('temporary_password');
    expect(Hash::check($definitivePassword, $student->fresh()->password))->toBeTrue()
        ->and($student->fresh()->must_change_password)->toBeFalse()
        ->and(SecurityEvent::where('type', 'temporary_credential_reissued')->count())->toBe(0);
});

it('allows a pending legacy account to receive a temporary credential through authorized reissue', function () {
    $admin = initialPasswordAdmin();
    $this->actingAs($admin)->post('/students', initialPasswordStudentData())->assertRedirect();
    $student = User::where('email', 'initial@example.test')->firstOrFail();
    expect($student->password)->toBeNull()->and($student->account_activation_pending)->toBeTrue();

    $response = $this->postJson('/students/'.$student->getKey().'/temporary-password')->assertOk();
    $student = $student->fresh();
    expect(Hash::check($response->json('temporary_password'), $student->password))->toBeTrue()
        ->and($student->account_activation_pending)->toBeFalse()
        ->and($student->must_change_password)->toBeTrue();
});

it('denies reissue to a user without student-management authority', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $originalHash = $student->password;
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)->postJson('/students/'.$student->getKey().'/temporary-password')
        ->assertForbidden();
    expect($response->json())->not->toHaveKey('temporary_password');
    expect($student->fresh()->password)->toBe($originalHash)
        ->and($student->fresh()->must_change_password)->toBeTrue()
        ->and(SecurityEvent::where('type', 'temporary_credential_reissued')->count())->toBe(0);
});

it('denies reissue of a soft-deleted student without changing its credential', function () {
    $admin = initialPasswordAdmin();
    $created = $this->actingAs($admin)->postJson('/students', initialPasswordStudentData())->assertCreated();
    $student = User::findOrFail($created->json('student_id'));
    $originalHash = $student->password;
    $student->delete();

    $response = $this->postJson('/students/'.$student->getKey().'/temporary-password')->assertNotFound();
    $deleted = User::withTrashed()->findOrFail($student->getKey());
    expect($response->json())->not->toHaveKey('temporary_password');
    expect($deleted->password)->toBe($originalHash)
        ->and($deleted->must_change_password)->toBeTrue()
        ->and($deleted->trashed())->toBeTrue()
        ->and(SecurityEvent::where('type', 'temporary_credential_reissued')->count())->toBe(0);
});

it('rolls back the individual account if writing its issuance audit record fails', function () {
    $admin = initialPasswordAdmin();
    SecurityEvent::creating(function (SecurityEvent $event): void {
        if ($event->type === 'temporary_credential_issued') {
            throw new RuntimeException('Deliberate audit failure');
        }
    });

    try {
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->postJson('/students', initialPasswordStudentData());
            $this->fail('Expected audit write to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Deliberate audit failure');
        }
        expect(User::where('email', 'initial@example.test')->exists())->toBeFalse()
            ->and(StudentProfile::where('enrollment_number', 'INIT-001')->exists())->toBeFalse();
    } finally {
        SecurityEvent::flushEventListeners();
    }
});
