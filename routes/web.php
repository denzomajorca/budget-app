<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\MonthlyController;
use App\Http\Controllers\PayeeRuleController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RetirementController;
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
    Route::post('budgets/layout', [BudgetController::class, 'layout'])->name('budgets.layout');
    Route::get('plan', [PlanController::class, 'index'])->name('plan.index');
    Route::post('plan', [PlanController::class, 'store'])->name('plan.store');
    Route::put('plan/{item}', [PlanController::class, 'update'])->name('plan.update');
    Route::delete('plan/{item}', [PlanController::class, 'destroy'])->name('plan.destroy');
    Route::redirect('reports', '/reports/cash-flow')->name('reports');
    Route::get('reports/cash-flow', [ReportsController::class, 'cashFlow'])->name('reports.cashflow');
    Route::get('reports/net-worth', [ReportsController::class, 'netWorth'])->name('reports.networth');
    Route::get('reports/budget', [ReportsController::class, 'budget'])->name('reports.budget');
    Route::get('reports/payees', [ReportsController::class, 'payees'])->name('reports.payees');
    Route::get('reports/year', [ReportsController::class, 'year'])->name('reports.year');
    Route::get('investments', [InvestmentController::class, 'index'])->name('investments.index');
    Route::post('investments/accounts', [InvestmentController::class, 'storeAccount'])->name('investments.accounts.store');
    Route::put('investments/accounts/{account}', [InvestmentController::class, 'updateAccount'])->name('investments.accounts.update');
    Route::delete('investments/accounts/{account}', [InvestmentController::class, 'destroyAccount'])->name('investments.accounts.destroy');
    Route::put('investments/accounts/{account}/target', [InvestmentController::class, 'storeTarget'])->name('investments.accounts.target');
    Route::post('investments/funds', [InvestmentController::class, 'storeFund'])->name('investments.funds.store');
    Route::post('investments/balances', [InvestmentController::class, 'storeBalance'])->name('investments.balances.store');
    Route::delete('investments/balances/{balance}', [InvestmentController::class, 'destroyBalance'])->name('investments.balances.destroy');
    Route::post('investments/activity', [InvestmentController::class, 'storeActivity'])->name('investments.activity.store');
    Route::delete('investments/activity/{activity}', [InvestmentController::class, 'destroyActivity'])->name('investments.activity.destroy');
    Route::post('investments/import', [InvestmentController::class, 'import'])->name('investments.import');
    Route::delete('investments/import/{import}', [InvestmentController::class, 'destroyImport'])->name('investments.import.destroy');
    Route::get('retirement', [RetirementController::class, 'index'])->name('retirement.index');
    Route::put('retirement', [RetirementController::class, 'update'])->name('retirement.update');
    Route::delete('retirement', [RetirementController::class, 'reset'])->name('retirement.reset');
    Route::get('import', [ImportController::class, 'index'])->name('imports.index');
    Route::post('import/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('import', [ImportController::class, 'store'])->name('imports.store');
    Route::post('import/match', [ImportController::class, 'match'])->name('imports.match');
    Route::post('import/batch', [ImportController::class, 'batch'])->name('imports.batch');
    Route::delete('import/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
});

Route::prefix('setup')->middleware('throttle:10,1')->group(function () {
    Route::get('/', [SetupController::class, 'show'])->name('setup.show');
    Route::post('migrate', [SetupController::class, 'migrate'])->name('setup.migrate');
    Route::post('user', [SetupController::class, 'createUser'])->name('setup.user');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
