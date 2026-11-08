<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Financial\InstitutionalBudgetReadController;
use App\Http\Middleware\RequireInstitutionalReadSession;

Route::middleware(['web','auth','verified',RequireInstitutionalReadSession::class,'session.active','device.track','financial.correlation','throttle:30,1'])
    ->get('/finanzas/presupuestos-institucionales',[InstitutionalBudgetReadController::class,'index'])
    ->name('financial.institutional.index');
