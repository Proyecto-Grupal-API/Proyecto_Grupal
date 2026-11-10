<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\WalletHold;
use App\Domains\Financial\Services\WalletHoldService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use App\Services\OAuthTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RentalDepositPilotController extends Controller
{
    use FinancialControlResponses;

    public function hold(Request $request, string $rental, WalletHoldService $holds): JsonResponse
    {
        $actor = $this->pilotActor($request, 'hold');
        $data = $request->validate([
            'student_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/D'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ]);
        $key = $this->key($request, $rental, 'hold');
        $this->recipient();
        $wallet = Wallet::where('owner_type', 'USER')->where('owner_id', $data['student_id'])
            ->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->firstOrFail();

        try {
            $hold = $holds->create($wallet, (int) $data['amount_cents'], 'RENTAL_DEPOSIT',
                CarbonImmutable::parse($data['expires_at']), $key, $actor, 'Garantía de renta.', 'STUDENT_RENTAL', $rental);
        } catch (InvalidArgumentException $error) {
            return $this->conflict($request, $error->getMessage());
        }

        return $this->holdResponse($request, $hold, $data['student_id'], $hold->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $request, string $rental): JsonResponse
    {
        $actor = $this->pilotActor($request, 'hold');
        $transaction = FinancialTransaction::where('idempotency_key', 'rental:'.$rental.':deposit-hold')
            ->where('reference_type', 'WALLET_HOLD_RESERVE')->firstOrFail();
        $hold = WalletHold::where('hold_transaction_id', $transaction->public_id)
            ->where('operation_type', 'RENTAL_DEPOSIT')
            ->where('reference_type', 'STUDENT_RENTAL')->where('reference_id', $rental)
            ->where('requested_by', $actor)->where('currency', 'MXN')->firstOrFail();
        $wallet = Wallet::where('public_id', $hold->wallet_id)->where('owner_type', 'USER')
            ->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->firstOrFail();

        return $this->holdResponse($request, $hold, $wallet->owner_id);
    }

    private function holdResponse(Request $request, WalletHold $hold, string $studentId, int $status = 200): JsonResponse
    {
        return $this->ok($request, [
            'rental_id' => $hold->reference_id,
            'hold_id' => strtolower($hold->public_id),
            'student_id' => $studentId,
            'amount_cents' => $hold->amount_cents,
            'currency' => $hold->currency,
            'status' => $hold->status->value,
            'closing_transaction_id' => $hold->closing_transaction_id ? strtolower($hold->closing_transaction_id) : null,
            'expires_at' => $hold->expires_at->avoidMutation()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $status);
    }

    public function settle(Request $request, string $rental, string $hold, WalletHoldService $holds): JsonResponse
    {
        $actor = $this->pilotActor($request, 'settle');
        $data = $request->validate([
            'condition' => ['required', 'in:good,damaged,lost'],
            'charged_cents' => ['required', 'integer', 'min:0'],
        ]);
        $key = $this->key($request, $rental, 'settle');
        $deposit = WalletHold::where('public_id', $hold)->where('operation_type', 'RENTAL_DEPOSIT')
            ->where('reference_type', 'STUDENT_RENTAL')->where('reference_id', $rental)
            ->where('requested_by', $actor)->where('currency', 'MXN')->firstOrFail();
        $charged = (int) $data['charged_cents'];
        abort_unless(match ($data['condition']) {
            'good' => $charged === 0,
            'damaged' => $charged > 0 && $charged <= $deposit->amount_cents,
            'lost' => $charged === $deposit->amount_cents,
        }, 422, 'El cargo no corresponde a la condición y al importe retenido.');
        $reason = 'Cierre de garantía de renta: '.$data['condition'].'.';

        try {
            $closed = $charged > 0
                ? $holds->settleRentalDeposit($deposit, $this->recipient(), $charged, $key, $actor, $reason)
                : $holds->release($deposit, $key, $actor, $reason);
        } catch (InvalidArgumentException $error) {
            return $this->conflict($request, $error->getMessage());
        }

        return $this->ok($request, [
            'rental_id' => $rental,
            'hold_id' => strtolower($closed->public_id),
            'transaction_id' => strtolower($closed->closing_transaction_id),
            'charged_cents' => $charged,
            'released_cents' => $closed->amount_cents - $charged,
            'status' => $closed->status->value,
        ]);
    }

    private function pilotActor(Request $request, string $operation): string
    {
        // The operator's institutional authority is pending. This pilot can only use the isolated SQL test database.
        abort_unless(app()->environment('testing') && config('financial_rental_deposit_pilot.enabled') === true
            && (new Wallet)->getConnection()->getDatabaseName() === 'campus_digital_financial_testing', 404);
        $actor = $this->authenticatedActor($request);
        $clientId = $request->attributes->get('oauth_client_id');
        abort_unless($clientId === config('financial_rental_deposit_pilot.client_id')
            && app(OAuthTokenService::class)->activeClientHasScope($clientId, 'financial:rental-deposit:'.$operation), 403);

        return $actor;
    }

    private function key(Request $request, string $rental, string $action): string
    {
        $key = 'rental:'.$rental.':deposit-'.$action;
        abort_unless($request->header('Idempotency-Key') === $key, 422, 'La clave debe corresponder a la renta y operación.');

        return $key;
    }

    private function recipient(): Wallet
    {
        $id = config('financial_library_fines.recipient_wallet_id');
        abort_unless(is_string($id) && Str::isUuid($id) && config('financial_library_fines.recipient_organization_id') === 'services',
            503, 'Falta configurar la wallet de prueba de Servicios al Estudiante.');

        return Wallet::where('public_id', $id)->where('owner_type', 'ORGANIZATION')->where('owner_id', 'services')
            ->where('type', WalletType::ORGANIZACION->value)->where('status', WalletStatus::ACTIVA->value)
            ->where('currency', 'MXN')->first() ?? abort(503, 'La wallet de Servicios al Estudiante no está habilitada.');
    }
}
