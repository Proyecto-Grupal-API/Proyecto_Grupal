<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EarningRuleController;
use App\Http\Controllers\Api\GovernanceController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PointsController;
use App\Http\Controllers\Api\RedemptionController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\PlatformIngestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Campus Digital Recompensas (Equipo 7)
|--------------------------------------------------------------------------
| Todas las rutas centralizadas bajo /api/v1.
*/

Route::prefix('v1')->group(function () {

    // ── Autenticación pública ──────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/switch-demo-user', [AuthController::class, 'switchDemoUser']);
    });

    // ── Catálogo de recompensas público (7.4) ──────────────────
    Route::get('/rewards', [RewardController::class, 'index']);
    Route::get('/rewards/{id}', [RewardController::class, 'show']);

    // ── Rutas protegidas (Sanctum Token) ───────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth sesión
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });

        // Dashboard adaptativo por rol
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // ── Puntos (7.1 Ledger global y alumno) ───────────────
        Route::prefix('points')->group(function () {
            Route::get('/my-balance', [PointsController::class, 'myBalance']);
            Route::get('/my-history', [PointsController::class, 'myHistory']);
            Route::get('/balance/{studentId}', [PointsController::class, 'balance']);
            Route::get('/history/{studentId}', [PointsController::class, 'history']);
            Route::get('/ledger', [PointsController::class, 'ledger'])->middleware('permission:points.view_any');
            Route::post('/earn', [PointsController::class, 'earn'])->middleware('permission:points.earn');
            Route::post('/redeem', [PointsController::class, 'redeem']);
            Route::post('/reverse', [PointsController::class, 'reverse'])->middleware('permission:points.reverse');
            Route::post('/reconcile/{studentId}', [PointsController::class, 'reconcile'])->middleware('permission:points.reconcile');
        });

        // ── Catálogo de recompensas (7.4 - Gestión) ───────────
        Route::prefix('rewards')->group(function () {
            Route::post('/', [RewardController::class, 'store'])->middleware('permission:rewards.manage');
            Route::put('/{id}', [RewardController::class, 'update'])->middleware('permission:rewards.manage');
            Route::delete('/{id}', [RewardController::class, 'destroy'])->middleware('permission:rewards.manage');
        });

        // ── Canjes y entrega (7.5) ─────────────────────────────
        Route::prefix('redemptions')->group(function () {
            Route::get('/', [RedemptionController::class, 'index']);
            Route::post('/', [RedemptionController::class, 'store'])->middleware('permission:redemptions.create');
            Route::post('/validate-code', [RedemptionController::class, 'validateCode'])->middleware('permission:redemptions.validate');
            Route::get('/{id}', [RedemptionController::class, 'show']);
            Route::post('/{id}/deliver', [RedemptionController::class, 'deliver'])->middleware('permission:redemptions.deliver');
            Route::post('/{id}/cancel', [RedemptionController::class, 'cancel']);
            Route::get('/{id}/events', [RedemptionController::class, 'events']);
        });

        // ── Negocios (7.2) ─────────────────────────────────────
        Route::prefix('businesses')->group(function () {
            Route::get('/', [BusinessController::class, 'index']);
            Route::get('/{id}', [BusinessController::class, 'show']);
            Route::post('/', [BusinessController::class, 'store'])->middleware('permission:businesses.manage');
            Route::put('/{id}', [BusinessController::class, 'update'])->middleware('permission:businesses.manage');
            Route::post('/{id}/toggle-status', [BusinessController::class, 'toggleStatus'])->middleware('permission:businesses.manage');
        });

        // ── Reglas de acumulación (7.3) ────────────────────────
        Route::prefix('rules')->group(function () {
            Route::get('/', [EarningRuleController::class, 'index']);
            Route::get('/{id}', [EarningRuleController::class, 'show']);
            Route::post('/', [EarningRuleController::class, 'store'])->middleware('permission:rules.manage');
            Route::put('/{id}', [EarningRuleController::class, 'update'])->middleware('permission:rules.manage');
            Route::post('/{id}/toggle-status', [EarningRuleController::class, 'toggleStatus'])->middleware('permission:rules.manage');
        });

        // ── Campañas (Puntos y académicas) ─────────────────────
        Route::prefix('campaigns')->group(function () {
            Route::get('/', [CampaignController::class, 'index']);
            Route::get('/{id}', [CampaignController::class, 'show']);
            Route::post('/', [CampaignController::class, 'store'])->middleware('permission:campaigns.manage');
            Route::post('/propose', [CampaignController::class, 'propose'])->middleware('permission:campaigns.propose_academic');
            Route::put('/{id}', [CampaignController::class, 'update'])->middleware('permission:campaigns.manage');
            Route::post('/{id}/toggle-status', [CampaignController::class, 'toggleStatus'])->middleware('permission:campaigns.manage');
        });

        // ── Gobierno de puntos y Fraude (7.6) ──────────────────
        Route::prefix('governance')->group(function () {
            Route::get('/policies', [GovernanceController::class, 'getPolicies']);
            Route::post('/policies', [GovernanceController::class, 'storePolicy'])->middleware('permission:governance.manage');
            Route::put('/policies/{id}', [GovernanceController::class, 'updatePolicy'])->middleware('permission:governance.manage');
            Route::get('/fraud-flags', [GovernanceController::class, 'getFraudFlags'])->middleware('permission:fraud.view');
            Route::get('/fraud-flags/{id}', [GovernanceController::class, 'showFraudFlag'])->middleware('permission:fraud.view');
            Route::post('/fraud-flags/{id}/review', [GovernanceController::class, 'reviewFraudFlag'])->middleware('permission:fraud.review');
            Route::post('/manual-adjustment', [GovernanceController::class, 'manualAdjustment'])->middleware('permission:points.adjust');
        });

        // ── Auditoría transversal (7.8) ────────────────────────
        Route::prefix('audit')->group(function () {
            Route::get('/logs', [AuditController::class, 'index']);
            Route::get('/logs/{id}', [AuditController::class, 'show']);
            Route::get('/correlations/{id}', [AuditController::class, 'correlation']);
        });

        // ── Dashboards y Analítica (7.9) ───────────────────────
        Route::prefix('analytics')->group(function () {
            Route::get('/overview', [AnalyticsController::class, 'overview'])->middleware('permission:analytics.view');
            Route::get('/professor', [AnalyticsController::class, 'professorAnalytics'])->middleware('permission:analytics.view_academic');
        });

        // ── Integración y observabilidad (7.10) ────────────────
        Route::prefix('integrations')->group(function () {
            Route::get('/logs', [IntegrationController::class, 'logs'])->middleware('permission:integrations.view');
            Route::get('/webhooks', [IntegrationController::class, 'webhooks'])->middleware('permission:integrations.view');
            Route::post('/webhooks/{id}/retry', [IntegrationController::class, 'retryWebhook'])->middleware('permission:integrations.retry');
            Route::post('/ingest-event', [IntegrationController::class, 'ingestEvent'])->middleware('permission:integrations.ingest');
        });

        // ── Administración general y configuraciones (7.7 & 7.11) ─
        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);
            Route::put('/{key}', [SettingsController::class, 'update'])->middleware('permission:settings.update');
            Route::get('/versions', [SettingsController::class, 'versions']);
            Route::post('/versions', [SettingsController::class, 'createVersion'])->middleware('permission:settings.update');
            Route::post('/versions/{id}/transition', [SettingsController::class, 'transitionVersion'])->middleware('permission:settings.approve');
        });

        Route::prefix('catalogs')->group(function () {
            Route::get('/', [SettingsController::class, 'catalogs']);
            Route::post('/', [SettingsController::class, 'storeCatalog'])->middleware('permission:catalogs.manage');
        });

        Route::prefix('plataforma')->middleware('throttle:300,1')->group(function () {
            Route::post('/eventos', [PlatformIngestController::class, 'storeEvent'])->middleware('permission:analytics.events.write');
            Route::post('/auditoria', [PlatformIngestController::class, 'storeAudit'])->middleware('permission:audit.write');
        });

    });
});
