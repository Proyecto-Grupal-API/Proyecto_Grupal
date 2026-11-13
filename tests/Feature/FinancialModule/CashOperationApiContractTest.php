<?php

use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Http\Middleware\ValidateServiceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Contrato HTTP de /api/v1/financial/topups y /withdrawals (2.3/2.4/2.10). Caracteriza las respuestas exactas
// para que la extracción del contrato común (RespondsWithCashOperations, rúbrica 5.6) no cambie el comportamiento.
// La autenticación OAuth de servicio tiene sus propias pruebas; aquí se omite sólo ese middleware.
beforeEach(function () {
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    $this->withoutMiddleware(ValidateServiceToken::class);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});
function coaWallet(int $balance): Wallet
{
    return Wallet::create(['public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => 'fixture-coa-'.Str::uuid(),
        'type' => 'WALLET_USUARIO', 'currency' => 'MXN', 'status' => 'ACTIVA', 'available_balance_cents' => $balance, 'held_balance_cents' => 0]);
}
const COA_CREATED = ['id', 'wallet_id', 'folio', 'amount_cents', 'currency', 'method', 'status', 'agent_id', 'external_reference', 'created_at'];

test('top-up store and show keep their exact JSON contract', function () {
    $w = coaWallet(0);
    $created = $this->withHeader('X-Request-Id', 'req-coa-1')->postJson('/api/v1/financial/topups',
        ['wallet_id' => $w->public_id, 'amount_cents' => 700, 'method' => 'EFECTIVO', 'agent_id' => 'agent-9', 'external_reference' => 'ref-1'])
        ->assertCreated()->assertJsonPath('meta', ['request_id' => 'req-coa-1', 'api_version' => 'v1'])->json();
    expect(array_keys($created))->toBe(['data', 'meta'])->and(array_keys($created['data']))->toBe(COA_CREATED);
    expect(strtolower($created['data']['wallet_id']))->toBe(strtolower($w->public_id));
    expect($created['data'])->toMatchArray(['amount_cents' => 700, 'currency' => 'MXN', 'method' => 'EFECTIVO',
        'status' => 'PENDIENTE', 'agent_id' => 'agent-9', 'external_reference' => 'ref-1']);
    $shown = $this->getJson('/api/v1/financial/topups/'.$created['data']['id'])->assertOk()->json();
    expect(array_keys($shown['data']))->toBe([...COA_CREATED, 'updated_at'])->and($shown['meta']['api_version'])->toBe('v1')
        ->and($shown['meta']['request_id'])->toBeString()->not->toBe('');
});

test('top-up complete keeps its 422 and 409 responses', function () {
    $id = $this->postJson('/api/v1/financial/topups', ['wallet_id' => coaWallet(0)->public_id, 'amount_cents' => 300, 'method' => 'EFECTIVO'])->json('data.id');
    $this->postJson("/api/v1/financial/topups/{$id}/complete")->assertStatus(422)->assertExactJson(['message' => 'The Idempotency-Key header is required.']);
    $conflict = $this->withHeader('Idempotency-Key', 'coa-'.Str::uuid())->postJson("/api/v1/financial/topups/{$id}/complete")->assertStatus(409)->json();
    expect(array_keys($conflict))->toBe(['message', 'meta'])->and($conflict['message'])->toContain('Caja y turnos');
});

test('validation, unknown resources, limits and conflicts keep their status codes and bodies', function () {
    $w = coaWallet(1000);
    $this->postJson('/api/v1/financial/topups', ['wallet_id' => $w->public_id, 'amount_cents' => 0, 'method' => 'CHEQUE'])
        ->assertStatus(422)->assertJsonValidationErrors(['amount_cents', 'method']);
    $this->postJson('/api/v1/financial/withdrawals', ['wallet_id' => (string) Str::uuid(), 'amount_cents' => 10, 'method' => 'TRANSFERENCIA'])->assertNotFound();
    $this->getJson('/api/v1/financial/withdrawals/'.Str::uuid())->assertNotFound();
    app(FinancialLimitService::class)->create(['name' => 'Fixture RETIRO BLOQUEAR', 'subject_type' => 'OWNER', 'subject_id' => $w->owner_id,
        'operation' => 'RETIRO', 'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => 500, 'action' => 'BLOQUEAR',
        'currency' => 'MXN', 'active' => true, 'reason' => 'Fixture configured limit'], 'fixture-admin');
    $limited = $this->postJson('/api/v1/financial/withdrawals', ['wallet_id' => $w->public_id, 'amount_cents' => 501, 'method' => 'TRANSFERENCIA'])
        ->assertStatus(409)->json();
    expect(array_keys($limited))->toBe(['message', 'code', 'alert_id', 'correlation_id', 'evaluation', 'meta'])
        ->and($limited['code'])->toBe('FINANCIAL_LIMIT_EXCEEDED');
    $withdrawal = $this->postJson('/api/v1/financial/withdrawals', ['wallet_id' => $w->public_id, 'amount_cents' => 400, 'method' => 'TRANSFERENCIA'])
        ->assertCreated()->json();
    expect(array_keys($withdrawal['data']))->toBe(COA_CREATED)->and($withdrawal['data']['status'])->toBe('PENDIENTE');
    expect(array_keys($this->getJson('/api/v1/financial/withdrawals/'.$withdrawal['data']['id'])->assertOk()->json('data')))->toBe([...COA_CREATED, 'updated_at']);
});
