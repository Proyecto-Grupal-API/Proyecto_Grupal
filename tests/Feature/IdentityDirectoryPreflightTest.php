<?php

use App\Domains\Financial\Exceptions\TransferRecipientUnavailableException;
use App\Exceptions\DirectoryFailure;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Directory\EnrollmentIntegrityPreflight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Auditoría E2 (19-nov-2026), FIN-03: la resolución por matrícula exige que E1 acredite la integridad de las matrículas
// (docs/integration/E1-DIRECTORY-D1-D4-LOCAL.md, D1). El preflight de sólo lectura es esa acreditación: no repara datos,
// no cambia configuración y sólo informa conteos. FIN-07: cada causa del directorio tiene su propio código y mensaje.

function dpProfile(string $enrollment, ?string $userId = null): string
{
    $userId ??= (string) User::factory()->create()->getKey();
    DB::connection('mongodb')->getCollection('student_profiles')->insertOne(['user_id' => $userId, 'enrollment_number' => $enrollment]);

    return $userId;
}

test('FIN-03: the integrity preflight passes on clean, canonical and unique enrollments', function () {
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    dpProfile('2026-0001');
    dpProfile('A1B2C3');

    $report = app(EnrollmentIntegrityPreflight::class)->run();
    expect($report['passed'])->toBeTrue()->and($report['profiles'])->toBe(2)
        ->and(collect($report['checks'])->every(fn ($c) => $c['passed']))->toBeTrue();
    $this->artisan('identity:directory-preflight')->expectsOutputToContain('APROBADO')->assertExitCode(0);
});

test('FIN-03: the preflight fails on historical variants, orphans and missing unique indexes, without revealing values', function () {
    // Sin los índices únicos de la migración.
    dpProfile('2026-0002');
    $report = app(EnrollmentIntegrityPreflight::class)->run();
    expect($report['passed'])->toBeFalse()
        ->and($report['checks']['unique_index_enrollment_number']['passed'])->toBeFalse()
        ->and($report['checks']['unique_index_user_id']['passed'])->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    dpProfile('ab-77');                            // variante histórica sin normalizar …
    dpProfile('AB-77');                            // … que colisiona con la canónica al normalizar
    dpProfile('ZZ-9', '64a0000000000000000000ff'); // perfil huérfano: el usuario no existe
    $report = app(EnrollmentIntegrityPreflight::class)->run();
    expect($report['passed'])->toBeFalse()
        ->and($report['checks']['canonical_enrollment_numbers']['count'])->toBe(1)
        ->and($report['checks']['no_duplicate_enrollment_after_normalization']['count'])->toBe(1)
        ->and($report['checks']['no_orphan_profiles']['count'])->toBe(1)
        ->and($report['checks']['unique_index_enrollment_number']['passed'])->toBeTrue();

    $this->artisan('identity:directory-preflight')->expectsOutputToContain('NO APROBADO')
        ->doesntExpectOutputToContain('AB-77')->doesntExpectOutputToContain('64a0000000000000000000ff')->assertExitCode(1);
    // Sólo lectura: nada se corrigió ni se activó.
    expect(StudentProfile::where('enrollment_number', 'ab-77')->exists())->toBeTrue()
        ->and(config('identity_directory.enrollment_integrity_verified'))->not->toBeTrue();
});

test('FIN-07: each directory failure keeps its own code, a useful message and a correlation id', function (int $status, string $code, string $text) {
    $exception = TransferRecipientUnavailableException::fromDirectory(new DirectoryFailure($status, $code), 'corr-12345678');
    expect($exception->reasonCode)->toBe($code)->and($exception->correlationId)->toBe('corr-12345678')
        ->and($exception->getMessage())->toContain($text)
        ->and($exception->getMessage())->not->toContain($code);
})->with([
    'integridad sin acreditar' => [503, 'directory_integrity_not_attested', 'acreditación de integridad'],
    'servicio no disponible' => [503, 'directory_unavailable', 'no está disponible'],
    'auditoría no disponible' => [503, 'directory_audit_unavailable', 'no está disponible'],
    'acceso denegado' => [403, 'directory_access_denied', 'autorización'],
    'cuota excedida' => [429, 'directory_rate_limited', 'límite de consultas'],
    'cuota no verificada' => [503, 'directory_quota_unavailable', 'límite de consultas'],
]);

test('FIN-07: transfers by enrollment explain the missing attestation and point to ID or QR, and an unknown enrollment is a clear rejection', function () {
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $sender = (string) User::factory()->create()->getKey();
    $enrollment = 'TEST-'.Str::upper(Str::random(8));
    $recipient = dpProfile($enrollment);
    $recipients = app(\App\Domains\Financial\Contracts\TransferRecipientProvider::class);

    config(['identity_directory.enrollment_integrity_verified' => false]);
    try {
        $recipients->resolve('ENROLLMENT', $enrollment, $sender, null);
        $this->fail('Se esperaba el bloqueo por integridad no acreditada.');
    } catch (TransferRecipientUnavailableException $e) {
        expect($e->reasonCode)->toBe('directory_integrity_not_attested')->and($e->getMessage())->toContain('QR');
    }

    config(['identity_directory.enrollment_integrity_verified' => true]);
    expect($recipients->resolve('ENROLLMENT', $enrollment, $sender, null)['user_id'])->toBe($recipient)
        ->and($recipients->resolve('USER_ID', $recipient, $sender, null)['user_id'])->toBe($recipient)
        ->and(fn () => $recipients->resolve('ENROLLMENT', 'NOEXISTE-1', $sender, null))
        ->toThrow(InvalidArgumentException::class, 'No hay un destinatario disponible con esa matrícula');
});
