<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampusAuthorizationController as Admin;
Route::middleware(['web','auth','verified','session.active','device.track'])->prefix('administracion/autorizaciones')->name('campus.authorization.')->group(function() {
    Route::get('/',[Admin::class,'index'])->name('index');
    Route::get('/records',[Admin::class,'records'])->name('records');
    Route::get('/lookup',[Admin::class,'lookup'])->middleware('throttle:30,1')->name('lookup');
    Route::get('/contexts',[Admin::class,'contexts'])->name('contexts');
    Route::post('/seats',[Admin::class,'seat'])->middleware('throttle:30,1')->name('seats');
    Route::post('/seats/{module}/release',[Admin::class,'release'])->where('module','[1-7]')->middleware('throttle:30,1')->name('release');
    Route::post('/grants',[Admin::class,'grant'])->middleware('throttle:30,1')->name('grants');
    Route::post('/grants/{grant}/revoke',[Admin::class,'revoke'])->whereUuid('grant')->middleware('throttle:30,1')->name('revoke');
});
