<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

// These integration classes use the full schema but do not test migration/index creation.
// Their existing migrate assertions remain; only immutable schema is reused within a class.
pest()->group('reusable-mongo-schema')->in(
    'Feature/AccountDeletionTest.php',
    'Feature/InitialPasswordLifecycleTest.php',
    'Feature/NfcCardLifecycleTest.php',
    'Feature/NfcCardRegistrationTest.php',
    'Feature/QrLegacySecretBackfillTest.php',
    'Feature/StudentImportTest.php',
    'Feature/StudentPhotoTest.php',
);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Give an existing fixture a confirmed factor when testing a different feature. */
function withConfirmedTestTwoFactor(\App\Models\User $user): \App\Models\User
{
    $user->forceFill([
        'two_factor_secret' => \Laravel\Fortify\Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => \Laravel\Fortify\Fortify::currentEncrypter()->encrypt(json_encode(['fixture-recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}
