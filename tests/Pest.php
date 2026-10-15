<?php

use App\Models\User;
use Laravel\Fortify\Fortify;
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

// These integration fixtures use the public role writer, now backed by the real catalog/schema.
pest()->group('assignment-authority-fixtures')->in(
    'Feature/RoleAssignmentTest.php', 'Feature/RoleAdministrationTest.php',
    'Feature/RequiredTwoFactorAuthenticationTest.php', 'Feature/PrivilegeEscalationRegressionTest.php',
    'Feature/StudentManagementTest.php', 'Feature/StudentServicesPersistenceTest.php',
    'Feature/StudentImportTest.php', 'Feature/StudentPhotoTest.php',
    'Feature/InitialPasswordLifecycleTest.php', 'Feature/AccountDeletionTest.php',
    'Feature/QrValidationAuthorizationTest.php', 'Feature/NfcCardLifecycleTest.php',
    'Feature/NfcCardRegistrationTest.php', 'Feature/NfcCardAuthorizationTest.php',
    'Feature/NfcCardReplacementTest.php', 'Feature/DatabaseSeederSafetyTest.php',
);

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
function withConfirmedTestTwoFactor(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['fixture-recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}


/** Domain regression fixtures use local adapters only; integration tests exercise real E1 OAuth and HTTP. */
function useStudentServicesTestDatabase(): void
{
    test()->assertSame('campus_virtual_testing', \Illuminate\Support\Facades\DB::connection('mongodb')->getDatabaseName());
    config(['integracion_servicios.timezone' => 'UTC']); // Domain fixtures express their times in UTC.
    test()->artisan('migrate', ['--force' => true])->assertExitCode(0);
    test()->withoutMiddleware(\App\Http\Middleware\AutorizarServicios::class);
    test()->mock(\App\Services\StudentServices\ServiceAuthority::class, function ($mock) {
        $mock->shouldReceive('staff')->andReturnFalse();
        $mock->shouldReceive('propias')->andReturnUsing(fn ($q) => $q);
    });
    app()->bind(\App\Services\StudentServices\Benefits\StudentDirectory::class, \Tests\Fakes\StudentServices\LocalStudentDirectory::class);
    app()->bind(\App\Services\StudentServices\ServiceAccess\StudentCredentialResolver::class, \Tests\Fakes\StudentServices\LocalStudentCredentialResolver::class);
    app()->bind(\App\Services\StudentServices\Payments\StudentPaymentGateway::class, \Tests\Fakes\StudentServices\LocalStudentPaymentGateway::class);
}

function studentServiceTestToken(string $token): string
{
    $scopes = match ($token) {
        'token-equipo6', 'token-otro' => ['services:benefits:read', 'services:benefits:write'],
        'token-lectura' => ['services:benefits:read'],
        default => null,
    };
    if ($scopes === null) { return $token; }
    \Firebase\JWT\JWT::$timestamp = now()->timestamp;
    $clientId = match ($token) { 'token-equipo6' => 'equipo6-comunidad', 'token-otro' => 'otro-cliente', default => 'solo-lectura' };
    \App\Models\ServiceClient::firstOrCreate(['client_id' => $clientId], [
        'name' => 'Test '.$token, 'secret_hash' => \Illuminate\Support\Facades\Hash::make('test'), 'scopes' => $scopes, 'active' => true,
    ]);
    return app(\App\Services\OAuthTokenService::class)->issue($clientId, 'test', $scopes)['access_token'];
}
