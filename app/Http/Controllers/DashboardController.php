<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\InvestmentAccount;
use App\Models\Transaction;
use App\Support\BudgetMonth;
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

        $budget = (new BudgetMonth(now()->format('Y-m')))->build();
        $over = [];
        foreach ($budget['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                if ($r['budget'] !== null && $r['spent'] > $r['budget']) {
                    $over[] = ['name' => $g['has_children'] ? $g['name'].' › '.$r['name'] : $r['name'], 'over' => $r['spent'] - $r['budget']];
                }
            }
        }
        usort($over, fn ($a, $b) => $b['over'] <=> $a['over']);

        return Inertia::render('Dashboard', [
            'budget' => ['budgeted' => $budget['totals']['budget'], 'spent' => $budget['totals']['spent'], 'over' => array_slice($over, 0, 3)],
            'accounts' => $accounts,
            'investments' => $investments = InvestmentAccount::totalValue(),
            'netWorth' => $accounts->sum('balance') + $investments,
            'spentThisMonth' => -$spentThisMonth,
            'monthStart' => $monthStart,
            'recentTransactions' => $recent,
        ]);
    }
}
