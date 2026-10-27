<?php

use App\Http\Controllers\PlatformModule\AnalyticsController;
use App\Http\Controllers\PlatformModule\AuditController;
use App\Http\Controllers\PlatformModule\BusinessController;
use App\Http\Controllers\PlatformModule\CampaignController;
use App\Http\Controllers\PlatformModule\DashboardController;
use App\Http\Controllers\PlatformModule\EarningRuleController;
use App\Http\Controllers\PlatformModule\GovernanceController;
use App\Http\Controllers\PlatformModule\IntegrationController;
use App\Http\Controllers\PlatformModule\PlatformIngestController;
use App\Http\Controllers\PlatformModule\PointsController;
use App\Http\Controllers\PlatformModule\RedemptionController;
use App\Http\Controllers\PlatformModule\RewardController;
use App\Http\Controllers\PlatformModule\SettingsController;
use App\Http\Middleware\AuthenticatePlatformSource;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\ResolvePlatformActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| API Routes — Campus Digital Recompensas (Equipo 7)
|--------------------------------------------------------------------------
| Todas las rutas centralizadas bajo /api/v1.
*/

Route::prefix('api/v1/recompensas')->middleware(['web', 'auth', 'session.active', 'device.track', ResolvePlatformActor::class])->group(function () {

    // ── Catálogo de recompensas público (7.4) ──────────────────
    Route::get('/rewards', [RewardController::class, 'index']);
    Route::get('/rewards/{id}', [RewardController::class, 'show']);

    // ── Rutas protegidas (Sanctum Token) ───────────────────────
    Route::group([], function () {

        // Dashboard adaptativo por rol
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // ── Puntos (7.1 Ledger global y alumno) ───────────────
        Route::prefix('points')->group(function () {
            Route::get('/my-balance', [PointsController::class, 'myBalance']);
            Route::get('/my-history', [PointsController::class, 'myHistory']);
            Route::get('/balance/{studentId}', [PointsController::class, 'balance'])->middleware(EnsurePlatformPermission::class.':points.view_any');
            Route::get('/history/{studentId}', [PointsController::class, 'history'])->middleware(EnsurePlatformPermission::class.':points.view_any');
            Route::get('/ledger', [PointsController::class, 'ledger'])->middleware(EnsurePlatformPermission::class.':points.view_any');
            Route::post('/earn', [PointsController::class, 'earn'])->middleware(EnsurePlatformPermission::class.':points.earn');
            Route::post('/redeem', [PointsController::class, 'redeem']);
            Route::post('/reverse', [PointsController::class, 'reverse'])->middleware(EnsurePlatformPermission::class.':points.reverse');
            Route::post('/reconcile/{studentId}', [PointsController::class, 'reconcile'])->middleware(EnsurePlatformPermission::class.':points.reconcile');
        });

        // ── Catálogo de recompensas (7.4 - Gestión) ───────────
        Route::prefix('rewards')->group(function () {
            Route::post('/', [RewardController::class, 'store'])->middleware(EnsurePlatformPermission::class.':rewards.manage');
            Route::put('/{id}', [RewardController::class, 'update'])->middleware(EnsurePlatformPermission::class.':rewards.manage');
            Route::delete('/{id}', [RewardController::class, 'destroy'])->middleware(EnsurePlatformPermission::class.':rewards.manage');
        });

        // ── Canjes y entrega (7.5) ─────────────────────────────
        Route::prefix('redemptions')->group(function () {
            Route::get('/', [RedemptionController::class, 'index']);
            Route::post('/', [RedemptionController::class, 'store'])->middleware(EnsurePlatformPermission::class.':redemptions.create');
            Route::post('/validate-code', [RedemptionController::class, 'validateCode'])->middleware(EnsurePlatformPermission::class.':redemptions.validate');
            Route::get('/{id}', [RedemptionController::class, 'show']);
            Route::post('/{id}/deliver', [RedemptionController::class, 'deliver'])->middleware(EnsurePlatformPermission::class.':redemptions.deliver');
            Route::post('/{id}/cancel', [RedemptionController::class, 'cancel']);
            Route::get('/{id}/events', [RedemptionController::class, 'events']);
        });

        // ── Negocios (7.2) ─────────────────────────────────────
        Route::prefix('businesses')->middleware(EnsurePlatformPermission::class.':businesses.view')->group(function () {
            Route::get('/', [BusinessController::class, 'index']);
            Route::get('/{id}', [BusinessController::class, 'show']);
            Route::post('/', [BusinessController::class, 'store'])->middleware(EnsurePlatformPermission::class.':businesses.manage');
            Route::put('/{id}', [BusinessController::class, 'update'])->middleware(EnsurePlatformPermission::class.':businesses.manage');
            Route::post('/{id}/toggle-status', [BusinessController::class, 'toggleStatus'])->middleware(EnsurePlatformPermission::class.':businesses.manage');
        });

        // ── Reglas de acumulación (7.3) ────────────────────────
        Route::prefix('rules')->middleware(EnsurePlatformPermission::class.':rules.view')->group(function () {
            Route::get('/', [EarningRuleController::class, 'index']);
            Route::get('/{id}', [EarningRuleController::class, 'show']);
            Route::post('/', [EarningRuleController::class, 'store'])->middleware(EnsurePlatformPermission::class.':rules.manage');
            Route::put('/{id}', [EarningRuleController::class, 'update'])->middleware(EnsurePlatformPermission::class.':rules.manage');
            Route::post('/{id}/toggle-status', [EarningRuleController::class, 'toggleStatus'])->middleware(EnsurePlatformPermission::class.':rules.manage');
        });

        // ── Campañas (Puntos y académicas) ─────────────────────
        Route::prefix('campaigns')->group(function () {
            Route::get('/', [CampaignController::class, 'index']);
            Route::get('/{id}', [CampaignController::class, 'show']);
            Route::post('/', [CampaignController::class, 'store'])->middleware(EnsurePlatformPermission::class.':campaigns.manage');
            Route::post('/propose', [CampaignController::class, 'propose'])->middleware(EnsurePlatformPermission::class.':campaigns.propose_academic');
            Route::put('/{id}', [CampaignController::class, 'update'])->middleware(EnsurePlatformPermission::class.':campaigns.manage');
            Route::post('/{id}/toggle-status', [CampaignController::class, 'toggleStatus'])->middleware(EnsurePlatformPermission::class.':campaigns.manage');
        });

        // ── Gobierno de puntos y Fraude (7.6) ──────────────────
        Route::prefix('governance')->middleware(EnsurePlatformPermission::class.':governance.view')->group(function () {
            Route::get('/policies', [GovernanceController::class, 'getPolicies']);
            Route::post('/policies', [GovernanceController::class, 'storePolicy'])->middleware(EnsurePlatformPermission::class.':governance.manage');
            Route::put('/policies/{id}', [GovernanceController::class, 'updatePolicy'])->middleware(EnsurePlatformPermission::class.':governance.manage');
            Route::get('/fraud-flags', [GovernanceController::class, 'getFraudFlags'])->middleware(EnsurePlatformPermission::class.':fraud.view');
            Route::get('/fraud-flags/{id}', [GovernanceController::class, 'showFraudFlag'])->middleware(EnsurePlatformPermission::class.':fraud.view');
            Route::post('/fraud-flags/{id}/review', [GovernanceController::class, 'reviewFraudFlag'])->middleware(EnsurePlatformPermission::class.':fraud.review');
            Route::post('/manual-adjustment', [GovernanceController::class, 'manualAdjustment'])->middleware(EnsurePlatformPermission::class.':points.adjust');
        });

        // ── Auditoría transversal (7.8) ────────────────────────
        Route::prefix('audit')->group(function () {
            Route::get('/logs', [AuditController::class, 'index']);
            Route::get('/logs/{id}', [AuditController::class, 'show']);
            Route::get('/correlations/{id}', [AuditController::class, 'correlation']);
        });

        // ── Dashboards y Analítica (7.9) ───────────────────────
        Route::prefix('analytics')->group(function () {
            Route::get('/overview', [AnalyticsController::class, 'overview'])->middleware(EnsurePlatformPermission::class.':analytics.view');
            Route::get('/professor', [AnalyticsController::class, 'professorAnalytics'])->middleware(EnsurePlatformPermission::class.':analytics.view_academic');
        });

        // ── Integración y observabilidad (7.10) ────────────────
        Route::prefix('integrations')->group(function () {
            Route::get('/logs', [IntegrationController::class, 'logs'])->middleware(EnsurePlatformPermission::class.':integrations.view');
            Route::get('/webhooks', [IntegrationController::class, 'webhooks'])->middleware(EnsurePlatformPermission::class.':integrations.view');
            Route::post('/webhooks/{id}/retry', [IntegrationController::class, 'retryWebhook'])->middleware(EnsurePlatformPermission::class.':integrations.retry');
            Route::post('/ingest-event', [IntegrationController::class, 'ingestEvent'])->middleware(EnsurePlatformPermission::class.':integrations.ingest');
        });

        // ── Administración general y configuraciones (7.7 & 7.11) ─
        Route::prefix('settings')->middleware(EnsurePlatformPermission::class.':settings.view')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);
            Route::put('/{key}', [SettingsController::class, 'update'])->middleware(EnsurePlatformPermission::class.':settings.update');
            Route::get('/versions', [SettingsController::class, 'versions']);
            Route::post('/versions', [SettingsController::class, 'createVersion'])->middleware(EnsurePlatformPermission::class.':settings.update');
            Route::post('/versions/{id}/transition', [SettingsController::class, 'transitionVersion'])->middleware(EnsurePlatformPermission::class.':settings.approve');
        });

        Route::prefix('catalogs')->middleware(EnsurePlatformPermission::class.':catalogs.view')->group(function () {
            Route::get('/', [SettingsController::class, 'catalogs']);
            Route::post('/', [SettingsController::class, 'storeCatalog'])->middleware(EnsurePlatformPermission::class.':catalogs.manage');
        });

    });
});

