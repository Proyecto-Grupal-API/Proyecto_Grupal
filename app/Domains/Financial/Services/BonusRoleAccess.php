<?php
namespace App\Domains\Financial\Services;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use Illuminate\Auth\Access\AuthorizationException;
final class BonusRoleAccess
{
    public function __construct(private readonly ScopedCapabilityAuthorization $authorization) {}
    public function allows(string $userId, string $capability, ?BonusIssuerContext $context = null): bool
    { $context ??= new BonusIssuerContext(); return $this->authorization->authorized($userId, $capability, $context->scopeType, $context->scopeId); }
    public function canIssue(string $userId, BonusType $type, BonusIssuerContext $context): bool
    { return $this->allows($userId, 'bonus.issue.'.$type->value, $context); }
    public function requireIssue(string $userId, BonusType $type, BonusIssuerContext $context): void
    { if (!$this->canIssue($userId, $type, $context)) { throw new AuthorizationException('No tienes autorización para este tipo de bono en ese contexto.'); } }
    public function has(string $userId, string $capability): bool { return $this->authorization->contexts($userId, $capability) !== []; }
    public function options(string $userId): array
    {
        $options = [];
        foreach (BonusType::cases() as $type) {
            foreach ($this->authorization->contexts($userId, 'bonus.issue.'.$type->value) as $context) {
                $key = json_encode($context); $options[$key] ??= [...$context, 'types' => []]; $options[$key]['types'][] = $type->value;
            }
        }
        return array_values($options);
    }
    public function query(string $userId, string $action = 'bonus.view')
    {
        $query = Bonus::query();
        if ($this->allows($userId, $action.'.all')) { return $query; }
        $contexts = $this->authorization->contexts($userId, $action);
        return $query->where(function ($q) use ($contexts) {
            $q->whereRaw('1 = 0');
            foreach ($contexts as $context) {
                $q->orWhere(function ($part) use ($context) {
                    $part->where('issuer_scope_type', $context['scope_type'])->where('issuer_scope_id', $context['scope_id']);
                });
            }
        });
    }
    public function canCancel(string $userId, Bonus $bonus): bool
    {
        return $this->allows($userId, 'bonus.cancel.all') || $this->allows($userId, 'bonus.cancel', new BonusIssuerContext($bonus->issuer_scope_type, $bonus->issuer_scope_id));
    }
    public function requireCancel(string $userId, Bonus $bonus): void
    { if (!$this->canCancel($userId, $bonus)) { throw new AuthorizationException('No tienes permiso para cancelar bonos de ese contexto.'); } }
}
