<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\StudentTransferKind;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferPolicy;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Domains\Financial\Data\TransferAuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
/** Domain execution only. Web/API controllers must verify an explicit, server-bound confirmation. */
class StudentTransferService
{
    public function __construct(private readonly TransferAuthorizationProvider $authorization,
        private readonly StudentTransferPolicyService $policies, private readonly LedgerService $ledger,
        private readonly FinancialLimitService $limits, private readonly TransactionAlertService $alerts,
        private readonly FinancialJobLock $locks, private readonly TransferAccountProvider $accounts) {}
    public function execute(string $senderId, Wallet $source, Wallet $destination, int $amountCents,
        StudentTransferKind $kind, ?string $concept, string $idempotencyKey, ?callable $onCompleted = null, ?TransferAuditContext $audit = null): StudentTransfer
    {
        $audit ??= TransferAuditContext::internal();
        $senderId = strtolower($senderId); $key = strtolower(trim($idempotencyKey)); $concept = trim($concept ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $senderId) || !preg_match('/^[a-z0-9._:-]{1,255}$/', $key)
            || $amountCents < 1 || $amountCents > 9007199254740991 || mb_strlen($concept) > 255) {
            throw new InvalidArgumentException('Datos de transferencia no válidos.');
        }
        $source = Wallet::where('public_id', $source->public_id)->firstOrFail();
        $destination = Wallet::where('public_id', $destination->public_id)->firstOrFail();
        $this->authorize($senderId, $source, $destination);
        $hash = hash('sha256', json_encode([$senderId, strtolower($source->public_id), strtolower($destination->public_id), $amountCents, $kind->value, $concept], JSON_THROW_ON_ERROR));
        $lockKey = 'student-transfer:' . hash('sha256', $key); $token = $this->locks->acquire($lockKey, 180);
        if (!$token) { throw new InvalidArgumentException('La solicitud está en proceso; reintenta con la misma clave.'); }
        try {
            try {
                return DB::connection('sqlsrv')->transaction(function () use ($senderId, $source, $destination, $amountCents, $kind, $concept, $key, $hash, $lockKey, $token, $onCompleted, $audit) {
                    $this->locks->assertOwned($lockKey, $token, 180);
                    $existing = StudentTransfer::where('idempotency_key', $key)->first();
                    if ($existing) {
                        if (!hash_equals($existing->request_hash, $hash)) { throw new InvalidArgumentException('La clave ya pertenece a otra solicitud.'); }
                        return $existing;
                    }
                    // Common policy lock serializes all 2.6 executions and policy edits; wallet locks protect against other financial paths.
                    $policy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->lockForUpdate()->firstOrFail();
                    $this->policies->validate($this->policies->snapshot($policy));
                    if (!$policy->enabled) { throw new InvalidArgumentException('Las transferencias están deshabilitadas.'); }
                    $senderWalletIds = Wallet::where('owner_type', 'USER')->where('owner_id', $senderId)->where('type', WalletType::USUARIO->value)->where('currency', 'MXN')->pluck('public_id');
                    $wallets = Wallet::whereIn('public_id', $senderWalletIds->push($destination->public_id)->unique())->orderBy('public_id')->lockForUpdate()->get()->keyBy(fn ($w) => strtolower($w->public_id));
                    $from = $wallets->get(strtolower($source->public_id)); $to = $wallets->get(strtolower($destination->public_id));
                    if (!$from || !$to) { throw new InvalidArgumentException('No fue posible localizar las wallets.'); }
                    $this->authorize($senderId, $from, $to); $this->validateWallets($from, $to);
                    $this->activeAccount($senderId); $this->activeAccount(strtolower($to->owner_id));
                    if ($amountCents < $policy->minimum_cents || $amountCents > $policy->maximum_cents) { throw new InvalidArgumentException('El monto no cumple el mínimo o máximo por operación.'); }
                    $at = CarbonImmutable::now($policy->business_timezone);
                    foreach (['daily_cents' => $at->startOfDay(), 'monthly_cents' => $at->startOfMonth()] as $field => $start) {
                        $end = $field === 'daily_cents' ? $start->addDay() : $start->addMonth();
                        $usageWalletIds = $wallets->filter(fn ($w) => $w->owner_type === 'USER' && strtolower($w->owner_id) === $senderId)->pluck('public_id');
                        $usage = -(int) LedgerEntry::whereIn('wallet_id', $usageWalletIds)
                            ->where('movement_type', MovementType::TRANSFERENCIA_SALIDA->value)->where('amount_cents', '<', 0)
                            ->where('created_at', '>=', $start->setTimezone(config('app.timezone')))
                            ->where('created_at', '<', $end->setTimezone(config('app.timezone')))->sum('amount_cents');
                        // Both kinds share the quota; refunds do not release historical sent amounts.
                        if ($usage > $policy->$field - $amountCents) { throw new InvalidArgumentException('Se excede el límite ' . ($field === 'daily_cents' ? 'diario.' : 'mensual.')); }
                    }
                    if ($from->available_balance_cents < $amountCents || $from->available_balance_cents < 0 || $from->held_balance_cents < 0
                        || $to->available_balance_cents < 0 || $to->held_balance_cents < 0
                        || $to->available_balance_cents > 9007199254740991 - $amountCents) { throw new InvalidArgumentException('Saldo disponible insuficiente o inconsistente.'); }
                    $evaluation = $this->limits->evaluate($from, MovementType::TRANSFERENCIA_SALIDA, $amountCents, actions: [LimitAction::BLOQUEAR]);
                    if ($evaluation->isBlocked()) { throw new FinancialLimitExceededException($evaluation); }
                    $id = (string) Str::uuid(); $ledgerKey = 'student-transfer:' . hash('sha256', $key);
                    if (FinancialTransaction::where('idempotency_key', $ledgerKey)->exists()) { throw new InvalidArgumentException('La clave contable ya fue utilizada.'); }
                    $snapshot = $this->policies->snapshot($policy);
                    $transaction = $this->ledger->transfer($from, $to, $amountCents, $ledgerKey,
                        $kind === StudentTransferKind::REGALO ? 'STUDENT_GIFT' : 'STUDENT_TRANSFER', $id,
                        ['student_transfer_id' => $id, 'transfer_kind' => $kind->value, 'actor_id' => 'user:' . $senderId,
                            'sender_id' => $senderId, 'recipient_id' => strtolower($to->owner_id), 'concept' => $concept === '' ? null : $concept,
                            'policy' => $snapshot, 'audit_context' => $audit->toArray()]);
                    $this->locks->assertOwned($lockKey, $token, 180);
                    $transfer = StudentTransfer::create(['public_id' => $id, 'idempotency_key' => $key, 'request_hash' => $hash,
                        'source_wallet_id' => $from->public_id, 'destination_wallet_id' => $to->public_id,
                        'sender_id' => $senderId, 'recipient_id' => strtolower($to->owner_id), 'actor_id' => 'user:' . $senderId,
                        'kind' => $kind, 'amount_cents' => $amountCents, 'currency' => 'MXN', 'concept' => $concept === '' ? null : $concept,
                        'status' => TransactionStatus::COMPLETADA->value, 'financial_transaction_id' => $transaction->public_id,
                        'audit_context' => $audit->toArray(), 'policy_version' => $policy->version, 'policy_snapshot' => $snapshot, 'completed_at' => $at->setTimezone(config('app.timezone'))]);
                    if ($onCompleted !== null) { $onCompleted($transfer); }
                    return $transfer;
                });
            } catch (FinancialLimitExceededException $e) {
                // Persist 2.10 evidence AFTER the rejected SQL transaction has rolled back.
                $alert = $this->alerts->recordBlocked($source, $e->evaluation, 'STUDENT_TRANSFER_ATTEMPT', null, 'user:' . $senderId);
                throw new FinancialLimitExceededException($e->evaluation, $alert->public_id, $alert->correlation_id);
            }
        } finally { $this->locks->release($lockKey, $token); }
    }
    private function authorize(string $senderId, Wallet $from, Wallet $to): void
    {
        if ($from->owner_type !== 'USER' || strtolower($from->owner_id) !== $senderId
            || !$this->authorization->canSend($senderId, $from)
            || !$this->authorization->canReceive(strtolower($to->owner_id), $to)) {
            throw new AuthorizationException('No se autoriza el envío o la recepción.');
        }
    }
    private function validateWallets(Wallet $from, Wallet $to): void
    {
        if (strtolower($from->public_id) === strtolower($to->public_id) || strtolower($from->owner_id) === strtolower($to->owner_id)
            || $from->type !== WalletType::USUARIO || $to->type !== WalletType::USUARIO || $to->owner_type !== 'USER'
            || $from->status !== WalletStatus::ACTIVA || $to->status !== WalletStatus::ACTIVA
            || $from->currency !== 'MXN' || $to->currency !== 'MXN') { throw new InvalidArgumentException('Se requieren dos wallets personales activas, distintas y en MXN.'); }
    }
    private function activeAccount(string $userId): void
    {
        $this->accounts->assertActive($userId);
    }
}
