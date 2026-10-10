<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Enums\StudentTransferKind;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferConfirmation;
use App\Domains\Financial\Models\StudentTransferPolicy;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
class StudentTransferConfirmationService
{
    public function __construct(private readonly TransferAuthorizationProvider $authorization,
        private readonly TransferRecipientProvider $recipients, private readonly StudentTransferPolicyService $policies,
        private readonly StudentTransferService $transfers, private readonly FinancialJobLock $locks) {}
    public function prepare(string $senderId, string $method, string $value, int $amount, StudentTransferKind $kind,
        ?string $concept, string $key, ?string $ip = null): StudentTransferConfirmation
    {
        $senderId = strtolower($senderId); $key = $this->key($key); $concept = trim($concept ?? ''); $value = trim($value);
        $source = $this->wallet($senderId); $this->canSend($senderId, $source);
        if ($amount < 1 || $amount > 9007199254740991 || mb_strlen($concept) > 255 || !in_array($method, ['USER_ID', 'ENROLLMENT', 'QR'], true) || $value === '' || mb_strlen($value) > 500) {
            throw new InvalidArgumentException('Datos de solicitud no válidos.');
        }
        $hash = hash('sha256', json_encode([$senderId, $method, $value, $amount, $kind->value, $concept], JSON_THROW_ON_ERROR));
        return $this->locked('preview:' . hash('sha256', $key), function ($lockKey, $token) use ($senderId, $source, $method, $value, $amount, $kind, $concept, $key, $hash, $ip) {
            $existing = StudentTransferConfirmation::where('request_key', $key)->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash, $hash)) { throw new InvalidArgumentException('La clave ya pertenece a otra solicitud.'); }
                return $existing;
            }
            // QR verification belongs to Module 1; never duplicate its token rules or store the raw code here.
            $recipient = $this->recipients->resolve($method, $value, $senderId, $ip);
            if (!isset($recipient['user_id'], $recipient['name']) || !preg_match('/^[a-f0-9]{24}$/i', $recipient['user_id'])) {
                throw new InvalidArgumentException('No se pudo identificar el destinatario.');
            }
            $recipientId = strtolower($recipient['user_id']); $destination = $this->wallet($recipientId);
            if ($recipientId === $senderId) { throw new InvalidArgumentException('El destinatario debe ser otra persona.'); }
            if (!$this->authorization->canReceive($recipientId, $destination)) { throw new AuthorizationException('El destinatario no tiene habilitada la recepción.'); }
            foreach ([$senderId, $recipientId] as $id) { $u = User::find($id); if (!$u || !$u->email_verified_at || $u->account_activation_pending) { throw new InvalidArgumentException('Se requieren cuentas activas y verificadas.'); } }
            return DB::connection('sqlsrv')->transaction(function () use ($senderId, $source, $destination, $recipientId, $recipient, $method, $amount, $kind, $concept, $key, $hash, $lockKey, $token, $ip) {
                $this->locks->assertOwned($lockKey, $token, 180);
                $p = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->lockForUpdate()->firstOrFail(); $snapshot = $this->policies->snapshot($p); $this->policies->validate($snapshot);
                if (!$p->enabled || $amount < $p->minimum_cents || $amount > $p->maximum_cents) { throw new InvalidArgumentException('El monto o la habilitación no cumplen la política vigente.'); }
                if ($source->status !== WalletStatus::ACTIVA || $destination->status !== WalletStatus::ACTIVA || $source->available_balance_cents < $amount) { throw new InvalidArgumentException('Wallet inactiva o saldo disponible insuficiente.'); }
                return StudentTransferConfirmation::create(['public_id' => (string) Str::uuid(), 'request_key' => $key, 'request_hash' => $hash,
                    'sender_id' => $senderId, 'recipient_id' => $recipientId, 'source_wallet_id' => $source->public_id, 'destination_wallet_id' => $destination->public_id,
                    'kind' => $kind->value, 'amount_cents' => $amount, 'concept' => $concept ?: null, 'identity_method' => $method,
                    'recipient_snapshot' => ['user_id' => $recipientId, 'name' => $recipient['name'], 'enrollment_number' => $recipient['enrollment_number'] ?? null],
                    'policy_snapshot' => $snapshot, 'status' => 'PENDIENTE', 'duration_seconds' => $p->confirmation_seconds,
                    'expires_at' => now()->addSeconds($p->confirmation_seconds), 'requested_ip' => $ip,
                    'correlation_id' => app(\App\Domains\Financial\Support\FinancialCorrelation::class)->id()]);
            });
        });
    }
    public function confirm(string $senderId, string $id, string $key, ?string $ip = null): StudentTransfer
    {
        $key = $this->key($key);
        return $this->locked('confirm-key:' . $key, function ($lockKey, $token) use ($senderId, $id, $key, $ip) {
            $c = $this->owned($senderId, $id); $source = Wallet::where('public_id', $c->source_wallet_id)->firstOrFail(); $this->canSend($senderId, $source);
            $destination = Wallet::where('public_id', $c->destination_wallet_id)->firstOrFail();
            if (!$this->authorization->canReceive($c->recipient_id, $destination)) { throw new AuthorizationException('La recepción ya no está autorizada.'); }
            if ($c->execution_key !== null && $c->execution_key !== $key) { throw new InvalidArgumentException('Reintenta la confirmación con su clave original.'); }
            if ($c->status === 'COMPLETADA') { return StudentTransfer::where('public_id', $c->student_transfer_id)->firstOrFail(); }
            $this->pending($c);
            // Persist the retry key independently; a failed financial attempt can retry but cannot change its identity.
            DB::connection('sqlsrv')->transaction(function () use ($senderId, $id, $key, $lockKey, $token) {
                $this->locks->assertOwned($lockKey, $token, 180);
                if (StudentTransferConfirmation::where('execution_key', $key)->where('public_id', '!=', $id)->exists()) { throw new InvalidArgumentException('La clave de confirmación pertenece a otra solicitud.'); }
                $locked = $this->owned($senderId, $id, true); $this->pending($locked);
                if ($locked->execution_key !== null && $locked->execution_key !== $key) { throw new InvalidArgumentException('La confirmación ya tiene una clave.'); }
                $locked->execution_key = $key; $locked->save();
            });
            // No outer financial transaction: 2.10 must persist blocked evidence after rollback.
            return $this->transfers->execute($senderId, $source, $destination, $c->amount_cents, StudentTransferKind::from($c->kind), $c->concept,
                'confirmation:' . strtolower($c->public_id), function (StudentTransfer $transfer) use ($senderId, $id, $key, $lockKey, $token, $ip) {
                    $this->locks->assertOwned($lockKey, $token, 180);
                    $locked = $this->owned($senderId, $id, true); $this->pending($locked);
                    if ($locked->execution_key !== $key || strtolower($transfer->recipient_id) !== strtolower($locked->recipient_id)
                        || strtolower($transfer->source_wallet_id) !== strtolower($locked->source_wallet_id)
                        || strtolower($transfer->destination_wallet_id) !== strtolower($locked->destination_wallet_id)) { throw new InvalidArgumentException('La identidad del envío cambió.'); }
                    $locked->status = 'COMPLETADA'; $locked->student_transfer_id = $transfer->public_id; $locked->confirmed_at = now(); $locked->confirmed_ip = $ip; $locked->save();
                });
        });
    }
    public function cancel(string $senderId, string $id): StudentTransferConfirmation
    {
        return DB::connection('sqlsrv')->transaction(function () use ($senderId, $id) {
            $c = $this->owned($senderId, $id, true);
            if ($c->status === 'CANCELADA') { return $c; }
            if ($c->status !== 'PENDIENTE') { throw new InvalidArgumentException('Un envío completado no se puede cancelar.'); }
            $c->status = 'CANCELADA'; $c->cancelled_at = now(); $c->save(); return $c;
        });
    }
    public function owned(string $senderId, string $id, bool $lock = false): StudentTransferConfirmation
    {
        return StudentTransferConfirmation::where('public_id', $id)->where('sender_id', strtolower($senderId))->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }
    public function wallet(string $userId): Wallet
    {
        $matches = Wallet::where('owner_type', 'USER')->where('owner_id', strtolower($userId))->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->limit(2)->get();
        if ($matches->count() !== 1) { throw new InvalidArgumentException('Se requiere una wallet personal MXN identificada de forma única.'); }
        return $matches->first();
    }
    private function canSend(string $id, Wallet $w): void
    {
        if ($w->owner_type !== 'USER' || strtolower($w->owner_id) !== strtolower($id) || !$this->authorization->canSend($id, $w)) { throw new AuthorizationException('No tienes habilitado el envío.'); }
    }
    private function pending(StudentTransferConfirmation $c): void
    {
        if ($c->status !== 'PENDIENTE' || now()->gte($c->expires_at)) { throw new InvalidArgumentException('La confirmación fue cancelada o venció; prepara otra solicitud.'); }
    }
    private function key(string $key): string
    {
        $key = strtolower(trim($key)); if (!preg_match('/^[a-z0-9._:-]{1,255}$/', $key)) { throw new InvalidArgumentException('La clave de idempotencia no es válida.'); } return $key;
    }
    private function locked(string $key, callable $run): mixed
    {
        $key = 'student-confirmation:' . hash('sha256', $key); $token = $this->locks->acquire($key, 180);
        if (!$token) { throw new InvalidArgumentException('La solicitud está en proceso; reintenta con la misma clave.'); }
        try { return $run($key, $token); } finally { $this->locks->release($key, $token); }
    }
}
