<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashApprovalPolicy;
use App\Domains\Financial\Models\CashApprovalPolicyChange;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
class CashApprovalPolicyService {
    public function snapshot(string $association, string $operation, string $currency): array {
        $policy = CashApprovalPolicy::where('association_id', $association)->where('operation', $operation)->where('currency', $currency)->first();
        return $policy ? $this->data($policy) : ['association_id' => $association, 'operation' => $operation,
            'currency' => $currency, 'enabled' => false, 'threshold_cents' => 1, 'version' => 0];
    }
    public function data(CashApprovalPolicy $policy): array {
        return $policy->only(['association_id', 'operation', 'currency', 'enabled', 'threshold_cents', 'version']);
    }
    public function configure(string $association, string $operation, string $currency, bool $enabled, int $threshold,
        int $version, string $actor, string $reason, string $key): array {
        $cash = app(CashShiftService::class);
        foreach ([$association, $actor, $key] as $text) $cash->text($text);
        $cash->text($reason, 1000);
        abort_unless(app(CashAuthorizationProvider::class)->allows($actor, $association, 'approval_manage'), 403);
        if (! in_array($operation, ['TOPUP', 'WITHDRAWAL'], true) || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || $threshold < 1 || $version < 0) throw new InvalidArgumentException('Política de autorización inválida.');
        $hash = $cash->hash([$association, $operation, $currency, $enabled, $threshold, $version, $actor, trim($reason)]);
        return DB::connection('sqlsrv')->transaction(function () use ($association, $operation, $currency, $enabled, $threshold, $version, $actor, $reason, $key, $hash) {
            $policy = CashApprovalPolicy::where('association_id', $association)->where('operation', $operation)->where('currency', $currency)->lockForUpdate()->first();
            $prior = CashApprovalPolicyChange::where('idempotency_key', trim($key))->first();
            if ($prior) {
                if ($prior->request_hash !== $hash) throw new InvalidArgumentException('La clave pertenece a otro cambio de política.');
                return $prior->after_snapshot;
            }
            if (($policy?->version ?? 0) !== $version) throw new InvalidArgumentException('La política cambió. Actualiza la consulta antes de guardar.');
            $before = $policy ? $this->data($policy) : null;
            $values = ['association_id' => $association, 'operation' => $operation, 'currency' => $currency,
                'enabled' => $enabled, 'threshold_cents' => $threshold, 'version' => $version + 1];
            if ($policy) $policy->fill($values)->save(); else $policy = CashApprovalPolicy::create($values);
            $after = $this->data($policy);
            CashApprovalPolicyChange::create(['cash_approval_policy_id' => $policy->id, 'idempotency_key' => trim($key),
                'request_hash' => $hash, 'actor_id' => $actor, 'reason' => trim($reason), 'before_snapshot' => $before,
                'after_snapshot' => $after, 'created_at' => now()]);
            return $after;
        });
    }
}
