<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Integrations\Team4\PaymentEventPayload;
use App\Domains\Financial\Models\MarketplacePaymentRequest;
use App\Domains\Financial\Models\Wallet;
use App\Services\IdentityService;
use App\Services\OAuthTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Preparation by a configured merchant is not consent. Only a native browser confirmation can debit. */
class MarketplaceCheckoutService
{
    public function __construct(private readonly IdentityService $identity, private readonly OAuthTokenService $oauth) {}

    public function enabled(): bool
    {
        return (bool) config('financial_marketplace.enabled', false);
    }

    private function enabledOrFail(): void
    {
        abort_unless($this->enabled(), 503, 'La confirmacion de compras no esta habilitada.');
    }

    public function merchant(string $client, string $business, string $scope = 'financial:purchase:prepare'): array
    {
        $this->enabledOrFail();
        $clients = config('financial_marketplace.clients', []);
        $entry = is_array($clients) ? ($clients[$client] ?? null) : null;
        abort_unless(is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== ''
            && is_array($entry['businesses'] ?? null) && is_string($entry['businesses'][$business] ?? null)
            && $entry['businesses'][$business] !== '', 403, 'Cliente o negocio no habilitado para compras.');
        abort_unless($this->oauth->activeClientHasScope($client, $scope), 403,
            'El cliente ya no tiene permiso para esta operación.');

        return ['client_name' => $entry['name'], 'business_name' => $entry['businesses'][$business]];
    }

    private function normalize(array $d): array
    {
        abort_unless(isset($d['order_id'],$d['business_id'],$d['amount_cents'],$d['currency']) && is_int($d['amount_cents']) && $d['amount_cents'] > 0
            && is_string($d['description'] ?? '') && mb_strlen($d['description'] ?? '') <= 200, 422, 'Datos de compra no validos.');
        abort_unless(is_string($d['buyer_id'] ?? null) && preg_match('/^[a-fA-F0-9]{24}$/', $d['buyer_id']), 422, 'Se requiere el ID canonico del comprador.');
        $canonical = PaymentEventPayload::canonical([
            'payment_intent_id' => 'prepare-validation', 'order_id' => $d['order_id'], 'business_id' => $d['business_id'],
            'status' => 'PAID', 'amount_cents' => $d['amount_cents'], 'currency' => $d['currency'],
            'occurred_at' => '2026-01-01T00:00:00Z', 'items' => $d['items'] ?? [],
        ]);

        return ['buyer_id' => strtolower($d['buyer_id']), 'order_id' => $canonical['order_id'], 'business_id' => $canonical['business_id'],
            'amount_cents' => $canonical['amount_cents'], 'currency' => 'MXN', 'items' => $canonical['items'], 'description' => $d['description'] ?? ''];
    }

    private function digest(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function originKey(string $client, string $business, string $order): string
    {
        return hash('sha256', json_encode([$client, $business, $order], JSON_THROW_ON_ERROR));
    }

    private function recipient(string $client, string $business, bool $lock = false): Wallet
    {
        $id = config('financial_marketplace.clients', [])[$client]['recipient_wallets'][$business] ?? null;
        abort_unless(is_string($id) && Str::isUuid($id), 503, 'Este negocio no tiene cuenta receptora configurada.');
        $wallet = Wallet::where('public_id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($wallet && $wallet->type === WalletType::NEGOCIO && $wallet->owner_type === 'BUSINESS'
            && $wallet->owner_id === $business && $wallet->currency === 'MXN'
            && $wallet->status === WalletStatus::ACTIVA,
            503, 'La cuenta receptora no está habilitada.');

        return $wallet;
    }

    public function prepare(string $client, string $key, array $input): MarketplacePaymentRequest
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'Clave de idempotencia no valida.');
        $d = $this->normalize($input);
        $this->merchant($client, $d['business_id']);
        abort_unless($this->identity->isEligibleFinancialAccount($d['buyer_id']), 422, 'El comprador no es una cuenta habilitada en Finanzas.');
        $ttl = (int) config('financial_marketplace.ttl_seconds', 600);
        abort_unless($ttl >= 60 && $ttl <= 1800, 503, 'Vigencia de compras no valida.');
        $d['recipient_wallet_id'] = strtolower($this->recipient($client, $d['business_id'])->public_id);
        $reservationExpiry = $input['reservation_expires_at'] ?? null;
        abort_if($d['items'] !== [] && $reservationExpiry === null, 422, 'Se requiere la vigencia de la reserva.');
        $d['reservation_expires_at'] = $reservationExpiry === null ? null : CarbonImmutable::parse($reservationExpiry)->toISOString();
        $hash = $this->digest($d);
        $keyHash = hash('sha256', json_encode([$client, $key], JSON_THROW_ON_ERROR));
        $origin = $this->originKey($client, $d['business_id'], $d['order_id']);

