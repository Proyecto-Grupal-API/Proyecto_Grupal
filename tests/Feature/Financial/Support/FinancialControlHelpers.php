<?php

/*
| Helpers compartidos por las pruebas de 2.10.
|
| Las pruebas financieras usan la base real de pruebas de SQL Server y
| limpian sus propios datos (no se usa RefreshDatabase). Todos los datos
| de 2.10 se identifican con el prefijo "test-fc-".
|
| Los montos usados aquí son datos de prueba, NO políticas financieras.
*/

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\FinancialLimitChange;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\ReconciliationDifference;
use App\Domains\Financial\Models\ReconciliationDifferenceObservation;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\TransactionAlertStatusChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const FC_PREFIX = 'test-fc-';
const FC_ACTOR = 'test-fc-actor';
const FC_API_ACTOR = 'service:test-fc-api-default';

function fcAuthenticateControls($test): void
{
    \App\Models\ServiceClient::where('client_id', 'test-fc-api-default')->delete();
    \App\Models\ServiceClient::create([
        'name' => 'Financial control API fixture',
        'client_id' => 'test-fc-api-default',
        'secret_hash' => \Illuminate\Support\Facades\Hash::make('test-secret'),
        'scopes' => ['financial:read', 'financial:control', 'financial:write'],
        'active' => true,
    ]);
    $token = $test->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => 'test-fc-api-default',
        'client_secret' => 'test-secret',
        'scope' => 'financial:read financial:control financial:write',
    ])->assertOk()->json('access_token');
    $test->withHeader('Authorization', "Bearer {$token}");
}


