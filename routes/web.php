<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::prefix('setup')->middleware('throttle:10,1')->group(function () {
    Route::get('/', [SetupController::class, 'show'])->name('setup.show');
    Route::post('migrate', [SetupController::class, 'migrate'])->name('setup.migrate');
    Route::post('user', [SetupController::class, 'createUser'])->name('setup.user');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
