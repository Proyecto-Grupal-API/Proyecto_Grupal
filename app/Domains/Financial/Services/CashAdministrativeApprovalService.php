<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashAdministrativeRequest;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
class CashAdministrativeApprovalService {
    private function action(string $operation): string {
        return match ($operation) { 'ADJUSTMENT' => 'adjust', 'WITHDRAWAL_RECOVERY' => 'recover', default => throw new InvalidArgumentException('Operación administrativa inválida.') };
    }
    private function authorize(string $actor, string $association, string $action): void {
        abort_unless(app(CashAuthorizationProvider::class)->allows($actor, $association, $action), 403);
    }
    private function validateAmount(string $operation, int $amount): void {
        if ($amount === 0 || $amount === PHP_INT_MIN || ($operation === 'WITHDRAWAL_RECOVERY' && $amount < 0)) throw new InvalidArgumentException('El importe administrativo no es válido.');
    }
    public function request(string $association, string $shiftId, string $operation, int $amount, ?string $refundId, string $actor, string $reason, string $key): CashAdministrativeRequest {
        $cash = app(CashShiftService::class); foreach ([$actor, $key] as $text) $cash->text($text); $cash->text($reason, 1000);
        $this->authorize($actor, $association, $this->action($operation));
        $this->validateAmount($operation, $amount);
        if (($operation === 'WITHDRAWAL_RECOVERY') !== ($refundId !== null)) throw new InvalidArgumentException('La referencia no corresponde al tipo de operación.');
        $hash = $cash->hash([$association, strtolower($shiftId), $operation, $amount, $refundId ? strtolower($refundId) : null, $actor, trim($reason)]);
        return DB::connection('sqlsrv')->transaction(function () use ($cash, $association, $shiftId, $operation, $amount, $refundId, $actor, $reason, $key, $hash) {
            $shift = $cash->lockShift($shiftId);
            abort_unless($shift->cashRegister->association_id === $association, 404);
            if ($operation === 'WITHDRAWAL_RECOVERY') abort_unless($shift->agent_id === $actor, 403);
            $prior = CashAdministrativeRequest::where('request_key', trim($key))->lockForUpdate()->first();
            if ($prior) { if ($prior->request_hash !== $hash) throw new InvalidArgumentException('La clave pertenece a otra solicitud administrativa.'); return $prior; }
            $cash->assertOpen($shift);
            if ($shift->cashRegister->status->value !== 'ACTIVE') throw new InvalidArgumentException('La caja no está activa.');
            $studentId = null;
            if ($refundId) {
                $refund = FinancialRefundRequest::where('public_id', $refundId)->with(['originalTransaction', 'wallet'])->firstOrFail();
                $original = $refund->originalTransaction;
                $withdrawal = $original?->reference_type === 'WITHDRAWAL' ? Withdrawal::where('public_id', $original->reference_id)->first() : null;
                $origin = $withdrawal?->cash_shift_id ? CashShift::where('public_id', $withdrawal->cash_shift_id)->first() : null;
                if ($refund->status->value !== 'APROBADA' || $refund->amount_cents !== $amount || ! $origin
                    || $origin->cashRegister->association_id !== $association || $withdrawal->currency !== $shift->cashRegister->currency
                    || FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->exists())
                    throw new InvalidArgumentException('La recuperación requiere una devolución de retiro aprobada, sin recuperación previa y de esta asociación y moneda.');
                if ($refund->wallet->owner_type === 'USER') $studentId = (string) $refund->wallet->owner_id;
            }
            $policy = app(CashApprovalPolicyService::class)->snapshot($association, $operation, $shift->cashRegister->currency);
            $required = $policy['enabled'] && abs($amount) >= $policy['threshold_cents'];
            $ttl = (int) config('financial.cash.confirmation_ttl_seconds', 300);
            if ($ttl < 60 || $ttl > 900) throw new InvalidArgumentException('La vigencia debe estar entre 60 y 900 segundos.');
            return CashAdministrativeRequest::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id, 'operation' => $operation,
                'amount_cents' => $amount, 'currency' => $shift->cashRegister->currency, 'refund_request_id' => $refundId,
                'operator_id' => $actor, 'student_id' => $studentId, 'reason' => trim($reason), 'request_key' => trim($key), 'request_hash' => $hash,
                'supervisor_required' => $required, 'approval_policy_snapshot' => $policy, 'status' => $required ? 'PENDING' : 'READY', 'expires_at' => now()->addSeconds($ttl)])->fresh();
        });
    }
    public function review(string $id, string $association, string $actor, bool $approve, string $reason): CashAdministrativeRequest {
        app(CashShiftService::class)->text($reason, 1000);
        abort_unless(str_starts_with($actor, 'user:'), 403); $this->authorize($actor, $association, 'approval_review');
        return DB::connection('sqlsrv')->transaction(function () use ($id, $association, $actor, $approve, $reason) {
            $candidate = CashAdministrativeRequest::where('public_id', $id)->whereHas('shift.cashRegister', fn ($q) => $q->where('association_id', $association))->firstOrFail();
            app(CashShiftService::class)->lockShift($candidate->shift->public_id);
            $p = CashAdministrativeRequest::where('public_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($p->operator_id !== $actor && $p->shift->agent_id !== $actor && (! $p->student_id || 'user:'.$p->student_id !== $actor), 403);
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if (($p->status === $status || ($approve && $p->status === 'CONSUMED')) && $p->supervisor_id === $actor && $p->supervisor_reason === trim($reason)) return $p;
            if (! $p->supervisor_required || $p->status !== 'PENDING' || ! $p->expires_at->isFuture() || $p->shift->status->value !== 'OPEN' || $p->shift->cashRegister->status->value !== 'ACTIVE')
                throw new InvalidArgumentException('La solicitud venció, ya fue revisada o no admite esta autorización.');
            $p->fill(['status' => $status, 'supervisor_id' => $actor, 'supervisor_reason' => trim($reason), 'supervisor_reviewed_at' => now()])->save(); return $p;
        });
    }
    public function cancel(string $id, string $actor): CashAdministrativeRequest {
        return DB::connection('sqlsrv')->transaction(function () use ($id, $actor) {
            $candidate = CashAdministrativeRequest::where('public_id', $id)->where('operator_id', $actor)->firstOrFail();
            app(CashShiftService::class)->lockShift($candidate->shift->public_id);
            $p = CashAdministrativeRequest::where('public_id', $id)->lockForUpdate()->firstOrFail(); abort_unless($p->operator_id === $actor, 403);
            $this->authorize($actor, $p->shift->cashRegister->association_id, $this->action($p->operation));
            if ($p->status === 'CANCELLED') return $p;
            if (! in_array($p->status, ['PENDING', 'APPROVED', 'READY'], true)) throw new InvalidArgumentException('La solicitud ya no puede cancelarse.');
            $p->fill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save(); return $p;
        });
    }
    public function execute(?string $id, string $association, string $shiftId, string $operation, int $amount, ?string $refundId, string $actor, string $reason, string $key, callable $execute) {
        $this->authorize($actor, $association, $this->action($operation)); $this->validateAmount($operation, $amount);
        return DB::connection('sqlsrv')->transaction(function () use ($id, $association, $shiftId, $operation, $amount, $refundId, $actor, $reason, $key, $execute) {
            $shift = app(CashShiftService::class)->lockShift($shiftId); abort_unless($shift->cashRegister->association_id === $association, 404);
            if (! $id) {
                // Old completed requests may replay with their original parameters, even after a policy is enabled.
                $existing = CashMovement::where('idempotency_key', trim($key))->first();
                if ($existing) {
                    if (CashAdministrativeRequest::where('settlement_key', trim($key))->exists()) throw new InvalidArgumentException('El reintento debe conservar la solicitud administrativa original.');
                    return $execute(null); // The original cash service verifies its complete immutable hash.
                }
                $policy = app(CashApprovalPolicyService::class)->snapshot($association, $operation, $shift->cashRegister->currency);
                if ($policy['enabled'] && abs($amount) >= $policy['threshold_cents']) throw new InvalidArgumentException('Solicita primero la autorización administrativa de esta operación.');
                return $execute(null);
            }
            $p = CashAdministrativeRequest::where('public_id', $id)->lockForUpdate()->firstOrFail();
            if ($p->cash_shift_id !== $shift->id || $p->operation !== $operation || $p->amount_cents !== $amount || $p->operator_id !== $actor
                || $p->currency !== $shift->cashRegister->currency || $p->reason !== trim($reason)
                || strtolower((string) $p->refund_request_id) !== strtolower((string) $refundId))
                throw new InvalidArgumentException('La autorización no corresponde al turno, operación, importe, operador, motivo o devolución.');
            if ($p->status === 'CONSUMED') {
                if ($p->settlement_key !== trim($key)) throw new InvalidArgumentException('La autorización ya fue consumida con otra clave.');
                $movement = CashMovement::where('public_id', $p->cash_movement_id)->where('idempotency_key', trim($key))->firstOrFail();
                if ($movement->cash_shift_id !== $shift->id || $movement->amount_cents !== $amount) throw new InvalidArgumentException('La evidencia no corresponde a la autorización.');
                return $execute($p);
            }
            if (! $p->expires_at->isFuture() || $p->status !== ($p->supervisor_required ? 'APPROVED' : 'READY')) throw new InvalidArgumentException('Se requiere una solicitud vigente y autorizada.');
            if ($p->supervisor_required && (! $p->supervisor_reviewed_at || ! str_starts_with((string) $p->supervisor_id, 'user:')
                || $p->supervisor_id === $actor || $p->supervisor_id === $shift->agent_id || ($p->student_id && $p->supervisor_id === 'user:'.$p->student_id)
                || ! app(CashAuthorizationProvider::class)->allows($p->supervisor_id, $association, 'approval_review')))
                throw new InvalidArgumentException('El supervisor debe ser independiente y conservar su permiso en esta asociación.');
            $p->settlement_key = trim($key); $p->save();
            $result = $execute($p);
            $movement = CashMovement::where('cash_shift_id', $shift->id)->where('idempotency_key', trim($key))->firstOrFail();
            $p->fill(['status' => 'CONSUMED', 'cash_movement_id' => $movement->public_id, 'consumed_at' => now()])->save(); return $result;
        });
    }
}
