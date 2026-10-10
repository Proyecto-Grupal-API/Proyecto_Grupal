<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferConfirmation;
class StudentTransferQueryService
{
    public function listing(string $actor, array $filters = []): array
    {
        $actor = strtolower($actor);
        $query = StudentTransfer::where(fn ($q) => $q->where('sender_id', $actor)->orWhere('recipient_id', $actor));
        if (!empty($filters['kind'])) { $query->where('kind', $filters['kind']); }
        if (($filters['direction'] ?? null) === 'SALIDA') { $query->where('sender_id', $actor); }
        if (($filters['direction'] ?? null) === 'ENTRADA') { $query->where('recipient_id', $actor); }
        $rows = $query->latest('id')->paginate(20);
        $transactions = FinancialTransaction::whereIn('public_id', $rows->getCollection()->pluck('financial_transaction_id'))->get()->keyBy(fn ($t) => strtolower($t->public_id));
        return ['data' => $rows->getCollection()->map(fn ($t) => $this->serialize($t, $actor, $transactions->get(strtolower($t->financial_transaction_id))))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }
    public function detail(string $actor, string $id): array
    {
        $actor = strtolower($actor);
        $t = StudentTransfer::where('public_id', $id)->where(fn ($q) => $q->where('sender_id', $actor)->orWhere('recipient_id', $actor))->firstOrFail();
        $transaction = FinancialTransaction::where('public_id', $t->financial_transaction_id)->firstOrFail();
        $reversal = FinancialTransaction::where('original_transaction_id', $transaction->public_id)->where('reference_type', 'REVERSO')->first();
        $ownWallet = $t->sender_id === $actor ? $t->source_wallet_id : $t->destination_wallet_id;
        $entries = LedgerEntry::where('transaction_id', $transaction->public_id)->where('wallet_id', $ownWallet)->orderBy('id')->get();
        $confirmation = StudentTransferConfirmation::where('student_transfer_id', $t->public_id)->first();
        return $this->serialize($t, $actor, $transaction) + [
            'reference_type' => $transaction->reference_type, 'reference_id' => strtolower((string) $transaction->reference_id),
            'own_ledger' => $entries->map(fn ($e) => ['movement_type' => $e->movement_type->value, 'amount_cents' => $e->amount_cents])->values(),
            'confirmation' => $confirmation ? ['prepared_at' => $confirmation->created_at?->toISOString(), 'confirmed_at' => $confirmation->confirmed_at?->toISOString()] : null,
            'reversal' => $reversal ? ['id' => strtolower($reversal->public_id), 'status' => $reversal->status->value, 'created_at' => $reversal->created_at?->toISOString()] : null,
        ];
    }
    public function serialize(StudentTransfer $t, string $actor, ?FinancialTransaction $transaction = null): array
    {
        $transaction ??= FinancialTransaction::where('public_id', $t->financial_transaction_id)->firstOrFail();
        $context = $t->audit_context;
        return ['id' => strtolower($t->public_id), 'kind' => $t->kind->value, 'amount_cents' => $t->amount_cents, 'currency' => $t->currency,
            'concept' => $t->concept, 'direction' => $t->sender_id === strtolower($actor) ? 'SALIDA' : 'ENTRADA', 'sender_id' => $t->sender_id, 'recipient_id' => $t->recipient_id,
            'status' => $transaction->status->value, 'executed_status' => $t->status, 'completed_at' => $t->completed_at?->toISOString(),
            'financial_transaction_id' => strtolower($t->financial_transaction_id), 'policy' => $t->policy_snapshot,
            'trace' => $context ? ['channel' => $context['channel'] ?? null, 'correlation_id' => $context['correlation_id'] ?? null] : null];
    }
}
