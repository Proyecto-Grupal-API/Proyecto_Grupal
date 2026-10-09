<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\WalletHold;
use App\Domains\Financial\Models\WalletHoldPolicy;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\PurchaseRefundService;
use App\Domains\Financial\Services\WalletHoldPolicyService;
use App\Domains\Financial\Services\WalletHoldService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use InvalidArgumentException;

class FinancialAdjustmentWebController extends Controller
{
    use FinancialControlResponses;

    public function index(Request $request, WalletService $wallets)
    {
        $request->validate(['wallet_id' => ['nullable', 'uuid']]);
        $wallet = $request->filled('wallet_id')
            ? Wallet::where('public_id', $request->input('wallet_id'))->firstOrFail()
            : $wallets->findByOwner('USER', (string) $request->user()->getKey(), WalletType::USUARIO);
        if ($wallet && !$this->owns($request, $wallet)) {
            $this->authorizeAction($request, 'adjustments.view', $wallet->public_id);
        }
        $permissions = [];
        foreach (['refund.review', 'refund.execute', 'refund.recover', 'hold.release', 'hold.capture', 'transaction.reverse', 'adjustments.view'] as $action) {
            $permissions[$action] = $wallet && $this->allowed($request, $action, $wallet->public_id);
        }
        foreach (['policy.view', 'policy.manage'] as $action) {
            $permissions[$action] = $this->allowed($request, $action);
        }
        $holds = $refunds = $purchaseRefunds = $purchases = $transactions = collect();
        if ($wallet) {
            $holds = WalletHold::where('wallet_id', $wallet->public_id)->latest('id')->limit(50)->get();
            $refunds = FinancialRefundRequest::where('wallet_id', $wallet->public_id)->with('originalTransaction')->latest('id')->limit(50)->get();
            $purchaseRefunds = PurchaseRefundRequest::where('wallet_id', $wallet->public_id)->latest('id')->limit(50)->get();
            $purchases = PurchasePayment::where('wallet_id', $wallet->public_id)->where('status', 'COMPLETADO')
                ->with('bonuses')->latest('id')->limit(50)->get();
            $transactions = FinancialTransaction::whereExists(function ($q) use ($wallet) {
                $q->selectRaw('1')->from('ledger_entries')->where('wallet_id', $wallet->public_id)
                    ->whereColumn('ledger_entries.transaction_id', 'financial_transactions.public_id');
            })->latest('id')->limit(50)->get();
        }
        $entries = $wallet ? LedgerEntry::where('wallet_id', $wallet->public_id)
            ->whereIn('transaction_id', $transactions->pluck('public_id'))->get()
            ->keyBy(fn ($entry) => strtolower($entry->transaction_id)) : collect();
        return Inertia::render('Financial/Adjustments', [
            'wallet' => $wallet ? $this->fields($wallet, ['currency', 'available_balance_cents', 'held_balance_cents']) : null,
            'canRequest' => $wallet && $this->owns($request, $wallet), 'permissions' => $permissions,
            'holds' => $holds->map(fn ($row) => $this->fields($row, ['amount_cents', 'status', 'expires_at', 'operation_type', 'reason', 'policy_version', 'max_duration_seconds'])),
            'refunds' => $refunds->map(fn ($row) => $this->fields($row, ['amount_cents', 'status', 'reason', 'review_reason', 'original_transaction_id']) + ['is_withdrawal' => $row->originalTransaction?->reference_type === 'WITHDRAWAL']),
            'purchaseRefunds' => $purchaseRefunds->map(fn ($row) => $this->fields($row, ['total_amount_cents', 'wallet_amount_cents', 'bonus_amount_cents', 'status', 'reason', 'review_reason', 'purchase_payment_id'])),
            'purchases' => $purchases->map(fn ($row) => $this->fields($row, ['total_amount_cents', 'wallet_amount_cents', 'bonus_amount_cents']) + [
                'bonuses' => $row->bonuses->isEmpty() && $row->bonus_amount_cents > 0 && $row->bonus_id
                    ? [['bonus_id' => strtolower($row->bonus_id), 'amount_cents' => $row->bonus_amount_cents]]
                    : $row->bonuses->map(fn ($line) => ['bonus_id' => strtolower($line->bonus_id), 'amount_cents' => $line->amount_cents]),
            ]),
            'transactions' => $transactions->map(function ($row) use ($entries) {
                $entry = $entries->get(strtolower($row->public_id));
                return $this->fields($row, ['status', 'reference_type']) + ['amount_cents' => $entry?->amount_cents,
                    'movement_type' => $entry?->movement_type?->value];
            }),
            'policies' => $permissions['policy.view'] ? WalletHoldPolicy::orderBy('id')->limit(50)->get()
                ->map(fn ($row) => $this->fields($row, ['operation_type', 'max_duration_seconds', 'active', 'version'])) : [],
        ]);
    }

