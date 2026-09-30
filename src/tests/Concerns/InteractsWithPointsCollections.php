<?php

namespace Tests\Concerns;

use App\Models\AuditCorrelation;
use App\Models\AuditLog;
use App\Models\EarningRule;
use App\Models\FraudFlag;
use App\Models\PointCampaign;
use App\Models\PointsAccount;
use App\Models\PointsLedger;

/**
 * PointsLedger y AuditLog son append-only (booted() bloquea updates/deletes vía Eloquent),
 * pero eso no impide un delete directo por query builder, que no dispara esos hooks de
 * instancia. Se usa aquí solo para higiene entre tests, nunca en código de producción.
 */
trait InteractsWithPointsCollections
{
    protected function cleanPointsCollections(): void
    {
        PointsLedger::query()->delete();
        PointsAccount::query()->delete();
        EarningRule::query()->delete();
        PointCampaign::query()->delete();
        FraudFlag::query()->delete();
        AuditLog::query()->delete();
        AuditCorrelation::query()->delete();
    }
}
