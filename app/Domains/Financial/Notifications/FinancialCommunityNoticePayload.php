<?php

namespace App\Domains\Financial\Notifications;

use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusBudgetCommitment;
use App\Domains\Financial\Models\EventPaymentRequest;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\InstitutionalBudgetOperation;
use Illuminate\Support\Str;

/** Only durable SQL evidence; no recipient, balance or QR availability lookup. */
final class FinancialCommunityNoticePayload
{
    public const PAYMENT = 'pago_evento_confirmado';
    public const REFUND = 'devolucion_confirmada';
    public const BECA = 'beca_emitida';

    public static function payment(EventPaymentRequest $request, ?FinancialTransaction $transaction): ?array
    {
        if ($request->status !== 'CONFIRMED' || ! $request->confirmed_at || ! $transaction
            || $transaction->status !== TransactionStatus::COMPLETADA
            || ! self::same($transaction->public_id, $request->transaction_id)
            || $transaction->reference_type !== 'COMMUNITY_EVENT'
            || $transaction->reference_id !== $request->source_reference
            || $transaction->idempotency_key !== 'event-payment:'.strtolower($request->public_id)
            || ($transaction->metadata['student_id'] ?? null) !== $request->student_id) {
            return null;
        }

        return self::make(self::PAYMENT, $transaction->public_id, $request->student_id,
            $request->amount_cents, 'comprobante_financiero', $transaction->public_id);
    }

    public static function refund(EventPaymentRequest $payment, ?FinancialTransaction $original,
        FinancialRefundRequest $request, ?FinancialTransaction $transaction): ?array
    {
        // Same completed-refund evidence as EventCheckoutService::completedRefund,
        // plus the proven original payment and the exact completed request binding.
        if (! self::payment($payment, $original) || $request->status !== RefundRequestStatus::COMPLETADA
            || ! $request->completed_at || $request->amount_cents !== $payment->amount_cents
            || ! self::same($request->original_transaction_id, $payment->transaction_id)
            || ! $transaction || $transaction->status !== TransactionStatus::COMPLETADA
            || ! self::same($transaction->public_id, $request->financial_transaction_id)
            || $transaction->reference_type !== 'DEVOLUCION'
            || ! self::same($transaction->reference_id, $request->public_id)
            || ! self::same($transaction->original_transaction_id, $payment->transaction_id)
            || ($transaction->metadata['amount_cents'] ?? null) !== $payment->amount_cents) {
            return null;
        }

        return self::make(self::REFUND, $transaction->public_id, $payment->student_id,
            $payment->amount_cents, 'comprobante_financiero', $transaction->public_id);
    }

    public static function beca(BonusBudgetCommitment $link, ?InstitutionalBudgetOperation $operation, ?Bonus $bonus): ?array
    {
        // Matches CommunityBecaController::proof. A BECA label alone is insufficient.
        $reference = $link->award_reference;
        $after = $operation?->after_data ?? [];
        if (! is_string($reference) || ! preg_match('/\Abeca:[0-9a-f]{24}\z/', $reference)
            || ! $bonus || $bonus->type !== BonusType::BECA || $bonus->beneficiary_type !== 'USER'
            || $bonus->external_reference !== $reference || ! $operation
            || ! self::same($operation->public_id, $link->issue_operation_id)
            || ! self::same($bonus->public_id, $link->bonus_id)
            || $operation->action !== 'ISSUE_BECA' || $operation->idempotency_key !== $reference
            || ($after['award_reference'] ?? null) !== $reference
            || ($after['bonus_id'] ?? null) !== strtolower($bonus->public_id)
            || ! self::same($operation->budget_id, $link->budget_id)
            || ! self::same($bonus->getRawOriginal('institutional_budget_id'), $link->budget_id)
            || ($after['committed_delta_cents'] ?? null) !== $link->amount_cents
            || $link->amount_cents !== $bonus->original_amount_cents
            || $link->currency !== 'MXN' || $bonus->currency !== 'MXN'
            || ! is_string($after['community_award_fingerprint'] ?? null)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $after['community_award_fingerprint'])) {
            return null;
        }

        return self::make(self::BECA, $operation->public_id, $bonus->beneficiary_id,
            $bonus->original_amount_cents, 'bono_financiero', $bonus->public_id);
    }

    private static function make(string $type, mixed $operation, mixed $user, mixed $cents, string $destination, mixed $id): ?array
    {
        if (! is_string($operation) || ! Str::isUuid($operation) || ! is_string($id) || ! Str::isUuid($id)
            || ! is_string($user) || ! preg_match('/\A[0-9a-f]{24}\z/', $user)
            || ! is_int($cents) || $cents <= 0) {
            return null;
        }
        // Integer arithmetic only, including amounts above IEEE-754's exact range.
        $money = intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).' MXN';
        [$subject, $text] = match ($type) {
            self::PAYMENT => ['Pago de evento confirmado', 'Se confirmó tu pago de evento por '.$money.'. Consulta el comprobante financiero.'],
            self::REFUND => ['Devolución confirmada', 'Se confirmó la devolución de tu pago de evento por '.$money.'. Consulta el comprobante financiero.'],
            self::BECA => ['Beca emitida', 'Se emitió tu bono de beca por '.$money.'. Consulta el bono y sus condiciones de uso.'],
        };

        return ['clave_idempotencia' => 'financial-community:'.$type.':'.strtolower($operation),
            'destinatario_id' => $user, 'tipo' => $type, 'asunto' => $subject, 'texto' => $text,
            'accion' => ['destino' => $destination, 'id' => strtolower($id)], 'importancia' => 'normal'];
    }

    private static function same(mixed $left, mixed $right): bool
    {
        return is_string($left) && $left !== '' && is_string($right) && strcasecmp($left, $right) === 0;
    }
}