    public function requestRefund(Request $request, FinancialAdjustmentService $service)
    {
        $data = $request->validate(['original_transaction_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        $key = $this->key($request);
        $original = FinancialTransaction::where('public_id', $data['original_transaction_id'])->whereExists(function ($q) use ($request) {
            $q->selectRaw('1')->from('ledger_entries')->join('wallets', 'wallets.public_id', '=', 'ledger_entries.wallet_id')
                ->whereColumn('ledger_entries.transaction_id', 'financial_transactions.public_id')
                ->where('wallets.owner_type', 'USER')->where('wallets.owner_id', (string) $request->user()->getKey())
                ->where('ledger_entries.amount_cents', '<', 0);
        })->firstOrFail();
        return $this->run($request, fn () => $service->refund($original, (int) $data['amount_cents'], $key, $this->actor($request), $data['reason']));
    }

    public function requestPurchaseRefund(Request $request, PurchaseRefundService $service)
    {
        $data = $request->validate(['purchase_payment_id' => ['required', 'uuid'], 'wallet_amount_cents' => ['required', 'integer', 'min:0'],
            'bonus_refunds' => ['sometimes', 'array'], 'bonus_refunds.*.bonus_id' => ['required', 'uuid'],
            'bonus_refunds.*.amount_cents' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        $key = $this->key($request);
        $payment = PurchasePayment::where('public_id', $data['purchase_payment_id'])->firstOrFail();
        abort_unless($this->owns($request, Wallet::where('public_id', $payment->wallet_id)->firstOrFail()), 404);
        $lines = array_map(fn ($line) => ['bonus_id' => $line['bonus_id'], 'amount_cents' => (int) $line['amount_cents']], $data['bonus_refunds'] ?? []);
        return $this->run($request, fn () => $service->request($payment, (int) $data['wallet_amount_cents'], $lines, $key, $this->actor($request), $data['reason']));
    }

    public function refundAction(Request $request, string $refundId, string $action, FinancialAdjustmentService $service)
    {
        $refund = FinancialRefundRequest::where('public_id', $refundId)->firstOrFail();
        $this->authorizeAction($request, match ($action) {'approve', 'reject' => 'refund.review', 'complete' => 'refund.execute', 'recover' => 'refund.recover'}, $refund->wallet_id);
        $reason = $this->reason($request);
        $actor = $this->actor($request);
        if ($action === 'recover') {
            $receipt = $request->validate(['recovery_reference' => ['required', 'string', 'max:255', 'regex:/\S/u']])['recovery_reference'];
            return $this->run($request, fn () => $service->confirmWithdrawalRecovery($refund, $receipt, $actor, $reason));
        }
        $key = $action === 'complete' ? $this->key($request) : null;
        return $this->run($request, fn () => match ($action) {
            'approve' => $service->approveRefund($refund, $actor, $reason), 'reject' => $service->rejectRefund($refund, $actor, $reason),
            'complete' => $service->completeRefund($refund, $key, $actor),
        });
    }

    public function purchaseRefundAction(Request $request, string $refundId, string $action, PurchaseRefundService $service)
    {
        $refund = PurchaseRefundRequest::where('public_id', $refundId)->firstOrFail();
        $this->authorizeAction($request, $action === 'complete' ? 'refund.execute' : 'refund.review', $refund->wallet_id);
        $reason = $this->reason($request); $actor = $this->actor($request);
        $key = $action === 'complete' ? $this->key($request) : null;
        return $this->run($request, fn () => match ($action) {
            'approve' => $service->approve($refund, $actor, $reason), 'reject' => $service->reject($refund, $actor, $reason),
            'complete' => $service->complete($refund, $key, $actor),
        });
    }

    public function holdAction(Request $request, string $holdId, string $action, WalletHoldService $service)
    {
        $hold = WalletHold::where('public_id', $holdId)->firstOrFail();
        $this->authorizeAction($request, 'hold.' . $action, $hold->wallet_id);
        $reason = $this->reason($request); $key = $this->key($request);
        return $this->run($request, fn () => $service->$action($hold, $key, $this->actor($request), $reason));
    }

    public function reverse(Request $request, string $transactionId, FinancialAdjustmentService $service)
    {
        $transaction = FinancialTransaction::where('public_id', $transactionId)->firstOrFail();
        $ids = LedgerEntry::where('transaction_id', $transaction->public_id)->pluck('wallet_id')->unique();
        abort_if($ids->isEmpty(), 409, 'La operación no tiene movimientos contables.');
        foreach ($ids as $id) { $this->authorizeAction($request, 'transaction.reverse', $id); }
        $reason = $this->reason($request); $key = $this->key($request);
        return $this->run($request, fn () => $service->reverse($transaction, $key, $reason, $this->actor($request)));
    }

    public function createPolicy(Request $request, WalletHoldPolicyService $service)
    {
        $this->authorizeAction($request, 'policy.manage');
        $data = $request->validate(['operation_type' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'max_duration_seconds' => ['required', 'integer', 'min:1', 'max:2147483647'], 'active' => ['required', 'boolean']]);
        $reason = $this->reason($request);
        return $this->run($request, fn () => $service->create($data['operation_type'], (int) $data['max_duration_seconds'], $request->boolean('active'), $this->actor($request), $reason));
    }

    public function updatePolicy(Request $request, string $policyId, WalletHoldPolicyService $service)
    {
        $this->authorizeAction($request, 'policy.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'max_duration_seconds' => ['required', 'integer', 'min:1', 'max:2147483647'], 'active' => ['required', 'boolean'], 'operation_type' => ['prohibited']]);
        $reason = $this->reason($request); $policy = WalletHoldPolicy::where('public_id', $policyId)->firstOrFail();
        return $this->run($request, fn () => $service->update($policy, (int) $data['expected_version'], [
            'max_duration_seconds' => (int) $data['max_duration_seconds'], 'active' => $request->boolean('active'),
        ], $this->actor($request), $reason));
    }

    public function policyHistory(Request $request, string $policyId)
    {
        $this->authorizeAction($request, 'policy.view');
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $policy = WalletHoldPolicy::where('public_id', $policyId)->firstOrFail();
        return $this->paginated($request, $policy->changes()->orderByDesc('id')->paginate(20), fn ($row) => $this->fields($row, ['version', 'change_type', 'actor_id', 'reason', 'before', 'after']));
    }

    private function owns(Request $request, Wallet $wallet): bool
    {
        return $wallet->owner_type === 'USER' && (string) $wallet->owner_id === (string) $request->user()->getKey();
    }
    private function actor(Request $request): string { return 'user:' . $request->user()->getKey(); }
    private function allowed(Request $request, string $action, ?string $walletId = null): bool
    {
        return app(FinancialWebAuthorizer::class)->allows((string) $request->user()->getKey(), $action, $walletId ? strtolower($walletId) : null);
    }
    private function authorizeAction(Request $request, string $action, ?string $walletId = null): void
    {
        abort_unless($this->allowed($request, $action, $walletId), 403, 'No tienes permiso para realizar esta acción.');
    }
    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']])['reason'];
    }
    private function key(Request $request): string
    {
        return validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:255', 'regex:/\S/u']])->validate()['key'];
    }
    private function run(Request $request, callable $operation)
    {
        try {
            $row = DB::connection('sqlsrv')->transaction(function () use ($operation, $request) {
                $row = $operation();
                if ($row instanceof FinancialTransaction) {
                    // Use a fresh model so the completed-transaction observer retains its state.
                    $audited = FinancialTransaction::where('public_id', $row->public_id)->lockForUpdate()->firstOrFail();
                    $metadata = $audited->metadata ?? [];
                    $actor = $this->actor($request);
                    $reason = $request->input('reason');
                    if ((!$row->wasRecentlyCreated && !isset($metadata['web_actor_id']))
                        || (isset($metadata['web_actor_id']) && ($metadata['web_actor_id'] !== $actor
                            || ($metadata['web_action_reason'] ?? null) !== $reason))) {
                        throw new InvalidArgumentException('La clave pertenece a una solicitud con otro responsable o motivo.');
                    }
                    if (!isset($metadata['web_actor_id'])) {
                        $audited->metadata = $metadata + ['web_actor_id' => $actor, 'web_action_reason' => $reason];
                        $audited->save();
                    }
                }
                return $row;
            });
        }
        catch (InvalidArgumentException $exception) { return $this->conflict($request, $exception->getMessage()); }
        return $this->ok($request, ['id' => strtolower($row->public_id)]);
    }
    private function fields($row, array $keys): array
    {
        $data = ['id' => strtolower($row->public_id), 'created_at' => $row->created_at?->toISOString()];
        foreach ($keys as $key) {
            $value = $row->$key;
            $data[$key] = $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value);
        }
        return $data;
    }
}
