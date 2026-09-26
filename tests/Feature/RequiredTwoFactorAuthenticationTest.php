<?php

use App\Models\Role;
use App\Models\UserSession;
use App\Models\User;
use App\Services\IdentityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('the policy only requires the three approved canonical roles regardless of scope', function (string $role, bool $required) {
    $user = User::factory()->create();
    $user->assignRole($role, 'service', 'test-service');

    expect($user->fresh()->requiresTwoFactorAuthentication())->toBe($required);
})->with([
    [Role::ADMIN, true],
    [Role::MAESTRO, true],
    [Role::STUDENT_MANAGER, true],
    [Role::ESTUDIANTE, false],
    [Role::SERVICIO_CAFETERIA, false],
    [Role::CONSEJO_ESTUDIANTIL, false],
]);

test('any required assignment wins over optional roles without changing contextual hasRole semantics', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);
    $user->assignRole(Role::ADMIN, 'business', 'one');

    expect($user->fresh()->requiresTwoFactorAuthentication())->toBeTrue()
        ->and($user->fresh()->hasRole(Role::ADMIN))->toBeFalse()
        ->and($user->fresh()->hasRole(Role::ADMIN, 'business', 'one'))->toBeTrue();
});

test('a historical role alone is not mapped to a required canonical role', function () {
    $user = User::factory()->create();
    $user->roles = [['name' => 'cashier', 'scope_type' => 'business', 'scope_id' => 'one']];
    $user->save();

    expect($user->fresh()->requiresTwoFactorAuthentication())->toBeFalse();
});

test('invalid required role configuration fails explicitly', function () {
    config()->set('security.two_factor_required_roles', ['not-a-canonical-role']);

    expect(fn () => User::factory()->create()->requiresTwoFactorAuthentication())
        ->toThrow(LogicException::class);
});

test('required user logs in but only enrollment and its essential routes are available', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::MAESTRO);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
    $this->get('/security/two-factor-enrollment')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Security/TwoFactorEnrollment')
        ->where('twoFactorEnabled', false));
    $this->get('/roles')->assertRedirect(route('two-factor.enrollment'));
    $this->get('/profile')->assertRedirect(route('two-factor.enrollment'));
    $this->postJson('/roles/assign', [])->assertStatus(423);
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

test('admin enrollment confirms the password through the actual Axios endpoint without bypassing TOTP', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
    $this->get('/security/two-factor-enrollment')->assertOk();

    $this->postJson('/user/two-factor-authentication')->assertStatus(423);
    $this->postJson('/confirm-password', ['password' => 'incorrect'])
        ->assertUnprocessable()->assertJsonValidationErrors('password')
        ->assertSessionMissing('auth.password_confirmed_at');
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));

    $this->postJson('/confirm-password', ['password' => 'password'])
        ->assertNoContent()->assertSessionHas('auth.password_confirmed_at');
    foreach (['/dashboard', '/roles', '/students', '/nfc-cards', '/identidad/qr'] as $path) {
        $this->get($path)->assertRedirect(route('two-factor.enrollment'));
    }

    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    $pending = $admin->fresh();
    expect($pending->two_factor_secret)->not->toBeNull()
        ->and($pending->two_factor_enabled)->toBeFalse();
    $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);
    $this->getJson('/user/two-factor-secret-key')->assertOk()->assertJsonStructure(['secretKey']);
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));

    $secret = Fortify::currentEncrypter()->decrypt($pending->two_factor_secret);
    $this->postJson('/user/confirmed-two-factor-authentication', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertSuccessful();
    expect($admin->fresh()->two_factor_enabled)->toBeTrue();
    $this->get('/dashboard')->assertOk();
});

test('pending setup stays limited until a valid confirmation and then permits normal access', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::STUDENT_MANAGER);
    $this->actingAs($user);

    $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    $pending = $user->fresh();
    expect($pending->two_factor_secret)->not->toBeNull()
        ->and($pending->two_factor_enabled)->toBeFalse();
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
    $this->getJson('/user/two-factor-qr-code')->assertOk();
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertUnprocessable();
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));

    $secret = Fortify::currentEncrypter()->decrypt($pending->two_factor_secret);
    $this->postJson('/user/confirmed-two-factor-authentication', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertSuccessful();
    expect($user->fresh()->two_factor_enabled)->toBeTrue();
    $this->get('/dashboard')->assertOk();
});