        return DB::connection('sqlsrv')->transaction(function () use ($d, $hash, $keyHash, $origin, $client, $ttl) {
            $existing = MarketplacePaymentRequest::where('key_hash', $keyHash)->first()
                ?? MarketplacePaymentRequest::where('origin_key', $origin)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ConflictHttpException('El pedido o la clave ya tienen otros datos.');
                }

                return $existing;
            }

            $expiresAt = now()->addSeconds($ttl)->startOfSecond();
            if ($d['reservation_expires_at'] !== null) {
                $expiresAt = $expiresAt->min(CarbonImmutable::parse($d['reservation_expires_at'])->startOfSecond());
            }
            abort_if($expiresAt->lte(now()), 409, 'La reserva ya expiró.');

            $created = MarketplacePaymentRequest::create(['public_id' => (string) Str::uuid(), 'client_id' => $client,
                'buyer_id' => $d['buyer_id'], 'business_id' => $d['business_id'], 'order_id' => $d['order_id'],
                'origin_key' => $origin, 'key_hash' => $keyHash, 'request_hash' => $hash, 'payload' => $d,
                'amount_cents' => $d['amount_cents'], 'currency' => 'MXN', 'expires_at' => $expiresAt, 'status' => 'PENDING']);
            // Return the persisted SQL representation, including datetime precision, on first response and replays.
            $created->refresh();
            $created->wasRecentlyCreated = true;

