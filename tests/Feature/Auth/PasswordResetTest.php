<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a pending account without a password can request a reset link', function () {
    Notification::fake();
    $user = User::factory()->create();
    $user->forceFill(['password' => null, 'account_activation_pending' => true])->save();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
    expect($user->fresh()->account_activation_pending)->toBeTrue()
        ->and($user->fresh()->password)->toBeNull();
});

test('a successful reset activates a pending account without verifying its email', function () {
    $user = User::factory()->unverified()->create();
    $user->forceFill(['password' => null, 'account_activation_pending' => true])->save();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $activated = $user->fresh();
    expect(Hash::check('new-password-123', $activated->password))->toBeTrue()
        ->and($activated->account_activation_pending)->toBeFalse()
        ->and($activated->email_verified_at)->toBeNull();

    $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($activated);
});

test('invalid reset tokens do not activate a pending account', function () {
    $user = User::factory()->create(['account_activation_pending' => true]);
    $originalPassword = $user->password;

    $this->post('/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBe($originalPassword)
        ->and($user->fresh()->account_activation_pending)->toBeTrue();
});

test('invalid new passwords do not activate a pending account', function () {
    $user = User::factory()->create(['account_activation_pending' => true]);
    $originalPassword = $user->password;
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBe($originalPassword)
        ->and($user->fresh()->account_activation_pending)->toBeTrue();
});

test('resetting a normal account changes its password without making it pending', function () {
    $user = User::factory()->create(['account_activation_pending' => false]);
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->account_activation_pending)->toBeFalse();
});

test('activated accounts with confirmed two factor authentication still require the challenge', function () {
    $user = User::factory()->create(['account_activation_pending' => true]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect(route('login'));

    $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])
        ->assertRedirect(route('two-factor.login', absolute: false));
    $this->assertGuest();
});