Route::middleware(['web', 'auth', 'session.active', 'device.track', ResolvePlatformActor::class])->prefix('recompensas')->group(function () {
    Route::get('/', function (Request $r) {
        $role = $r->attributes->get('platform_actor')->role;

        return redirect('/recompensas/'.match ($role) {
            'admin' => 'admin','professor' => 'profesor',default => 'alumno'
        });
    })->name('platform.home');
    Route::get('/alumno', fn (Request $r) => Inertia::render('Platform/student/StudentDashboard', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]));
    Route::get('/alumno/campanas', fn (Request $r) => Inertia::render('Platform/student/StudentCampaigns', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]));
    Route::get('/alumno/catalogo', fn (Request $r) => Inertia::render('Platform/student/StudentCatalog', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]));
    Route::get('/alumno/canjes', fn (Request $r) => Inertia::render('Platform/student/StudentRedemptions', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]));
    Route::get('/alumno/actividad', fn (Request $r) => Inertia::render('Platform/student/StudentActivity', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]));
    Route::get('/profesor', fn (Request $r) => Inertia::render('Platform/professor/ProfessorDashboard', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':analytics.view_academic');
    Route::get('/profesor/campanas', fn (Request $r) => Inertia::render('Platform/professor/ProfessorCampaigns', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':campaigns.propose_academic');
    Route::get('/profesor/validar', fn (Request $r) => Inertia::render('Platform/professor/ProfessorValidate', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':redemptions.validate');
    Route::get('/profesor/analitica', fn (Request $r) => Inertia::render('Platform/professor/ProfessorAnalytics', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':analytics.view_academic');
    Route::get('/profesor/historial', fn (Request $r) => Inertia::render('Platform/professor/ProfessorHistory', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':audit.view_own');
    Route::get('/admin', fn (Request $r) => Inertia::render('Platform/admin/AdminDashboard', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':analytics.view');
    Route::get('/admin/ledger', fn (Request $r) => Inertia::render('Platform/admin/AdminLedger', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':points.view_any');
    Route::get('/admin/negocios', fn (Request $r) => Inertia::render('Platform/admin/AdminBusinesses', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':businesses.view');
    Route::get('/admin/reglas', fn (Request $r) => Inertia::render('Platform/admin/AdminRules', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':rules.view');
    Route::get('/admin/catalogo', fn (Request $r) => Inertia::render('Platform/admin/AdminCatalog', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':rewards.manage');
    Route::get('/admin/canjes', fn (Request $r) => Inertia::render('Platform/admin/AdminRedemptions', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':redemptions.view_all');
    Route::get('/admin/gobierno', fn (Request $r) => Inertia::render('Platform/admin/AdminGovernance', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':governance.view');
    Route::get('/admin/administracion', fn (Request $r) => Inertia::render('Platform/admin/AdminGeneral', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':catalogs.view');
    Route::get('/admin/auditoria', fn (Request $r) => Inertia::render('Platform/admin/AdminAudit', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':audit.view');
    Route::get('/admin/analitica', fn (Request $r) => Inertia::render('Platform/admin/AdminAnalytics', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':analytics.view');
    Route::get('/admin/integracion', fn (Request $r) => Inertia::render('Platform/admin/AdminObservability', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':integrations.view');
    Route::get('/admin/configuracion', fn (Request $r) => Inertia::render('Platform/admin/AdminSettings', ['platformActor' => $r->attributes->get('platform_actor')->toArray()]))->middleware(EnsurePlatformPermission::class.':settings.view');
});

Route::prefix('api/v1/plataforma')->middleware([AuthenticatePlatformSource::class, 'throttle:300,1'])->group(function () {
    Route::post('/eventos', [PlatformIngestController::class, 'storeEvent']);
    Route::post('/auditoria', [PlatformIngestController::class, 'storeAudit']);
});
