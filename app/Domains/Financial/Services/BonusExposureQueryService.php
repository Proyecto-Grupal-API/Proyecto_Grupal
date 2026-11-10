<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\BonusType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Current stored exposure, never funding certification or historical reconstruction. */
final class BonusExposureQueryService
{
    public function report(?string $type = null, ?string $currency = null): array
    {
        if ($type !== null && BonusType::tryFrom($type) === null) {
            throw new InvalidArgumentException('Tipo de bono no admitido.');
        }
        if ($currency !== null && !preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new InvalidArgumentException('La moneda debe tener tres letras mayusculas.');
        }
        // One read statement, no model events, expiry, ledgers, sessions or Identity calls.
        $at = CarbonImmutable::now('UTC');
        $stamp = $at->format('Y-m-d H:i:s.v');
        $bucket = "CASE
            WHEN type IS NULL OR type NOT IN ('BECA','CAMPANIA','BENEFICIO','CATEGORIA','NEGOCIO')
                OR currency IS NULL OR LEN(currency) <> 3
                OR original_amount_cents IS NULL OR remaining_amount_cents IS NULL OR status IS NULL
                OR original_amount_cents <= 0 OR remaining_amount_cents < 0
                OR remaining_amount_cents > original_amount_cents
                OR valid_from IS NULL OR expires_at IS NULL OR expires_at <= valid_from
                OR status NOT IN ('ACTIVO','PENDIENTE','AGOTADO','CANCELADO','EXPIRADO') THEN 'INVALID_RECORD'
            WHEN status IN ('AGOTADO','CANCELADO','EXPIRADO') AND remaining_amount_cents > 0 THEN 'TERMINAL_WITH_BALANCE'
            WHEN status IN ('AGOTADO','CANCELADO','EXPIRADO') THEN 'TERMINAL'
            WHEN remaining_amount_cents = 0 THEN 'OPEN_WITHOUT_BALANCE'
            WHEN expires_at <= ? THEN 'EXPIRED_UNPROCESSED'
            WHEN valid_from > ? THEN 'SCHEDULED'
            ELSE 'AVAILABLE' END";
        $classified = DB::connection('sqlsrv')->table('bonuses')->select(['type', 'currency', 'status',
            'original_amount_cents', 'remaining_amount_cents'])
            ->selectRaw($bucket.' AS exposure_bucket', [$stamp, $stamp])
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency));
        $rows = DB::connection('sqlsrv')->query()->fromSub($classified, 'exposure')
            ->select(['type', 'currency', 'status', 'exposure_bucket'])
            ->selectRaw('COUNT_BIG(*) AS bonus_count')
            // SQL BIGINT sums and PHP float conversion must not overflow or round money.
            ->selectRaw('CAST(SUM(CAST(original_amount_cents AS DECIMAL(38,0))) AS VARCHAR(40)) AS original_amount_cents')
            ->selectRaw('CAST(SUM(CAST(remaining_amount_cents AS DECIMAL(38,0))) AS VARCHAR(40)) AS remaining_amount_cents')
            ->groupBy('type', 'currency', 'status', 'exposure_bucket')
            ->orderBy('type')->orderBy('currency')->orderBy('status')->orderBy('exposure_bucket')->get();
        return [
            'observed_at' => $at->toISOString(), 'basis' => 'CURRENT_STORED_BALANCES',
            'filters' => ['type' => $type, 'currency' => $currency],
            'funding_evidence' => 'NOT_CHECKED_BY_THIS_REPORT',
            'funding_certified' => false, 'money_moved' => false,
            'groups' => $rows->map(fn ($r) => [
                'type' => $r->type, 'currency' => $r->currency, 'stored_status' => $r->status,
                'bucket' => $r->exposure_bucket, 'bonus_count' => (string) $r->bonus_count,
                'original_amount_cents' => (string) $r->original_amount_cents,
                'remaining_amount_cents' => (string) $r->remaining_amount_cents,
            ])->all(),
        ];
    }
}
