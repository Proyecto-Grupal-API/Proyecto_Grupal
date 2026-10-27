<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LegacyCashReceiptReview;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LegacyCashReceiptReviewService extends LegacyCashReceiptEvidenceService
{
    public function __construct(private LegacyCashReceiptAuthorization $authorization) {}

    public function classify(string $actor, string $transactionId, string $walletId, string $reason): LegacyCashReceiptReview
    {
        $this->authorization->assertGlobal($actor);
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            abort(422, 'Se requiere motivo de revisión.');
        }

        return DB::connection('sqlsrv')->transaction(function () use ($actor, $transactionId, $walletId, $reason) {
            $t = FinancialTransaction::where('public_id', $transactionId)->lockForUpdate()->firstOrFail();
            $w = Wallet::where('public_id', $walletId)->lockForUpdate()->firstOrFail();
            $this->authorization->assertGlobal($actor);
            $snapshot = $this->evidence($w, $t);
            $hash = $this->hash($snapshot);
            $existing = LegacyCashReceiptReview::where('transaction_id', $t->public_id)->first();
            if ($existing) {
                if (strtolower($existing->wallet_id) !== strtolower($w->public_id) || $existing->reviewed_by !== $actor
                    || $existing->reason !== trim($reason) || ! hash_equals($existing->evidence_hash, $hash)) {
                    throw new ConflictHttpException('La operación ya tiene una revisión diferente.');
                }

                return $existing;
            }
            $candidate = config('financial_receipts.legacy_candidates.'.strtolower($t->public_id));
            // Explicitly reviewed admission list: age or missing cash link alone never authorizes.
            if (! is_array($candidate) || strtolower((string) ($candidate['wallet_id'] ?? '')) !== strtolower($w->public_id)
                || ($candidate['amount_cents'] ?? null) !== $snapshot['amount_cents']
                || strtolower((string) ($candidate['reference_id'] ?? '')) !== $snapshot['reference_id']) {
                throw new ConflictHttpException('La operación no está identificada como candidata histórica.');
            }

            return LegacyCashReceiptReview::create(['public_id' => (string) Str::uuid(), 'transaction_id' => $t->public_id,
                'wallet_id' => $w->public_id, 'classification' => 'DEVELOPMENT_DEMO_WITHOUT_CASH_RECEIPT',
                'reviewed_by' => $actor, 'reason' => trim($reason), 'evidence_hash' => $hash, 'snapshot' => $snapshot, 'reviewed_at' => now()]);
        }, 3);
    }

    public function assertReviewed(string $actor, Wallet $wallet, FinancialTransaction $t): void
    {
        $this->authorization->assertGlobal($actor);
        if (! $this->notice($wallet, $t)) {
            throw new ConflictHttpException('La operación requiere revisión histórica explícita.');
        }
    }
}
