<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\FraudFlag;
use App\Models\PlatformModule\PointCampaign;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\Reward;
use App\Models\PlatformModule\RewardProgramMembership;
use App\Services\PlatformModule\PlatformActor as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController
{
    /**
     * Resumen estadístico adaptado al rol del usuario autenticado:
     * - student: sus puntos, canjes activos, campaña destacada, recompensas recomendadas, actividad reciente.
     * - professor: campañas académicas, canjes pendientes de validación, analítica básica docente.
     * - admin: indicadores globales de la plataforma, ledger reciente, alertas de fraude abiertas.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');
        $today = Carbon::today();
        $role = $user?->role ?? User::ROLE_ADMIN;

        if ($role === User::ROLE_STUDENT && $user) {
            return $this->studentDashboard((string) $user->getKey());
        }

        if ($role === User::ROLE_PROFESSOR && $user) {
            return $this->professorDashboard((string) $user->getKey());
        }

        return $this->adminDashboard($today);
    }

    private function studentDashboard(string $studentId): JsonResponse
    {
        $account = PointsAccount::where('student_id', $studentId)->first();
        $balance = (int) PointsLedger::where('student_id', $studentId)->sum('amount');

        $activeRedemptionsCount = Redemption::where('student_id', $studentId)
            ->whereIn('status', ['confirmed', 'reserved', 'stock_pending'])
            ->count();

        $availableCampaignsCount = PointCampaign::where('status', 'active')->count();

        $featuredCampaign = PointCampaign::where('status', 'active')
            ->orderBy('multiplier', 'desc')
            ->first();

        $featuredRewards = Reward::where('status', 'active')
            ->orderBy('points_required', 'asc')
            ->take(3)
            ->get();

        $recentActivity = PointsLedger::where('student_id', $studentId)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'role' => 'student',
            'balance' => $balance,
            'pending_balance' => $account?->pending_balance ?? 0,
            'lifetime_earned' => $account?->lifetime_earned ?? 0,
            'lifetime_redeemed' => $account?->lifetime_redeemed ?? 0,
            'active_redemptions' => $activeRedemptionsCount,
            'available_campaigns' => $availableCampaignsCount,
            'featured_campaign' => $featuredCampaign ? [
                'id' => (string) $featuredCampaign->_id,
                'title' => $featuredCampaign->name,
                'description' => $featuredCampaign->description,
                'period' => ($featuredCampaign->starts_at?->format('d/m/Y') ?? 'Ahora').' — '.($featuredCampaign->ends_at?->format('d/m/Y') ?? 'Sin vencimiento'),
                'multiplier' => $featuredCampaign->multiplier,
            ] : null,
            'featured_rewards' => $featuredRewards->map(fn (Reward $r) => [
                'id' => (string) $r->_id,
                'name' => $r->name,
                'points' => $r->points_required,
                'image' => $r->is_physical ? '📦' : '🎟',
                'description' => $r->description,
            ]),
            'recent_activity' => $recentActivity->map(fn (PointsLedger $l) => [
                'id' => (string) $l->_id,
                'date' => $l->created_at?->format('d M, Y') ?? '',
                'concept' => $l->description ?? 'Operación de puntos',
                'points' => $l->amount,
                'status' => 'Completado',
                'type' => $l->type,
            ]),
        ]);
    }

    private function professorDashboard(string $professorId): JsonResponse
    {
        $pendingCount = Redemption::whereIn('status', ['confirmed', 'reserved', 'stock_pending'])->count();
        $validatedThisMonth = Redemption::where('status', 'delivered')
            ->where('delivered_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        $academicCampaigns = PointCampaign::where('type', 'academic')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $pendingRedemptions = Redemption::with(['reward'])
            ->whereIn('status', ['confirmed', 'reserved', 'stock_pending'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'role' => 'professor',
            'pending_validations' => $pendingCount,
            'validated_this_month' => $validatedThisMonth,
            'academic_campaigns' => $academicCampaigns->count(),
            'students_engaged' => PointsAccount::count(),
            'recent_pending' => $pendingRedemptions,
            'campaigns' => $academicCampaigns,
        ]);
    }

    private function adminDashboard(Carbon $today): JsonResponse
    {
        return response()->json([
            'role' => 'admin',
            'points_issued_month' => (int) PointsLedger::where('type', 'earn')->where('created_at', '>=', Carbon::now()->startOfMonth())->sum('amount'),
            'total_redemptions_delivered' => Redemption::where('status', 'delivered')->count(),
            'active_businesses' => RewardProgramMembership::where('status', 'active')->count(),
            'total_accounts' => PointsAccount::count(),
            'total_points_in_circulation' => (int) PointsAccount::sum('balance'),
            'total_lifetime_earned' => (int) PointsAccount::sum('lifetime_earned'),
            'total_lifetime_redeemed' => (int) PointsAccount::sum('lifetime_redeemed'),
            'transactions_today' => PointsLedger::where('created_at', '>=', $today)->count(),
            'points_earned_today' => (int) PointsLedger::where('type', 'earn')->where('created_at', '>=', $today)->sum('amount'),
            'active_rewards' => Reward::where('status', 'active')->count(),
            'open_fraud_flags' => FraudFlag::where('status', 'open')->count(),
            'redemptions_today' => Redemption::where('created_at', '>=', $today)->count(),
            'recent_ledger' => PointsLedger::orderBy('created_at', 'desc')->take(5)->get(),
            'top_rewards' => Reward::orderBy('stock', 'desc')->take(4)->get(),
        ]);
    }
}
