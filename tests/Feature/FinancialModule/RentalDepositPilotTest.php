<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletHoldPolicyService;
use App\Models\ServiceClient;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('the rental pilot binds the service and rental to one settlement and preserves its receipt', function () {
    expect(config('database.connections.sqlsrv.database'))->toBe('campus_digital_financial_testing');
    $sql = DB::connection('sqlsrv');
    $sql->beginTransaction();
    try {
        $wallet = fn ($type, $ownerType, $owner) => Wallet::create([
            'public_id' => (string) Str::uuid(), 'type' => $type, 'owner_type' => $ownerType,
            'owner_id' => $owner, 'currency' => 'MXN', 'status' => 'ACTIVA',
            'available_balance_cents' => 0, 'held_balance_cents' => 0,
        ]);
        $studentId = '507f1f77bcf86cd799439010';
        $rental = '507f1f77bcf86cd799439020';
        $student = $wallet('WALLET_USUARIO', 'USER', $studentId);
        $recipient = $wallet('WALLET_ORGANIZACION', 'ORGANIZATION', 'services');
        app(LedgerService::class)->credit($student, 50000, MovementType::RECARGA, (string) Str::uuid());
        app(WalletHoldPolicyService::class)->create('RENTAL_DEPOSIT', 3600, true, 'synthetic', 'Prueba de API.');

        config([
            'oauth.signing_key' => str_repeat('rental-pilot-testing-', 3),
            'financial_rental_deposit_pilot.client_id' => 'rental-pilot-e5',
            'financial_library_fines.recipient_organization_id' => 'services',
            'financial_library_fines.recipient_wallet_id' => $recipient->public_id,
        ]);
        $scopes = ['financial:rental-deposit:hold', 'financial:rental-deposit:settle'];
        $bearer = function ($clientId) use ($scopes) {
            ServiceClient::create(['client_id' => $clientId, 'name' => 'Synthetic pilot', 'active' => true,
                'secret_hash' => Hash::make('fixture-secret'), 'scopes' => $scopes]);

            return 'Bearer '.app(OAuthTokenService::class)->issue($clientId, 'fixture-secret', $scopes)['access_token'];
        };
        $headers = ['Authorization' => $bearer('rental-pilot-e5'), 'Idempotency-Key' => 'rental:'.$rental.':deposit-hold'];
        $wrongClient = $bearer('rental-pilot-other');
        $root = '/api/v1/financial/student-services/rentals/';
        $path = $root.$rental.'/deposit';
        $body = ['student_id' => $studentId, 'amount_cents' => 50000, 'expires_at' => now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->postJson($path, $body, $headers)->assertNotFound();
        config(['financial_rental_deposit_pilot.enabled' => true]);
        $hold = $this->postJson($path, $body, $headers)->assertCreated()->json('data.hold_id');
        $this->postJson($path, $body, $headers)->assertOk()->assertJsonPath('data.hold_id', $hold);
        $this->postJson($path, [...$body, 'amount_cents' => 10000], $headers)->assertConflict();

        $headers['Idempotency-Key'] = 'rental:'.$rental.':deposit-settle';
        $settlePath = $path.'/'.$hold.'/settle';
        $settlement = ['condition' => 'damaged', 'charged_cents' => 15000];
        $this->postJson($settlePath, $settlement, [...$headers, 'Authorization' => $wrongClient])->assertForbidden();
        $other = '507f1f77bcf86cd799439021';
        $this->postJson($root.$other.'/deposit/'.$hold.'/settle', $settlement, [
            ...$headers, 'Idempotency-Key' => 'rental:'.$other.':deposit-settle',
        ])->assertNotFound();
        $this->postJson($settlePath, [...$settlement, 'charged_cents' => 50001], $headers)->assertUnprocessable();
        $receipt = $this->postJson($settlePath, $settlement, $headers)->assertOk()
            ->assertJsonPath('data.charged_cents', 15000)->assertJsonPath('data.released_cents', 35000)
            ->json('data.transaction_id');
        $this->postJson($settlePath, $settlement, $headers)->assertOk()->assertJsonPath('data.transaction_id', $receipt);
        $this->postJson($settlePath, [...$settlement, 'charged_cents' => 10000], $headers)->assertConflict();
        expect(FinancialTransaction::where('idempotency_key', $headers['Idempotency-Key'])->count())->toBe(1)
            ->and($student->fresh()->available_balance_cents)->toBe(35000)
            ->and($student->fresh()->held_balance_cents)->toBe(0)
            ->and($recipient->fresh()->available_balance_cents)->toBe(15000);
    } finally {
        while ($sql->transactionLevel() > 0) {
            $sql->rollBack();
        }
    }
});
