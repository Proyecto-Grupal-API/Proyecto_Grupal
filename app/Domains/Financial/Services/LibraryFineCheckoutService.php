<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LibraryFinePaymentRequest;
use App\Domains\Financial\Models\Wallet;
use App\Services\IdentityService;
use App\Services\OAuthTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LibraryFineCheckoutService
{
    public function __construct(
        private readonly IdentityService $identity,
        private readonly OAuthTokenService $oauth,
        private readonly WalletCheckoutConsent $consent,
        private readonly FinancialLimitService $limits,
        private readonly LedgerService $ledger,
    ) {}

    public function authorizeClient(?string $client, string $action): string
    {
        $this->ensureEnabled();
        $allowed = config('financial_library_fines.client_id');
        abort_unless(is_string($allowed) && $allowed !== '' && $client === $allowed
            && $this->oauth->activeClientHasScope($allowed, 'financial:library-fine:'.$action), 403);

        return $allowed;
    }

    public function prepare(string $client, string $fine, string $student, int $amount): LibraryFinePaymentRequest
    {
        $row = LibraryFinePaymentRequest::where('source_reference', $fine)->first();
        if (! $row) {
            abort_unless($this->identity->isEligibleFinancialAccount($student), 422, 'La cuenta del estudiante no está habilitada.');
            $recipient = $this->recipientQuery()->first();
            abort_unless($recipient, 503, 'La cuenta receptora institucional no está habilitada.');
            $row = LibraryFinePaymentRequest::firstOrCreate(['source_reference' => $fine], [
                'public_id' => (string) Str::uuid(), 'client_id' => $client, 'status' => 'PENDING',
                'student_id' => $student, 'amount_cents' => $amount, 'recipient_wallet_id' => $recipient->public_id,
            ]);
        }
        abort_unless($row->client_id === $client && $row->student_id === $student && $row->amount_cents === $amount
            && $row->status !== 'CANCELLED', 409, 'La multa ya está vinculada a otra solicitud o fue cancelada.');

        return $row;
    }

    public function lookup(string $client, string $fine): LibraryFinePaymentRequest
    {
        return LibraryFinePaymentRequest::where('client_id', $client)->where('source_reference', $fine)->firstOrFail();
    }

    public function cancel(string $client, string $fine): LibraryFinePaymentRequest
    {
        // Keep a permanent record even when cancellation wins before preparation.
        $row = LibraryFinePaymentRequest::firstOrCreate(['source_reference' => $fine], [
            'public_id' => (string) Str::uuid(), 'client_id' => $client, 'status' => 'CANCELLED',
        ]);

        return DB::connection('sqlsrv')->transaction(function () use ($client, $row) {
            $current = LibraryFinePaymentRequest::whereKey($row->getKey())->where('client_id', $client)->lockForUpdate()->firstOrFail();
            if ($current->status === 'PENDING') {
                $current->update(['status' => 'CANCELLED']);
            }

            return $current;
        }, 3);
    }

    public function result(LibraryFinePaymentRequest $row): array
    {
        return [
            'source_reference' => $row->source_reference, 'concept' => 'library_fine',
            'student_id' => $row->student_id, 'amount_cents' => $row->amount_cents, 'currency' => 'MXN',
            'status' => $row->status, 'payment_id' => $row->transaction_id ? strtolower($row->transaction_id) : null,
            'confirmation_path' => $row->student_id
                ? route('financial.library-fines.show', ['paymentRequest' => strtolower($row->public_id)], absolute: false) : null,
        ];
    }

    public function review(Request $request, string $id): array
    {
        $this->ensureEnabled();
        $row = $this->studentRequest($request, $id)->firstOrFail();
        $this->consent->ensureActiveSession($request);

        return [
            'paymentRequest' => $this->result($row),
            'passwordRecent' => $this->consent->passwordRecent($request),
            'reviewToken' => $this->reviewToken($request, $row),
        ];
    }

    public function confirm(Request $request, string $id, string $token): LibraryFinePaymentRequest
    {
        $this->ensureEnabled();
        $this->consent->ensureActiveSession($request);

        return DB::connection('sqlsrv')->transaction(function () use ($request, $id, $token) {
            $row = $this->studentRequest($request, $id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($this->reviewToken($request, $row), $token), 409, 'Vuelve a revisar el detalle de la multa.');
            abort_unless($this->consent->passwordRecent($request), 409, 'Confirma tu contraseña antes de pagar.');
            if ($row->status === 'CONFIRMED') {
                return $row;
            }
            abort_unless($row->status === 'PENDING', 409, 'La solicitud ya no se puede pagar.');

            $wallet = Wallet::where('owner_type', 'USER')->where('owner_id', $row->student_id)
                ->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->first();
            abort_unless($wallet, 409, 'No tienes una wallet en MXN.');
            $locked = Wallet::whereIn('public_id', [$wallet->public_id, $row->recipient_wallet_id])
                ->orderBy('public_id')->lockForUpdate()->get()->keyBy(fn (Wallet $item) => strtolower($item->public_id));
            $payer = $locked->get(strtolower($wallet->public_id));
            abort_unless($payer?->status === WalletStatus::ACTIVA && $payer->currency === 'MXN'
                && $payer->owner_type === 'USER' && $payer->owner_id === $row->student_id
                && $payer->type === WalletType::USUARIO, 409, 'Tu wallet no está habilitada.');
            $recipient = $this->recipientQuery()->where('public_id', $row->recipient_wallet_id)->first();
            abort_unless($recipient, 409, 'La cuenta receptora cambió o ya no está habilitada.');

            // The lock also serializes the check of cumulative spending limits for this wallet.
            $evaluation = $this->limits->evaluate($payer, MovementType::PAGO, $row->amount_cents, actions: [LimitAction::BLOQUEAR]);
            abort_if($evaluation->isBlocked(), 409, 'El importe supera el límite de pago de tu wallet.');
            abort_if($payer->available_balance_cents < $row->amount_cents, 409, 'Saldo insuficiente.');
            $this->consent->ensureActiveSession($request);
            abort_unless($this->consent->passwordRecent($request), 409, 'La confirmación de contraseña venció.');
            $this->authorizeClient($row->client_id, 'prepare');

            $key = 'library-fine:'.strtolower($row->public_id);
            abort_if(FinancialTransaction::where('idempotency_key', $key)->exists(), 409, 'El registro contable requiere revisión.');
            $transaction = $this->ledger->transfer($payer, $recipient, $row->amount_cents, $key, 'LIBRARY_FINE',
                $row->source_reference, [
                    'student_id' => $row->student_id, 'client_id' => $row->client_id, 'channel' => 'WEB',
                    'identity_session_id' => $request->session()->get('cd_session_id'),
                    'native_session_hash' => hash('sha256', $request->session()->getId()),
                    'password_confirmed_at' => $request->session()->get('auth.password_confirmed_at'),
                ], MovementType::PAGO, MovementType::COBRO_SERVICIO);
            abort_unless($transaction->wasRecentlyCreated, 409, 'El registro contable requiere revisión.');
            $row->update(['status' => 'CONFIRMED', 'transaction_id' => $transaction->public_id]);

            return $row;
        }, 3);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('financial_library_fines.enabled', false), 503, 'El pago de multas no está habilitado.');
    }

    private function studentRequest(Request $request, string $id): Builder
    {
        return LibraryFinePaymentRequest::where('public_id', $id)->where('student_id', (string) $request->user()->getKey());
    }

    private function reviewToken(Request $request, LibraryFinePaymentRequest $row): string
    {
        $digest = hash('sha256', json_encode([
            'library_fine', $row->source_reference, $row->student_id, $row->amount_cents,
            'MXN', strtolower($row->recipient_wallet_id), $row->client_id,
        ], JSON_THROW_ON_ERROR));

        return $this->consent->reviewToken($request, $row->public_id, $digest);
    }

    private function recipientQuery(): Builder
    {
        $walletId = config('financial_library_fines.recipient_wallet_id');
        $organization = config('financial_library_fines.recipient_organization_id');
        abort_unless(is_string($walletId) && Str::isUuid($walletId) && is_string($organization) && trim($organization) !== '', 503,
            'Falta configurar la cuenta institucional de Servicios al Estudiante.');

        return Wallet::where('public_id', $walletId)->where('owner_type', 'ORGANIZATION')->where('owner_id', $organization)
            ->where('type', WalletType::ORGANIZACION->value)->where('status', WalletStatus::ACTIVA->value)->where('currency', 'MXN');
    }
}
