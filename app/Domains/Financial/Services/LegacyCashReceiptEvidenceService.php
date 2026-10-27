<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\LegacyCashReceiptReview;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Evidence projection has no dependency on the roles adapter. */
class LegacyCashReceiptEvidenceService
{
    public function notice(Wallet $wallet, FinancialTransaction $t): ?array
    {
        $review = LegacyCashReceiptReview::where('transaction_id', $t->public_id)->where('wallet_id', $wallet->public_id)->first();
        if (! $review) {
            return null;
        }
        if (! is_array($review->snapshot) || ! hash_equals($review->evidence_hash, $this->hash($review->snapshot))) {
            throw new ConflictHttpException('La copia de evidencia de la revisión requiere investigación.');
        }
        if (! hash_equals($review->evidence_hash, $this->hash($this->evidence($wallet, $t)))) {
            throw new ConflictHttpException('La evidencia cambió después de la revisión histórica.');
        }

        return ['review_id' => strtolower($review->public_id), 'classification' => $review->classification,
            'reviewed_at' => $review->reviewed_at->toISOString(),
            'message' => 'Recarga de demostración revisada, sin comprobante de recepción en caja.'];
    }

    protected function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected function evidence(Wallet $w, FinancialTransaction $t): array
    {
        if ($t->reference_type !== 'TOPUP' || $t->status->value !== 'COMPLETADA' || $t->original_transaction_id
            || $w->owner_type !== 'USER' || $w->type->value !== 'WALLET_USUARIO') {
            throw new ConflictHttpException('No es una recarga histórica elegible.');
        }
        $r = TopUp::where('public_id', $t->reference_id)->firstOrFail();
        if ($r->method->value !== 'EFECTIVO' || $r->status->value !== 'COMPLETADA' || $r->cash_shift_id !== null || $r->folio !== null
            || strtolower($r->wallet_id) !== strtolower($w->public_id) || $r->currency !== $w->currency
            || CashMovement::where('reference_type', 'TOPUP')->where('reference_id', $r->public_id)->exists()) {
            throw new ConflictHttpException('La recarga tiene datos incompatibles con la revisión histórica.');
        }
        $entries = LedgerEntry::where('transaction_id', $t->public_id)->orderBy('id')->get();
        if ($entries->count() !== 1 || strtolower($entries[0]->wallet_id) !== strtolower($w->public_id)
            || $entries[0]->movement_type->value !== 'RECARGA' || $entries[0]->amount_cents !== $r->amount_cents || $r->amount_cents <= 0) {
            throw new ConflictHttpException('La evidencia contable de la recarga requiere revisión.');
        }

        return ['transaction_id' => strtolower($t->public_id), 'reference_id' => strtolower($r->public_id),
            'wallet_id' => strtolower($w->public_id), 'owner_id' => $w->owner_id, 'currency' => $r->currency,
            'amount_cents' => $r->amount_cents, 'transaction_created_at' => $t->created_at->toISOString(),
            'topup_created_at' => $r->created_at->toISOString(), 'agent_id' => $r->agent_id,
            'idempotency_digest' => hash('sha256', $t->idempotency_key),
            'movement_type' => $entries[0]->movement_type->value, 'entry_created_at' => $entries[0]->created_at->toISOString(),
            'entry_id' => strtolower($entries[0]->public_id), 'balance_after_cents' => $entries[0]->balance_after_cents,
            'available_balance_after_cents' => $entries[0]->available_balance_after_cents,
            'held_balance_after_cents' => $entries[0]->held_balance_after_cents];
    }
}
