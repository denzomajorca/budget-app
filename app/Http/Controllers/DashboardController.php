<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $accounts = Account::withBalance()->where('is_archived', false)->orderBy('name')->get()
            ->map(fn (Account $a) => AccountController::present($a));

        $monthStart = now()->startOfMonth()->toDateString();
        $spentThisMonth = (int) Transaction::whereDate('date', '>=', $monthStart)
            ->where('amount', '<', 0)
            ->where(fn ($q) => $q->whereNull('category_id')->orWhereHas('category', fn ($c) => $c->where('kind', 'expense')))
            ->sum('amount');

        $recent = Transaction::with(['account:id,name', 'category.parent'])->orderByDesc('date')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'date' => $t->date->format('Y-m-d'),
                'payee' => $t->payee,
                'amount' => $t->amount,
                'account' => $t->account?->name,
                'category' => $t->category?->label(),
            ]);

        return Inertia::render('Dashboard', [
            'accounts' => $accounts,
            'netWorth' => $accounts->sum('balance'),
            'spentThisMonth' => -$spentThisMonth,
            'monthStart' => $monthStart,
            'recentTransactions' => $recent,
        ]);
    }
}