if (!function_exists('fcWallet')) {
    function fcWallet(
        string $suffix,
        int $initialBalanceCents = 0,
        WalletType $type = WalletType::USUARIO
    ): Wallet {
        $wallet = app(WalletService::class)->create(
            'USER',
            FC_PREFIX . $suffix,
            $type
        );

        if ($initialBalanceCents > 0) {
            app(LedgerService::class)->credit(
                $wallet,
                $initialBalanceCents,
                MovementType::RECARGA,
                FC_PREFIX . 'seed-' . Str::uuid(),
                'TEST_SEED',
                $wallet->public_id
            );
        }

        return $wallet->fresh();
    }

    function fcLimit(Wallet $wallet, array $overrides = []): FinancialLimit
    {
        return app(FinancialLimitService::class)->create(
            array_merge([
                'name' => 'Límite de prueba',
                'subject_type' => 'OWNER',
                'subject_id' => $wallet->owner_id,
                'operation' => 'RECARGA',
                'period' => 'OPERACION',
                'metric' => 'MONTO',
                'max_amount_cents' => 10000,
                'action' => 'BLOQUEAR',
            ], $overrides),
            FC_ACTOR
        );
    }

    function fcKey(string $name): string
    {
        return FC_PREFIX . $name . '-' . Str::uuid();
    }

    function fcSameId(?string $a, ?string $b): bool
    {
        return strtolower((string) $a) === strtolower((string) $b);
    }

    function fcAlertsForWallet(Wallet $wallet)
    {
        return TransactionAlert::where('wallet_id', $wallet->public_id)
            ->orderBy('id')
            ->get();
    }

    function fcCleanup(): void
    {
        $walletIds = Wallet::where('owner_id', 'like', FC_PREFIX . '%')
            ->pluck('public_id')
            ->all();

        // '%test-fc-%' también cubre actores de la interfaz ('USER:test-fc-...').
        $limitIds = FinancialLimit::where(function ($q) {
                $q->where('created_by', 'like', '%' . FC_PREFIX . '%')
                    ->orWhere('created_by', 'like', 'service:svc_test_fc%');
            })
            ->pluck('public_id')
            ->all();

        $alertIds = TransactionAlert::query()
            ->where(function ($q) use ($walletIds, $limitIds) {
                $q->whereIn('wallet_id', $walletIds ?: ['00000000-0000-0000-0000-000000000000'])
                    ->orWhereIn('limit_id', $limitIds ?: ['00000000-0000-0000-0000-000000000000']);
            })
            ->pluck('public_id')
            ->all();

        if ($alertIds !== []) {
            TransactionAlertStatusChange::whereIn('alert_id', $alertIds)->delete();
            TransactionAlert::whereIn('public_id', $alertIds)->delete();
        }

        if ($limitIds !== []) {
            FinancialLimitChange::whereIn('limit_id', $limitIds)->delete();
        }

        FinancialLimit::whereIn('public_id', $limitIds ?: ['00000000-0000-0000-0000-000000000000'])->delete();

        $reconciliationIds = Reconciliation::where(function ($q) {
                $q->where('executed_by', 'like', '%' . FC_PREFIX . '%')
                    ->orWhere('executed_by', 'like', 'service:svc_test_fc%');
            })
            ->pluck('public_id')
            ->all();

        if ($reconciliationIds !== []) {
            // Solo se borran diferencias creadas por corridas de prueba; las
            // observaciones de prueba sobre diferencias ajenas solo se desligan.
            $differenceIds = ReconciliationDifference::whereIn('reconciliation_id', $reconciliationIds)
                ->pluck('public_id')
                ->all();

            ReconciliationDifferenceObservation::query()
                ->whereIn('reconciliation_id', $reconciliationIds)
                ->orWhereIn('difference_id', $differenceIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();
            ReconciliationDifference::whereIn('public_id', $differenceIds ?: ['00000000-0000-0000-0000-000000000000'])->delete();
            Reconciliation::whereIn('public_id', $reconciliationIds)->delete();
        }

        // Never delete locks owned by unrelated financial workers.
        DB::connection('sqlsrv')->table('financial_job_locks')
            ->where('owner_token', 'like', FC_PREFIX . '%')->delete();
        \App\Models\ServiceClient::where('client_id', 'test-fc-api-default')->delete();

        \App\Domains\Financial\Models\WalletHold::whereIn('wallet_id', $walletIds)->delete();

        $transactionIds = collect();

        if ($walletIds !== []) {
            $transactionIds = LedgerEntry::whereIn('wallet_id', $walletIds)
                ->pluck('transaction_id');
        }

        $transactionIds = $transactionIds
            ->merge(
                FinancialTransaction::where('idempotency_key', 'like', FC_PREFIX . '%')
                    ->pluck('public_id')
            )
            ->unique()
            ->values()
            ->all();

        if ($transactionIds !== []) {
            LedgerEntry::whereIn('transaction_id', $transactionIds)->delete();
        }

        if ($walletIds !== []) {
            LedgerEntry::whereIn('wallet_id', $walletIds)->delete();

            $operationIds = TopUp::whereIn('wallet_id', $walletIds)->pluck('public_id')
                ->merge(Withdrawal::whereIn('wallet_id', $walletIds)->pluck('public_id'))
                ->flatMap(fn ($id) => [strtolower((string) $id), strtoupper((string) $id)])
                ->all();

            if ($operationIds !== []) {
                $transactionIds = array_merge(
                    $transactionIds,
                    FinancialTransaction::whereIn('reference_type', ['TOPUP', 'WITHDRAWAL'])
                        ->whereIn('reference_id', $operationIds)
                        ->pluck('public_id')
                        ->all()
                );
            }
        }

        if ($transactionIds !== []) {
            LedgerEntry::whereIn('transaction_id', $transactionIds)->delete();
            FinancialTransaction::whereIn('public_id', $transactionIds)->delete();
        }

        if ($walletIds !== []) {
            TopUp::whereIn('wallet_id', $walletIds)->delete();
            Withdrawal::whereIn('wallet_id', $walletIds)->delete();
            Wallet::whereIn('public_id', $walletIds)->delete();
        }
    }
}
