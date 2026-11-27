<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MonthlyController;
use App\Http\Controllers\PayeeRuleController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SpendingController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('transactions/bulk', [TransactionController::class, 'bulk'])->name('transactions.bulk');
    Route::post('transactions/{transaction}/pair', [TransactionController::class, 'pair'])->name('transactions.pair');
    Route::post('payee-rules', [PayeeRuleController::class, 'store'])->name('payee-rules.store');
    Route::delete('payee-rules/{payeeRule}', [PayeeRuleController::class, 'destroy'])->name('payee-rules.destroy');
    Route::resource('transactions', TransactionController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('accounts/{account}/balance', [AccountController::class, 'setBalance'])->name('accounts.balance');
    Route::get('spending', SpendingController::class)->name('spending');
    Route::get('spending/monthly', MonthlyController::class)->name('spending.monthly');
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::put('budgets', [BudgetController::class, 'update'])->name('budgets.update');
    Route::post('budgets/fill', [BudgetController::class, 'fillFromAverages'])->name('budgets.fill');
    Route::get('plan', [PlanController::class, 'index'])->name('plan.index');
    Route::post('plan', [PlanController::class, 'store'])->name('plan.store');
    Route::put('plan/{item}', [PlanController::class, 'update'])->name('plan.update');
    Route::delete('plan/{item}', [PlanController::class, 'destroy'])->name('plan.destroy');
    Route::get('import', [ImportController::class, 'index'])->name('imports.index');
    Route::post('import/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('import', [ImportController::class, 'store'])->name('imports.store');
    Route::delete('import/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
});

Route::prefix('setup')->middleware('throttle:10,1')->group(function () {
    Route::get('/', [SetupController::class, 'show'])->name('setup.show');
    Route::post('migrate', [SetupController::class, 'migrate'])->name('setup.migrate');
    Route::post('user', [SetupController::class, 'createUser'])->name('setup.user');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