test('confirmed required user retains the login challenge and one use recovery code', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['one-use'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login', absolute: false));
    $this->assertGuest();
    $this->post('/two-factor-challenge', ['recovery_code' => 'one-use'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get('/dashboard')->assertOk();
    expect($user->fresh()->recoveryCodes())->not->toContain('one-use');
});

test('server rejects disable for required confirmed users and keeps optional disable', function () {
    $required = User::factory()->create();
    $required->assignRole(Role::ADMIN);
    $required->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($required)->withSession(['auth.password_confirmed_at' => time()]);
    $this->deleteJson('/user/two-factor-authentication')->assertForbidden();
    expect($required->fresh()->two_factor_enabled)->toBeTrue();

    $optional = User::factory()->create();
    $optional->assignRole(Role::ESTUDIANTE);
    $optional->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($optional)->withSession(['auth.password_confirmed_at' => time()]);
    $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
    expect($optional->fresh()->two_factor_enabled)->toBeFalse();
});

test('assignment and revocation change the obligation on the next request', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);
    $this->actingAs($user);
    $this->get('/dashboard')->assertOk();

    $user->assignRole(Role::MAESTRO, 'association', 'one');
    Auth::setUser($user->fresh());
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));

    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
    ])->save();
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));

    $user->revokeRole(Role::MAESTRO, 'association', 'one');
    Auth::setUser($user->fresh());
    $this->get('/dashboard')->assertOk();
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('a remotely revoked session takes precedence over enrollment after a role change', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();
    $session = UserSession::findOrFail(session('cd_session_id'));

    $user->assignRole(Role::MAESTRO);
    Auth::setUser($user->fresh());
    app(IdentityService::class)->revokeSession($user, $session, 'test_remote_revocation');

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session('cd_session_id'))->toBeNull();
});

test('a valid tracked session can still enter enrollment after a required role is assigned', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();
    expect(UserSession::find(session('cd_session_id')))->not->toBeNull();

    $user->assignRole(Role::MAESTRO);
    Auth::setUser($user->fresh());

    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
    $this->get('/security/two-factor-enrollment')->assertOk();
    $this->postJson('/confirm-password', ['password' => 'password'])
        ->assertNoContent()->assertSessionHas('auth.password_confirmed_at');
    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('a remotely revoked session cannot use an enrollment endpoint', function (string $method, string $path) {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();
    $session = UserSession::findOrFail(session('cd_session_id'));

    $user->assignRole(Role::MAESTRO);
    Auth::setUser($user->fresh());
    app(IdentityService::class)->revokeSession($user, $session, 'test_remote_revocation');

    $this->json($method, $path, ['password' => 'password'])
        ->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session('auth.password_confirmed_at'))->toBeNull()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
})->with([
    ['GET', '/security/two-factor-enrollment'],
    ['POST', '/confirm-password'],
    ['POST', '/user/confirm-password'],
    ['POST', '/user/two-factor-authentication'],
    ['GET', '/user/two-factor-qr-code'],
    ['GET', '/user/two-factor-secret-key'],
    ['DELETE', '/user/two-factor-authentication'],
]);

test('a remotely revoked session cannot confirm a pending factor', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();
    $session = UserSession::findOrFail(session('cd_session_id'));

    $user->assignRole(Role::MAESTRO);
    Auth::setUser($user->fresh());
    $this->postJson('/confirm-password', ['password' => 'password'])->assertNoContent();
    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    $pending = $user->fresh();
    $secret = Fortify::currentEncrypter()->decrypt($pending->two_factor_secret);

    app(IdentityService::class)->revokeSession($user, $session, 'test_remote_revocation');
    $this->postJson('/user/confirmed-two-factor-authentication', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()->two_factor_secret)->toBe($pending->two_factor_secret)
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('logout clears a remotely revoked session during required enrollment', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ESTUDIANTE);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();
    $session = UserSession::findOrFail(session('cd_session_id'));

    $user->assignRole(Role::MAESTRO);
    Auth::setUser($user->fresh());
    app(IdentityService::class)->revokeSession($user, $session, 'test_remote_revocation');

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session('cd_session_id'))->toBeNull();
});

test('pending activation takes precedence over required enrollment', function () {
    $user = User::factory()->create(['account_activation_pending' => true]);
    $user->assignRole(Role::MAESTRO);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('login.id');
    $this->assertGuest();
});

test('a soft deleted required user cannot authenticate or reach enrollment', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);
    $user->delete();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->get('/security/two-factor-enrollment')->assertRedirect(route('login'));
});

test('password reset does not remove the required enrollment policy', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::STUDENT_MANAGER);
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect(route('login'));

    expect($user->fresh()->requiresTwoFactorAuthentication())->toBeTrue();
    $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get('/dashboard')->assertRedirect(route('two-factor.enrollment'));
});

test('optional roles retain ordinary login and optional enrollment access', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get('/dashboard')->assertOk();
    $this->get('/profile')->assertInertia(fn ($page) => $page->where('twoFactorRequired', false));
})->with([Role::ESTUDIANTE, Role::SERVICIO_CAFETERIA, Role::CONSEJO_ESTUDIANTIL]);
