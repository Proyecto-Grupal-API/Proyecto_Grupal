<?php

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

function createConfirmedTwoFactorUser(array $recoveryCodes = ['recovery-code']): array
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$user, $secret];
}

test('a user without confirmed two factor authentication logs in normally', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a user with a pending two factor secret is not sent to the challenge', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['pending-code'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a user with confirmed two factor authentication is sent to the challenge', function () {
    [$user] = createConfirmedTwoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login', absolute: false));

    $this->assertGuest();
});

test('a confirmed two factor user cannot complete the challenge with an invalid code', function () {
    [$user] = createConfirmedTwoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('a confirmed two factor user can complete the challenge with a valid code', function () {
    [$user, $secret] = createConfirmedTwoFactorUser();
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a valid recovery code completes the challenge and is consumed', function () {
    [$user] = createConfirmedTwoFactorUser(['single-use-code']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'single-use-code'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->not->toContain('single-use-code');

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'single-use-code'])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
});

test('the profile only reports confirmed two factor authentication as enabled', function () {
    $pending = User::factory()->create();
    $pending->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($pending)->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->where('twoFactorEnabled', false)
            ->where('twoFactorConfigurationPending', true));

    [$confirmed] = createConfirmedTwoFactorUser();

    $this->actingAs($confirmed)->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->where('twoFactorEnabled', true)
            ->where('twoFactorConfigurationPending', false));
});

test('the two factor enabled accessor only reports confirmed configurations as active', function () {
    $pending = User::factory()->create();
    $pending->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ])->save();

    expect($pending->fresh()->two_factor_enabled)->toBeFalse();

    [$confirmed] = createConfirmedTwoFactorUser();

    expect($confirmed->fresh()->two_factor_enabled)->toBeTrue();
});

test('the roles page only reports confirmed two factor authentication as enabled', function () {
    $pending = User::factory()->create();
    $pending->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($pending)->get('/roles')
        ->assertInertia(fn ($page) => $page->where('twoFactorEnabled', false));

    [$confirmed] = createConfirmedTwoFactorUser();

    $this->actingAs($confirmed)->get('/roles')
        ->assertInertia(fn ($page) => $page->where('twoFactorEnabled', true));
});

test('the two factor challenge route uses the configured limiter', function () {
    $route = app('router')->getRoutes()->getByName('two-factor.login.store');

    expect($route->middleware())->toContain('throttle:two-factor');
});

test('fortify setup moves from disabled through pending to confirmed and can be disabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    foreach (['/profile', '/roles'] as $page) {
        $this->get($page)->assertInertia(fn ($response) => $response
            ->where('twoFactorEnabled', false)
            ->where('twoFactorConfigurationPending', false));
    }

    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    $pending = $user->fresh();
    expect($pending->two_factor_secret)->not->toBeNull()
        ->and($pending->two_factor_confirmed_at)->toBeNull()
        ->and($pending->two_factor_enabled)->toBeFalse();

    foreach (['/profile', '/roles'] as $page) {
        $this->get($page)->assertInertia(fn ($response) => $response
            ->where('twoFactorEnabled', false)
            ->where('twoFactorConfigurationPending', true));
    }

    $secret = Fortify::currentEncrypter()->decrypt($pending->two_factor_secret);
    $qr = $this->getJson('/user/two-factor-qr-code')->assertOk()->json();
    expect($qr['svg'])->toContain('<svg')
        ->and($qr['url'])->toStartWith('otpauth://');
    $this->getJson('/user/two-factor-secret-key')
        ->assertOk()
        ->assertJsonPath('secretKey', $secret);

    $this->postJson('/user/confirmed-two-factor-authentication', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertSuccessful();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->fresh()->two_factor_enabled)->toBeTrue();

    foreach (['/profile', '/roles'] as $page) {
        $this->get($page)->assertInertia(fn ($response) => $response
            ->where('twoFactorEnabled', true)
            ->where('twoFactorConfigurationPending', false));
    }

    $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
    $disabled = $user->fresh();
    expect($disabled->two_factor_secret)->toBeNull()
        ->and($disabled->two_factor_recovery_codes)->toBeNull()
        ->and($disabled->two_factor_confirmed_at)->toBeNull()
        ->and($disabled->two_factor_enabled)->toBeFalse();
});

test('confirmed users can view and regenerate real recovery codes', function () {
    [$user] = createConfirmedTwoFactorUser();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $before = $this->getJson('/user/two-factor-recovery-codes')->assertOk()->json();
    expect($before)->not->toBeEmpty();

    $this->postJson('/user/two-factor-recovery-codes')->assertSuccessful();
    $after = $this->getJson('/user/two-factor-recovery-codes')->assertOk()->json();

    expect($after)->not->toBeEmpty()->not->toEqual($before)
        ->and($user->fresh()->recoveryCodes())->toEqual($after);
});

test('fortify setup requires a recent password confirmation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->postJson('/user/two-factor-authentication')->assertStatus(423);
    expect($user->fresh()->two_factor_secret)->toBeNull();

    $this->postJson('/confirm-password', ['password' => 'password'])->assertRedirect();
    $this->postJson('/user/two-factor-authentication')->assertSuccessful();
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});
