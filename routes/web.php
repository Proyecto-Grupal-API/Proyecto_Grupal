<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Biblioteca')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/student-services.php';
require __DIR__.'/settings.php';
