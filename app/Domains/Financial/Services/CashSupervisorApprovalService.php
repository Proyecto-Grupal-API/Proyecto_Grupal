<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashOperationConfirmation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
class CashSupervisorApprovalService {
    public function review(string $id, string $association, string $actor, bool $approve, string $reason): CashOperationConfirmation {
        app(CashShiftService::class)->text($reason, 1000);
        // A service token cannot impersonate a human supervisor. Web identity comes from the authenticated session.
        abort_unless(str_starts_with($actor, 'user:') && app(CashAuthorizationProvider::class)->allows($actor, $association, 'approval_review'), 403);
        return DB::connection('sqlsrv')->transaction(function () use ($id, $association, $actor, $approve, $reason) {
            $proof = CashOperationConfirmation::where('public_id', $id)->whereHas('shift.cashRegister',
                fn ($q) => $q->where('association_id', $association))->lockForUpdate()->firstOrFail();
            abort_unless($proof->operator_id !== $actor && 'user:'.$proof->student_id !== $actor, 403);
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($proof->supervisor_status === $status && $proof->supervisor_id === $actor && $proof->supervisor_reason === trim($reason)) return $proof;
            if (! $proof->supervisor_required || $proof->supervisor_status !== 'PENDING' || ! in_array($proof->status, ['PENDING', 'CONFIRMED'], true)
                || ! $proof->expires_at->isFuture() || $proof->shift->status->value !== 'OPEN' || $proof->shift->cashRegister->status->value !== 'ACTIVE')
                throw new InvalidArgumentException('La solicitud venció, fue revisada o ya no admite autorización.');
            $proof->fill(['supervisor_status' => $status, 'supervisor_id' => $actor, 'supervisor_reason' => trim($reason), 'supervisor_reviewed_at' => now()]);
            if (! $approve) $proof->fill(['status' => 'REJECTED', 'rejected_at' => now()]);
            $proof->save(); return $proof;
        });
    }
    public function assertApproved(CashOperationConfirmation $proof, string $association): void {
        if (! $proof->supervisor_required) return;
        if ($proof->supervisor_status !== 'APPROVED' || ! $proof->supervisor_reviewed_at || ! $proof->supervisor_id
            || ! str_starts_with($proof->supervisor_id, 'user:') || $proof->supervisor_id === $proof->operator_id
            || $proof->supervisor_id === 'user:'.$proof->student_id
            || ! app(CashAuthorizationProvider::class)->allows($proof->supervisor_id, $association, 'approval_review'))
            throw new InvalidArgumentException('Se requiere la segunda autorización vigente de un supervisor independiente.');
    }
}
