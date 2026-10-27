<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\PointCampaign;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\Reward;
use App\Models\PlatformModule\RewardProgramMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class AnalyticsController
{
    /**
     * Analítica transversal del programa de recompensas (módulo 7.9).
     * Devuelve agregaciones reales basadas en PointsLedger, Redemptions, Accounts y Rewards.
     */
    public function overview(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $since = Carbon::now()->subDays($days)->startOfDay();

        // Totales globales
        $totalAccounts = PointsAccount::count();
        $totalCirculation = (int) PointsAccount::sum('balance');
        $totalEarned = (int) PointsAccount::sum('lifetime_earned');
        $totalRedeemed = (int) PointsAccount::sum('lifetime_redeemed');

        // Movimientos recientes
        $earnedPeriod = (int) PointsLedger::where('type', 'earn')
            ->where('created_at', '>=', $since)
            ->sum('amount');

        $redeemedPeriod = abs((int) PointsLedger::where('type', 'redeem')
            ->where('created_at', '>=', $since)
            ->sum('amount'));

        // Desglose de canjes por estado
        $redemptionsByStatus = Redemption::raw(function ($collection) {
            return $collection->aggregate([
                ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
            ]);
        })->pluck('count', '_id')->toArray();

        // Top recompensas más canjeadas
        $topRewards = Reward::orderBy('stock', 'desc')->take(5)->get(['name', 'points_required', 'stock', 'reserved_stock']);

        // Agrupación mensual o diaria de puntos
        $activityTrend = PointsLedger::where('created_at', '>=', $since)
            ->orderBy('created_at', 'asc')
            ->take(100)
            ->get(['created_at', 'type', 'amount'])
            ->groupBy(fn ($item) => $item->created_at?->format('Y-m-d') ?? '')
            ->map(function ($items, $date) {
                return [
                    'date' => $date,
                    'earned' => $items->where('type', 'earn')->sum('amount'),
                    'redeemed' => abs($items->where('type', 'redeem')->sum('amount')),
                ];
            })->values();

        return response()->json([
            'summary' => [
                'total_accounts' => $totalAccounts,
                'points_in_circulation' => $totalCirculation,
                'lifetime_earned' => $totalEarned,
                'lifetime_redeemed' => $totalRedeemed,
                'period_days' => $days,
                'earned_in_period' => $earnedPeriod,
                'redeemed_in_period' => $redeemedPeriod,
            ],
            'redemptions_by_status' => $redemptionsByStatus,
            'top_rewards' => $topRewards,
            'activity_trend' => $activityTrend,
            'active_businesses' => RewardProgramMembership::where('status', 'active')->count(),
            'active_campaigns' => PointCampaign::where('status', 'active')->count(),
        ]);
    }

    /**
     * Analítica para el panel docente del profesor.
     */
    public function professorAnalytics(Request $request): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');
        $professorId = $user ? (string) $user->getKey() : null;

        $academicCampaigns = PointCampaign::where('type', 'academic')
            ->when($professorId, fn ($q) => $q->where('sponsor_id', $professorId))
            ->get();

        $validationsTotal = Redemption::where('status', 'delivered')->count();
        $validationsThisMonth = Redemption::where('status', 'delivered')
            ->where('delivered_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        return response()->json([
            'total_validations' => $validationsTotal,
            'validations_month' => $validationsThisMonth,
            'academic_campaigns' => $academicCampaigns->count(),
            'campaigns' => $academicCampaigns,
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $since = Carbon::now()->subDays((int) $request->query('days', 30))->startOfDay();

        $rows = AnalyticsEvent::raw(fn ($c) => $c->aggregate([
            ['$match' => ['source_domain' => 'services', 'occurred_at' => ['$gte' => new UTCDateTime($since)]]],
            ['$group' => [
                '_id' => ['day' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$occurred_at']], 'event' => '$event_name'],
                'count' => ['$sum' => 1],
            ]],
            ['$project' => ['_id' => 0, 'day' => '$_id.day', 'event' => '$_id.event', 'count' => 1]],
            ['$sort' => ['day' => 1]],
        ]));

        return response()->json([
            'por_dia' => $rows->groupBy('day')->map(fn ($g) => $g->pluck('count', 'event')),
        ]);
    }
}
