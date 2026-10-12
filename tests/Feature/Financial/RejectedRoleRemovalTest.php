<?php
use App\Domains\Financial\Contracts\{BonusAuthorizationProvider,CashAuthorizationProvider,ReceiptAdministrativeAuthorization,LegacyCashReceiptAuthorization};
use App\Domains\Financial\Adapters\{PendingBonusAuthorizationProvider,PendingCashAuthorizationProvider,PendingReceiptAdministrativeAuthorization,PendingLegacyCashReceiptAuthorization};
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;

test('removed role switches cannot reactivate rejected financial authorization',function(){
    config(['financial.bonus_roles.enabled'=>true,'campus_authorization.enabled'=>true,
        'financial_cash_authorization.provider'=>'campus','financial_receipts.administration_provider'=>'campus']);
    expect(app(BonusAuthorizationProvider::class))->toBeInstanceOf(PendingBonusAuthorizationProvider::class)
        ->and(app(CashAuthorizationProvider::class))->toBeInstanceOf(PendingCashAuthorizationProvider::class)
        ->and(app(ReceiptAdministrativeAuthorization::class))->toBeInstanceOf(PendingReceiptAdministrativeAuthorization::class)
        ->and(app(LegacyCashReceiptAuthorization::class))->toBeInstanceOf(PendingLegacyCashReceiptAuthorization::class);
});
test('removed general and bonus role routes are absent and two factor remains available',function(){
    expect(\Illuminate\Support\Facades\Route::has('campus.authorization.index'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('financial.bonuses.roles.index'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('two-factor.confirm'))->toBeTrue();
});
test('historical administration fails closed without the approved identity contract',function(){
    expect(fn()=>app(LegacyCashReceiptAuthorization::class)->assertGlobal('6aba858405cbb6446708bfa2'))
        ->toThrow(FinancialDependencyUnavailableException::class);
});
