<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LegacyCashReceiptReview;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LegacyCashReceiptHistoryService
{
    public function __construct(private LegacyCashReceiptAuthorization $authorization, private LegacyCashReceiptEvidenceService $evidence) {}

    public function listing(string $actor, array $filters): array
    {
        $this->authorization->assertGlobal($actor);
        $q = LegacyCashReceiptReview::query()->select(['public_id', 'transaction_id', 'wallet_id', 'classification', 'reviewed_by', 'reason', 'reviewed_at']);
        foreach (['transaction_id', 'wallet_id', 'reviewed_by'] as $key) {
            if (! empty($filters[$key])) {
                $q->where($key, $filters[$key]);
            }
        }
        $page = $q->orderByDesc('reviewed_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->getCollection()->map(fn ($r) => $this->summary($r))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]];
    }

    public function detail(string $actor, string $id): array
    {
        $this->authorization->assertGlobal($actor);
        $r = LegacyCashReceiptReview::where('public_id', $id)->firstOrFail();
        $snapshot = $r->snapshot;
        $status = 'CONSISTENT';
        if (! is_array($snapshot) || ! hash_equals($r->evidence_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
            $status = 'REVIEW_RECORD_INCONSISTENT';
        } else {
            $w = Wallet::where('public_id', $r->wallet_id)->first();
            $t = FinancialTransaction::where('public_id', $r->transaction_id)->first();
            if (! $w || ! $t) {
                $status = 'SOURCE_MISSING';
            } else {
                try {
                    if (! $this->evidence->notice($w, $t)) {
                        $status = 'SOURCE_CHANGED';
                    }
                } catch (ConflictHttpException|ModelNotFoundException $e) {
                    $status = 'SOURCE_CHANGED';
                }
            }
        }

        // History remains readable when the current operation cannot be rendered.
        return array_merge($this->summary($r), ['snapshot' => $snapshot, 'evidence_hash' => $r->evidence_hash, 'evidence_status' => $status]);
    }

    private function summary(LegacyCashReceiptReview $r): array
    {
        return ['id' => strtolower($r->public_id), 'transaction_id' => strtolower($r->transaction_id), 'wallet_id' => strtolower($r->wallet_id),
            'classification' => $r->classification, 'reviewed_by' => $r->reviewed_by, 'reason' => $r->reason, 'reviewed_at' => $r->reviewed_at->toISOString()];
    }
}
