<?php

use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Models\QrValidation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserSession;
use App\Services\IdentityService;
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

// Requisito 1.6-d (QR dinámico en una operación sensible). Decir a quién se le envía dinero es la operación sensible que Finanzas
// protege con el QR dinámico del destinatario: Identidad lo valida con el contexto de la transferencia, lo consume y no lo vuelve
// a aceptar. El recorrido de E2 lo hace por la pantalla de transferencias.
test('transfer recipient by dynamic QR is resolved once by Identity under the transfer context', function () {
    $this->artisan('migrate')->assertSuccessful();
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $senderId = (string) $sender->getKey();
    $recipientId = (string) $recipient->getKey();
    $identity = app(IdentityService::class);
    $recipients = app(TransferRecipientProvider::class);
    $validations = fn () => QrValidation::where('user_id', $recipientId)->where('context', 'FINANCIAL_TRANSFER_RECIPIENT');

    // Con el código corto que el destinatario dicta.
    $short = $identity->issueDynamicQrToken($recipient);
    expect($recipients->resolve('QR', $short->shortCode, $senderId, '203.0.113.9')['user_id'])->toBe($recipientId)
        ->and($validations()->where('result', 'valid')->count())->toBe(1)
        ->and($validations()->where('result', 'valid')->first()->validated_by_user_id)->toBe($senderId)
        ->and($short->token->fresh()->consumed_at)->not->toBeNull();

    // De un solo uso: el mismo código ya no identifica a nadie, y no se convierte en otro tipo de error.
    expect(fn () => $recipients->resolve('QR', $short->shortCode, $senderId, null))
        ->toThrow(InvalidArgumentException::class, 'El QR no es válido o ya no está vigente.')
        ->and($validations()->where('result', 'valid')->count())->toBe(1);

    // Con el QR completo que el destinatario muestra.
    $full = $identity->issueDynamicQrToken($recipient);
    expect($recipients->resolve('QR', $identity->buildQrPayload($full), $senderId, null)['user_id'])->toBe($recipientId)
        ->and($full->token->fresh()->consumed_at)->not->toBeNull()
        ->and($validations()->where('result', 'valid')->count())->toBe(2);

    // Un código inventado, uno vacío y uno desmedido no identifican a nadie.
    expect(fn () => $recipients->resolve('QR', '000000', $senderId, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recipients->resolve('QR', '', $senderId, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recipients->resolve('QR', str_repeat('9', 501), $senderId, null))->toThrow(InvalidArgumentException::class);
});
