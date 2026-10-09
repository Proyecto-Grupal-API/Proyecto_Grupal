<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\LimitSubjectType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\WalletType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialControlWebController extends Controller
{
    public function index(Request $request, FinancialWebAuthorizer $authorizer): Response
    {
        $permissions = [];
        foreach (['limits.view', 'limits.manage', 'limits.evaluate', 'alerts.view', 'alerts.review',
            'reconciliation.view', 'reconciliation.run', 'reconciliation.resolve'] as $action) {
            $permissions[$action] = $authorizer->allows((string) $request->user()->getKey(), $action, null);
        }
        $values = fn (string $enum) => array_map(fn ($case) => $case->value, $enum::cases());
        return Inertia::render('Financial/Controls', [
            'permissions' => $permissions,
            'catalogs' => [
                'subjects' => $values(LimitSubjectType::class), 'operations' => $values(MovementType::class),
                'periods' => $values(LimitPeriod::class), 'metrics' => $values(LimitMetric::class),
                'actions' => $values(LimitAction::class), 'walletTypes' => $values(WalletType::class),
                'alertStatuses' => $values(AlertStatus::class), 'alertTypes' => $values(AlertType::class),
                'outcomes' => $values(AlertOutcome::class), 'reconciliationStatuses' => $values(ReconciliationStatus::class),
                'checks' => $values(ReconciliationCheck::class),
            ],
            'businessDate' => now(config('financial.business_timezone', 'America/Mexico_City'))->toDateString(),
        ]);
    }
}
