<?php

namespace App\Domains\Financial\Models;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchasePayment extends Model
{
    protected $connection = 'sqlsrv';

    protected $table = 'purchase_payments';

    protected $fillable = [
        'public_id',
        'idempotency_key',
        'wallet_id',
        'bonus_id',
        'requested_bonus_ids',
        'business_id',
        'category_id',
        'currency',
        'total_amount_cents',
        'bonus_amount_cents',
        'wallet_amount_cents',
        'status',
    ];

    protected $casts = [
        'total_amount_cents' => 'integer',
        'bonus_amount_cents' => 'integer',
        'wallet_amount_cents' => 'integer',
        'requested_bonus_ids' => 'array',
    ];

    /** Verify the original charge and return the recipient bound at settlement, if any. */
    public function assertWalletCharge(FinancialTransaction $transaction): ?string
    {
        $recipient = $transaction->metadata['recipient_wallet_id'] ?? null;
        if ($recipient !== null && (! is_string($recipient) || ! Str::isUuid($recipient))) {
            throw new InvalidArgumentException('La cuenta receptora registrada no es válida.');
        }
        // Mixed purchases historically reference their bonus, or have no reference for a batch.
        $reference = $this->bonus_id ?? ($this->requested_bonus_ids === null ? $this->public_id : null);
        $entries = LedgerEntry::where('transaction_id', $transaction->public_id)->get();
        $debit = $entries->first(fn (LedgerEntry $entry) => strcasecmp($entry->wallet_id, $this->wallet_id) === 0);
        $credit = $recipient === null ? null : $entries->first(
            fn (LedgerEntry $entry) => strcasecmp($entry->wallet_id, $recipient) === 0
        );

        if ($transaction->status !== TransactionStatus::COMPLETADA || $transaction->reference_type !== 'PURCHASE'
            || strcasecmp((string) $transaction->reference_id, (string) $reference) !== 0
            || $entries->count() !== ($recipient === null ? 1 : 2)
            || $debit?->movement_type !== MovementType::PAGO || $debit->amount_cents !== -$this->wallet_amount_cents
            || ($recipient !== null && ($credit?->movement_type !== MovementType::LIQUIDACION_COMPRA
                || $credit->amount_cents !== $this->wallet_amount_cents))) {
            throw new InvalidArgumentException('La compra no tiene evidencia contable consistente.');
        }

        return $recipient;
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(
            PurchasePaymentBonus::class,
            'purchase_payment_id',
            'public_id'
        );
    }
}
