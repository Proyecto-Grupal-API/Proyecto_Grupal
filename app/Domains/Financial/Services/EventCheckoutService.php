<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\EventPaymentRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Services\IdentityService;
use App\Services\OAuthTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventCheckoutService
{
    public function __construct(
        private readonly IdentityService $identity,
        private readonly OAuthTokenService $oauth,
        private readonly WalletCheckoutConsent $consent,
        private readonly FinancialLimitService $limits,
        private readonly LedgerService $ledger,
    ) {}

    public function authorize(?string $client, string $action): string
    {
        $this->ensureEnabled();
        $allowed = config('financial_event_payments.client_id');
        abort_unless(is_string($allowed) && $allowed !== '' && $client === $allowed
            && $this->oauth->activeClientHasScope($allowed, 'financial:event-payment:'.$action), 403);

        return $allowed;
    }

    public function prepare(string $client, string $source, array $data): EventPaymentRequest
    {
        unset($data['currency']);
        $row = EventPaymentRequest::where('source_reference', $source)->first();
        if (! $row) {
            $expires = Carbon::parse($data['reservation_expires_at']);
            abort_unless($expires->isFuture() && $expires->lte(now()->addMinutes(15)), 409, 'La vigencia de la reserva no es válida.');
            abort_unless($this->identity->isEligibleFinancialAccount($data['student_id']), 422);
            $mapping = $this->recipient($data['organization_id']);
            $wallet = Wallet::where('public_id', $mapping['wallet_id'])->first();
            $this->checkRecipient($wallet, $data['organization_id']);
            $row = EventPaymentRequest::firstOrCreate(['source_reference' => $source], $data + [
                'public_id' => (string) Str::uuid(), 'client_id' => $client, 'status' => 'PENDING',
                'recipient_wallet_id' => $wallet->public_id,
            ]);
        }
        abort_unless($row->client_id === $client && $row->only(array_keys($data)) === $data
            && $row->status !== 'CANCELLED', 409, 'La referencia se cerró o pertenece a otros datos.');

        return $row;
    }

    public function lookup(string $client, string $source): EventPaymentRequest
    {
        return EventPaymentRequest::where('source_reference', $source)->where('client_id', $client)->firstOrFail();
    }

    public function cancel(string $client, string $source): EventPaymentRequest
    {
        $row = EventPaymentRequest::firstOrCreate(['source_reference' => $source], [
            'public_id' => (string) Str::uuid(), 'client_id' => $client, 'status' => 'CANCELLED',
        ]);

        return DB::connection('sqlsrv')->transaction(function () use ($row, $client) {
            $current = EventPaymentRequest::whereKey($row->getKey())->where('client_id', $client)->lockForUpdate()->firstOrFail();
            if ($current->status === 'PENDING') {
                $current->update(['status' => $this->expired($current) ? 'EXPIRED' : 'CANCELLED']);
            }

            return $current;
        }, 3);
    }

    public function result(EventPaymentRequest $row): array
    {
        return $row->only(['source_reference', 'status', 'student_id', 'event_id', 'organization_id', 'amount_cents', 'reservation_expires_at', 'confirmed_at']) + [
            'currency' => 'MXN', 'request_id' => strtolower($row->public_id),
            'payment_id' => $row->transaction_id ? strtolower($row->transaction_id) : null,
            'confirmation_path' => $row->status === 'PENDING' ? '/finanzas/eventos/solicitudes/'.strtolower($row->public_id) : null,
        ];
    }

    public function review(Request $request, string $id): array
    {
        $this->ensureEnabled();
        $this->consent->ensureActiveSession($request);
        $row = $this->owned($request, $id);
        $result = $this->result($row);
        if ($row->status === 'PENDING' && $this->expired($row)) {
            $result['status'] = 'EXPIRED';
        }

        return ['paymentRequest' => $result, 'reviewToken' => $this->reviewToken($request, $row),
            'passwordRecent' => $this->consent->passwordRecent($request),
            'recipientName' => $this->recipient($row->organization_id)['name']];
    }

    public function confirm(Request $request, string $id, string $token): EventPaymentRequest
    {
        $this->ensureEnabled();
        $this->consent->ensureActiveSession($request);

        return DB::connection('sqlsrv')->transaction(function () use ($request, $id, $token) {
            $row = $this->owned($request, $id, lock: true);
            abort_unless(hash_equals($this->reviewToken($request, $row), $token) && $this->consent->passwordRecent($request), 409,
                'Vuelve a revisar el importe y confirma tu contraseña.');
            if ($row->status === 'CONFIRMED') {
                return $row;
            }
            abort_unless($row->status === 'PENDING' && ! $this->expired($row), 409, 'La reserva ya no admite un cargo.');

            $payer = Wallet::where('owner_type', 'USER')->where('owner_id', $row->student_id)
                ->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->first();
            abort_unless($payer, 409, 'No tienes una wallet en MXN.');
            $wallets = Wallet::whereIn('public_id', [$payer->public_id, $row->recipient_wallet_id])
                ->orderBy('public_id')->lockForUpdate()->get()->keyBy(fn (Wallet $wallet) => strtolower($wallet->public_id));
            $payer = $wallets->get(strtolower($payer->public_id));
            $recipient = $wallets->get(strtolower($row->recipient_wallet_id));
            abort_unless($payer?->status === WalletStatus::ACTIVA && $payer->currency === 'MXN'
                && $payer->owner_type === 'USER' && $payer->owner_id === $row->student_id
                && $payer->type === WalletType::USUARIO, 409, 'Tu wallet no está habilitada.');
            $this->checkRecipient($recipient, $row->organization_id);
            $evaluation = $this->limits->evaluate($payer, MovementType::PAGO, $row->amount_cents, actions: [LimitAction::BLOQUEAR]);
            abort_if($evaluation->isBlocked() || $payer->available_balance_cents < $row->amount_cents, 409, 'Saldo o límite de pago insuficiente.');
            $this->authorize($row->client_id, 'prepare');
            $this->consent->ensureActiveSession($request);
            $confirmedAt = now();
            abort_unless($this->consent->passwordRecent($request) && $confirmedAt->lt(Carbon::parse($row->reservation_expires_at)), 409, 'La confirmación o la reserva venció.');

            $key = 'event-payment:'.strtolower($row->public_id);
            abort_if(FinancialTransaction::where('idempotency_key', $key)->exists(), 409, 'La operación requiere revisión.');
            $transaction = $this->ledger->transfer($payer, $recipient, $row->amount_cents, $key, 'COMMUNITY_EVENT', $row->source_reference, [
                'student_id' => $row->student_id, 'client_id' => $row->client_id, 'event_id' => $row->event_id,
                'organization_id' => $row->organization_id, 'identity_session_id' => $request->session()->get('cd_session_id'),
                'password_confirmed_at' => $request->session()->get('auth.password_confirmed_at'),
            ], MovementType::PAGO, MovementType::COBRO_SERVICIO);
            abort_unless($transaction->wasRecentlyCreated, 409, 'La operación requiere revisión.');
            $row->update(['status' => 'CONFIRMED', 'transaction_id' => $transaction->public_id,
                'confirmed_at' => $confirmedAt->utc()->format('Y-m-d\TH:i:s.v\Z')]);

            return $row;
        }, 3);
    }

    private function owned(Request $request, string $id, bool $lock = false): EventPaymentRequest
    {
        return EventPaymentRequest::where('public_id', $id)->where('student_id', (string) $request->user()->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('financial_event_payments.enabled') === true, 503, 'El cobro de eventos no está habilitado.');
    }

    private function expired(EventPaymentRequest $row): bool
    {
        return now()->gte(Carbon::parse($row->reservation_expires_at));
    }

    private function reviewToken(Request $request, EventPaymentRequest $row): string
    {
        $mapping = $this->recipient($row->organization_id);
        $digest = hash('sha256', json_encode($row->only(['source_reference', 'client_id', 'student_id', 'event_id',
            'organization_id', 'amount_cents', 'reservation_expires_at', 'recipient_wallet_id']) + ['recipient_name' => $mapping['name']], JSON_THROW_ON_ERROR));

        return $this->consent->reviewToken($request, $row->public_id, $digest);
    }

    private function recipient(string $organization): array
    {
        $entry = config('financial_event_payments.recipients', [])[$organization] ?? null;
        abort_unless(is_array($entry) && is_string($entry['wallet_id'] ?? null) && Str::isUuid($entry['wallet_id'])
            && is_string($entry['name'] ?? null) && trim($entry['name']) !== '', 503, 'La organización carece de receptor aprobado.');

        return $entry;
    }

    private function checkRecipient(?Wallet $wallet, string $organization): void
    {
        $entry = $this->recipient($organization);
        abort_unless($wallet && strcasecmp($wallet->public_id, $entry['wallet_id']) === 0
            && $wallet->type === WalletType::ORGANIZACION && $wallet->owner_type === 'ORGANIZATION'
            && $wallet->owner_id === $organization && $wallet->currency === 'MXN' && $wallet->status === WalletStatus::ACTIVA,
            409, 'La cuenta receptora requiere revisión.');
    }
}
