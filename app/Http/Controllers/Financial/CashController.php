<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class CashController extends Controller
{
    use FinancialControlResponses;

    public function __construct(private readonly CashAuthorizationProvider $authorization,
        private readonly CashShiftService $shifts, private readonly CashSettlementService $settlements) {}

    protected function authorizeAssociation(Request $request, string $associationId, string $action): string
    {
        Validator::make(['association_id' => $associationId], ['association_id' => ['required', 'string', 'max:255']])->validate();
        $actor = $this->authenticatedActor($request);
        abort_unless($this->authorization->allows($actor, $associationId, $action), 403, 'Sin permiso para esta acción en la asociación.');
        return $actor;
    }

    protected function register(string $associationId, string $registerId): CashRegister
    {
        return CashRegister::where('association_id', $associationId)->where('public_id', $registerId)->firstOrFail();
    }

    protected function shift(string $associationId, string $shiftId): CashShift
    {
        $registerIds = CashRegister::where('association_id', $associationId)->select('id');
        return CashShift::whereIn('cash_register_id', $registerIds)->where('public_id', $shiftId)->firstOrFail();
    }

    protected function key(Request $request): string
    {
        $value = Validator::make(['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:255', 'regex:/\S/']])->validate();
        return trim($value['key']);
    }

    protected function pageSize(Request $request): int
    {
        $values = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1']]);
        return (int) ($values['per_page'] ?? 25);
    }

    public function registers(Request $request, string $associationId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        return $this->paginated($request, CashRegister::where('association_id', $associationId)->orderByDesc('id')
            ->paginate($this->pageSize($request)), fn ($register) => [
                'id' => strtolower($register->public_id), 'association_id' => $register->association_id,
                'name' => $register->name, 'currency' => $register->currency, 'status' => $register->status->value,
                'version' => $register->version]);
    }

    public function shifts(Request $request, string $associationId, string $registerId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        $register = $this->register($associationId, $registerId);
        return $this->paginated($request, CashShift::where('cash_register_id', $register->id)->orderByDesc('id')
            ->paginate($this->pageSize($request)), fn ($shift) => $this->shiftData($shift));
    }

    public function show(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        $shift = $this->shift($associationId, $shiftId);
        return $this->ok($request, $this->summaryData($this->shifts->getSummary($shift->public_id)));
    }

    public function movements(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        $shift = $this->shift($associationId, $shiftId);
        return $this->paginated($request, $shift->movements()->with('receipt')->orderByDesc('id')->paginate($this->pageSize($request)),
            fn ($movement) => $this->movementData($movement));
    }

    protected function findReceipt(string $associationId, string $receiptId): CashReceipt
    {
        return CashReceipt::where('public_id', $receiptId)->whereHas('movement.shift.cashRegister',
            fn ($query) => $query->where('association_id', $associationId))->firstOrFail();
    }

    public function receipt(Request $request, string $associationId, string $receiptId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        return $this->ok($request, $this->findReceipt($associationId, $receiptId)->snapshot);
    }

    public function open(Request $request, string $associationId, string $registerId): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $register = $this->register($associationId, $registerId);
        $values = $request->validate(['opening_amount_cents' => ['required', 'integer', 'min:0']]);
        $key = $this->key($request);
        return $this->execute($request, fn () => $this->shiftData($this->shifts->open(
            $register->public_id, $actor, $values['opening_amount_cents'], $key)));
    }

    public function move(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        return $this->manual($request, $associationId, $shiftId, false);
    }

    public function adjust(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        return $this->manual($request, $associationId, $shiftId, true);
    }

    private function manual(Request $request, string $associationId, string $shiftId, bool $adjust): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, $adjust ? 'adjust' : 'operate');
        $shift = $this->shift($associationId, $shiftId);
        if (! $adjust) abort_unless($shift->agent_id === $actor, 403, 'El turno pertenece a otro operador.');
        $values = $request->validate(['type' => $adjust ? ['prohibited'] : ['required', Rule::in(['CASH_IN', 'CASH_OUT'])],
            'amount_cents' => $adjust ? ['required', 'integer', 'not_in:0'] : ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'], 'administrative_request_id' => $adjust ? ['nullable', 'uuid'] : ['prohibited']]);
        $key = $this->key($request);
        $type = $adjust ? CashMovementType::ADJUSTMENT : CashMovementType::from($values['type']);
        return $this->execute($request, function () use ($adjust, $values, $associationId, $shift, $type, $key, $actor) {
            $move = fn ($authorization = null) => $this->shifts->addMovement($shift->public_id, $type, $values['amount_cents'], $key, $actor,
                $values['reason'], null, null, $authorization?->public_id);
            $result = $adjust ? app(\App\Domains\Financial\Services\CashAdministrativeApprovalService::class)->execute(
                $values['administrative_request_id'] ?? null, $associationId, $shift->public_id, 'ADJUSTMENT', $values['amount_cents'], null, $actor, $values['reason'], $key, $move) : $move();
            return $this->movementData($result);
        });
    }

    public function topUp(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        return $this->settle($request, $associationId, $shiftId, true);
    }

    public function withdraw(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        return $this->settle($request, $associationId, $shiftId, false);
    }

    private function settle(Request $request, string $associationId, string $shiftId, bool $incoming): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $shift = $this->shift($associationId, $shiftId);
        abort_unless($shift->agent_id === $actor, 403, 'El turno pertenece a otro operador.');
        $values = $request->validate(['wallet_id' => ['required', 'uuid'], 'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'], 'confirmation_id' => ['required', 'uuid']]);
        $key = $this->key($request);
        $wallet = Wallet::where('public_id', $values['wallet_id'])->firstOrFail();
        return $this->execute($request, function () use ($incoming, $shift, $wallet, $values, $key, $actor) {
            $operation = $this->settlements->settleConfirmed($shift->public_id, $wallet, $values['amount_cents'], $key,
                $actor, $values['reason'], $incoming, $values['confirmation_id']);
            $receipt = CashMovement::where('idempotency_key', $key)->firstOrFail()->receipt;
            return ['id' => strtolower($operation->public_id), 'wallet_id' => strtolower($operation->wallet_id),
                'cash_shift_id' => strtolower($operation->cash_shift_id), 'amount_cents' => $operation->amount_cents,
                'currency' => $operation->currency, 'status' => $operation->status->value, 'actor_id' => $operation->agent_id,
                'folio' => $operation->folio, 'receipt_id' => $receipt ? strtolower($receipt->public_id) : null];
        });
    }

    public function close(Request $request, string $associationId, string $shiftId): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'close');
        $shift = $this->shift($associationId, $shiftId);
        $values = $request->validate(['counted_amount_cents' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000']]);
        $key = $this->key($request);
        return $this->execute($request, fn () => $this->summaryData($this->shifts->close(
            $shift->public_id, $values['counted_amount_cents'], $key, $actor, $values['reason'])));
    }

    protected function execute(Request $request, callable $operation): JsonResponse
    {
        try { return $this->ok($request, $operation()); }
        catch (FinancialLimitExceededException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error' => $exception->toArray(),
                'meta' => $this->meta($request)], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'meta' => $this->meta($request)], 409);
        } catch (UniqueConstraintViolationException $exception) {
            return response()->json(['message' => 'Conflicto concurrente. Reintenta la solicitud con la misma clave.',
                'meta' => $this->meta($request)], 409);
        }
    }

    protected function shiftData(CashShift $shift): array
    {
        return ['id' => strtolower($shift->public_id), 'cash_register_id' => strtolower($shift->cashRegister->public_id),
            'association_id' => $shift->cashRegister->association_id, 'currency' => $shift->cashRegister->currency,
            'agent_id' => $shift->agent_id, 'status' => $shift->status->value,
            'opening_amount_cents' => $shift->opening_amount_cents, 'opened_at' => $shift->opened_at?->toISOString(),
            'closed_at' => $shift->closed_at?->toISOString(), 'closed_by' => $shift->closed_by, 'closing_reason' => $shift->closing_reason];
    }

    private function summaryData(array $summary): array
    {
        $data = $this->shiftData($summary['shift']);
        unset($summary['shift']);
        return array_merge($data, $summary);
    }

    private function movementData(CashMovement $movement): array
    {
        return ['id' => strtolower($movement->public_id), 'type' => $movement->type->value,
            'amount_cents' => $movement->amount_cents, 'actor_id' => $movement->actor_id, 'reason' => $movement->reason,
            'wallet_id' => $movement->wallet_id ? strtolower($movement->wallet_id) : null,
            'reference_type' => $movement->reference_type, 'reference_id' => $movement->reference_id,
            'created_at' => $movement->created_at?->toISOString(),
            'folio' => $movement->receipt?->folio, 'receipt_id' => $movement->receipt ? strtolower($movement->receipt->public_id) : null];
    }
}
