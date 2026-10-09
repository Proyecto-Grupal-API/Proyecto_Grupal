<?php
// Test-only approved confirmations for existing cashier tests. New end-to-end tests exercise the student's actual session.
function cashConfirmationCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Solo base financiera de pruebas.');
    \App\Domains\Financial\Models\CashOperationConfirmation::where('request_key', 'like', 'test-fc-%')->delete();
}
function cashConfirmedPost($test, string $url, array $data = [], array $headers = [])
{
    if (preg_match('~/shifts/([a-f0-9-]{36})/(topups|withdrawals)$~i', $url, $match)
        && isset($data['wallet_id'], $data['amount_cents'], $data['reason']) && !isset($data['confirmation_id'])) {
        $shift = \App\Domains\Financial\Models\CashShift::where('public_id', $match[1])->first();
        $wallet = \App\Domains\Financial\Models\Wallet::where('public_id', $data['wallet_id'])->first();
        if ($shift && $wallet && is_int($data['amount_cents']) && $data['amount_cents'] > 0) {
            $key = $headers['Idempotency-Key'] ?? null;
            $proof = $key ? \App\Domains\Financial\Models\CashOperationConfirmation::where('settlement_key', $key)->first() : null;
            if (!$proof) $proof = \App\Domains\Financial\Models\CashOperationConfirmation::create([
                'public_id' => (string) str()->uuid(), 'cash_shift_id' => $shift->id, 'wallet_id' => $wallet->public_id,
                'operator_id' => $shift->agent_id, 'student_id' => (string) $wallet->owner_id,
                'operation' => $match[2] === 'topups' ? 'TOPUP' : 'WITHDRAWAL', 'amount_cents' => $data['amount_cents'],
                'currency' => $wallet->currency, 'reason' => trim($data['reason']), 'status' => 'CONFIRMED',
                'request_key' => 'test-fc-confirmation-fixture-'.str()->uuid(), 'request_hash' => str_repeat('a', 64),
                'expires_at' => now()->addMinutes(5), 'confirmed_by' => 'user:'.$wallet->owner_id, 'confirmed_at' => now()]);
            $data['confirmation_id'] = $proof->public_id;
        }
    }
    return $test->postJson($url, $data, $headers);
}