            return $created;
        });
    }

    public function status(MarketplacePaymentRequest $row): string
    {
        return $row->status === 'PENDING' && now()->gte($row->expires_at) ? 'EXPIRED' : $row->status;
    }

    public function partnerResult(MarketplacePaymentRequest $row): array
    {
        return ['payment_request_id' => strtolower($row->public_id), 'order_id' => $row->order_id, 'business_id' => $row->business_id,
            'status' => $this->status($row), 'amount_cents' => $row->amount_cents, 'currency' => $row->currency,
            'payment_intent_id' => $row->purchase_payment_id ? strtolower($row->purchase_payment_id) : null,
            'expires_at' => $row->expires_at->toISOString(),
            'confirmation_path' => '/finanzas/compras/solicitudes/'.strtolower($row->public_id)];
    }

    public function owned(Request $request, string $id): MarketplacePaymentRequest
    {
        return MarketplacePaymentRequest::where('public_id', $id)->where('buyer_id', (string) $request->user()->getKey())->firstOrFail();
    }

    public function session(Request $request): void
    {
        $this->enabledOrFail();
        abort_unless($this->identity->hasActiveAccountSession(
            (string) $request->user()->getKey(), $request->session()->get('cd_session_id')
        ), 403, 'La cuenta o la sesión de Identidad no está vigente.');
    }

    public function passwordRecent(Request $request): bool
    {
        $max = (int) config('financial_marketplace.password_max_age_seconds', 300);
        abort_unless($max >= 60 && $max <= 900, 503, 'Configuracion de confirmacion no valida.');
        $at = $request->session()->get('auth.password_confirmed_at');

        return is_int($at) && $at <= now()->timestamp && $at >= now()->timestamp - $max;
    }

    public function reviewToken(Request $request, MarketplacePaymentRequest $row): string
    {
        abort_unless(is_string(config('app.key')) && strlen(config('app.key')) >= 32, 503, 'Clave de aplicacion no valida.');

        return hash_hmac('sha256', strtolower($row->public_id).'|'.$row->request_hash.'|'.(string) $request->user()->getKey().'|'.$request->session()->getId().'|'.$request->session()->get('cd_session_id'), (string) config('app.key'));
    }

    public function confirm(Request $request, string $id, string $token): MarketplacePaymentRequest
    {
        $this->session($request);

        return DB::connection('sqlsrv')->transaction(function () use ($request, $id, $token) {
            $row = MarketplacePaymentRequest::where('public_id', $id)->where('buyer_id', (string) $request->user()->getKey())->lockForUpdate()->firstOrFail();
            $this->merchant($row->client_id, $row->business_id);
            abort_unless(hash_equals($this->reviewToken($request, $row), $token), 409, 'Vuelve a abrir el detalle y revisa la compra.');
            abort_unless($this->passwordRecent($request), 409, 'Confirma tu contrasena antes de autorizar el pago.');
            if ($row->status === 'PAID') {
                return $row;
            }
            abort_unless($this->status($row) === 'PENDING', 409, 'La solicitud ya no se puede pagar.');
            $wallet = Wallet::where('owner_type', 'USER')->where('owner_id', (string) $request->user()->getKey())
                ->where('type', WalletType::USUARIO->value)->where('currency', $row->currency)->lockForUpdate()->first();
            abort_unless($wallet, 409, 'No tienes una wallet habilitada para esta moneda.');
            $recipient = $this->recipient($row->client_id, $row->business_id, lock: true);
            $d = $this->normalize($row->payload) + [
                'recipient_wallet_id' => strtolower($recipient->public_id),
                'reservation_expires_at' => $row->payload['reservation_expires_at'] ?? null,
            ];
            abort_unless(hash_equals($row->request_hash, $this->digest($d)) && $d['buyer_id'] === $row->buyer_id
                && $d['business_id'] === $row->business_id && $d['order_id'] === $row->order_id
                && $d['amount_cents'] === $row->amount_cents && $d['currency'] === $row->currency, 409, 'Los datos de la solicitud requieren revision.');
            // Revalidate after waiting for the wallet lock. SQL and Mongo do not share a distributed transaction.
            $this->session($request);
            $this->merchant($row->client_id, $row->business_id);
            abort_unless($this->status($row) === 'PENDING' && $this->passwordRecent($request), 409, 'La solicitud o la confirmacion de contrasena expiro.');
            $result = app(PurchasePaymentService::class)->payWithWallet($wallet, $d['amount_cents'], 'marketplace-confirm:'.strtolower($row->public_id),
                $d['business_id'], null, ['order_id' => $d['order_id'], 'business_id' => $d['business_id'], 'items' => $d['items']], $recipient);
            $row->status = 'PAID';
            $row->purchase_payment_id = $result['purchase_payment_id'];
            $row->confirmed_at = now();
            $row->audit_context = ['channel' => 'WEB', 'actor_id' => 'user:'.$row->buyer_id, 'client_id' => $row->client_id,
                'identity_session_id' => $request->session()->get('cd_session_id'),
                'native_session_hash' => hash('sha256', $request->session()->getId()), 'ip' => $request->ip(),
                'password_confirmed_at' => $request->session()->get('auth.password_confirmed_at')];
            $row->save();

            return $row;
        });
    }

    public function lookupByOrder(string $client, string $business, string $order): MarketplacePaymentRequest
    {
        $this->merchant($client, $business, 'financial:purchase:read');

        return MarketplacePaymentRequest::where('origin_key', $this->originKey($client, $business, $order))->firstOrFail();
    }

    public function cancelByOrder(string $client, string $business, string $order): MarketplacePaymentRequest
    {
        PaymentEventPayload::identifier($business);
        PaymentEventPayload::identifier($order);
        $this->merchant($client, $business, 'financial:purchase:cancel');
        $origin = $this->originKey($client, $business, $order);

        return DB::connection('sqlsrv')->transaction(function () use ($origin) {
            // Serialize with buyer confirmation on the same request, using the exact original tuple.
            $row = MarketplacePaymentRequest::where('origin_key', $origin)->lockForUpdate()->firstOrFail();
            if ($row->status === 'PENDING') {
                $row->status = $this->status($row) === 'EXPIRED' ? 'EXPIRED' : 'CANCELLED';
                $row->save();
            }

            return $row;
        });
    }

    public function cancel(Request $request, string $id): MarketplacePaymentRequest
    {
        $this->session($request);

        return DB::connection('sqlsrv')->transaction(function () use ($request, $id) {
            $row = MarketplacePaymentRequest::where('public_id',$id)->where('buyer_id',(string) $request->user()->getKey())->lockForUpdate()->firstOrFail();
            if ($row->status === 'CANCELLED') {
                return $row;
            }
            abort_unless($this->status($row) === 'PENDING',409,'La solicitud ya no se puede cancelar.');
            $row->status = 'CANCELLED';
            $row->save();

            return $row;
        });
    }
}
