<?php

namespace App\Support;

use App\Models\Account;
use App\Models\InvestmentAccount;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared sums for the Reports pages. The rules match Spending › By month:
 * income is money in income categories, spending is money out of spending
 * categories plus anything not categorized yet (refunds count against it),
 * and transfers are left out.
 */
class ReportData
{
    public static function monthExpr(string $column = 'transactions.date'): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', $column)" : "DATE_FORMAT($column, '%Y-%m')";
    }

    /** Last month that has transactions (or this month), as a Carbon on the 1st. */
    public static function latestMonth(): Carbon
    {
        $last = Transaction::max('date');

        return ($last ? Carbon::parse($last) : now())->copy()->startOfMonth();
    }

    /** @return list<string> "Y-m" keys from $from to $to */
    public static function monthKeys(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonthNoOverflow()) {
            $out[] = $m->format('Y-m');
        }

        return $out;
    }

    /** @return array<string, array{income:int, spending:int}> per "Y-m" */
    public static function months(Carbon $from, Carbon $to): array
    {
        $ym = self::monthExpr();
        $out = array_fill_keys(self::monthKeys($from, $to), ['income' => 0, 'spending' => 0]);
        Transaction::leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->whereDate('transactions.date', '>=', $from->toDateString())
            ->whereDate('transactions.date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('transactions.category_id')->orWhereIn('categories.kind', ['income', 'expense']))
            ->groupBy(DB::raw($ym), 'categories.kind')
            ->select(DB::raw("$ym as ym"), 'categories.kind', DB::raw('sum(transactions.amount) as total'))
            ->get()
            ->each(function ($r) use (&$out) {
                if (! isset($out[$r->ym])) {
                    return;
                }
                if ($r->kind === 'income') {
                    $out[$r->ym]['income'] += (int) $r->total;
                } else {
                    $out[$r->ym]['spending'] -= (int) $r->total;
                }
            });

        return $out;
    }

    /** @return array{income:int, spending:int} */
    public static function totals(Carbon $from, Carbon $to): array
    {
        $m = self::months($from, $to);

        return ['income' => array_sum(array_column($m, 'income')), 'spending' => array_sum(array_column($m, 'spending'))];
    }

    /** Spending per main category (subcategories included), biggest first. */
    public static function byCategory(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::leftJoin('categories as c', 'c.id', '=', 'transactions.category_id')
            ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->whereDate('transactions.date', '>=', $from->toDateString())
            ->whereDate('transactions.date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('transactions.category_id')->orWhere('c.kind', 'expense'))
            ->groupBy(DB::raw('coalesce(p.id, c.id)'), DB::raw('coalesce(p.name, c.name)'))
            ->select(DB::raw('coalesce(p.id, c.id) as id'), DB::raw('coalesce(p.name, c.name) as name'), DB::raw('sum(transactions.amount) as total'))
            ->get()
            ->map(fn ($r) => ['id' => $r->id ? (int) $r->id : null, 'name' => $r->name ?? 'Uncategorized', 'total' => -(int) $r->total])
            ->filter(fn ($r) => $r['total'] !== 0)
            ->sortByDesc('total')
            ->values();

        return $rows->all();
    }

    /**
     * Spending per payee, biggest first. Payees are grouped ignoring case
     * and extra spaces; the most common spelling is shown.
     */
    public static function payees(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::leftJoin('categories as c', 'c.id', '=', 'transactions.category_id')
            ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->whereDate('transactions.date', '>=', $from->toDateString())
            ->whereDate('transactions.date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('transactions.category_id')->orWhere('c.kind', 'expense'))
            ->get(['transactions.payee', 'transactions.amount', 'transactions.date', DB::raw('coalesce(p.name, c.name) as main')]);
        $groups = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($r->payee)));
            $g = &$groups[$key];
            $g ??= ['names' => [], 'count' => 0, 'total' => 0, 'last' => null, 'mains' => []];
            $g['names'][$r->payee] = ($g['names'][$r->payee] ?? 0) + 1;
            $g['count']++;
            $g['total'] -= (int) $r->amount;
            $d = Carbon::parse($r->date)->toDateString();
            $g['last'] = max($g['last'] ?? $d, $d);
            $main = $r->main ?? 'Uncategorized';
            $g['mains'][$main] = ($g['mains'][$main] ?? 0) - (int) $r->amount;
            unset($g);
        }
        $out = [];
        foreach ($groups as $g) {
            if ($g['total'] <= 0) {
                continue;
            }
            arsort($g['names']);
            arsort($g['mains']);
            $out[] = [
                'payee' => array_key_first($g['names']),
                'count' => $g['count'],
                'total' => $g['total'],
                'average' => (int) round($g['total'] / $g['count']),
                'last' => $g['last'],
                'category' => array_key_first($g['mains']),
            ];
        }
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * Balances of every bank account at the end of a day, plus investments.
     *
     * @return array{cash:int, other:int, debts:int, investments:int, net:int}
     */
    public static function worthOn(Carbon $day, $accounts = null, ?InvestmentReport $inv = null): array
    {
        $accounts ??= Account::all();
        $sums = Transaction::whereDate('date', '<=', $day->toDateString())->groupBy('account_id')
            ->select('account_id', DB::raw('sum(amount) as total'))->pluck('total', 'account_id');
        $out = ['cash' => 0, 'other' => 0, 'debts' => 0, 'investments' => 0];
        foreach ($accounts as $a) {
            $bal = (int) ($sums[$a->id] ?? 0) + ($a->opening_date === null || $a->opening_date->lte($day) ? $a->opening_balance : 0);
            if ($a->isDebt()) {
                $out['debts'] += $bal;
            } elseif (in_array($a->type, ['checking', 'savings', 'cash'], true)) {
                $out['cash'] += $bal;
            } else {
                $out['other'] += $bal;
            }
        }
        $inv ??= new InvestmentReport(InvestmentAccount::with(['balances', 'activities'])->get(), 'all');
        $out['investments'] = $inv->totalOn($day);
        $out['net'] = $out['cash'] + $out['other'] + $out['debts'] + $out['investments'];

        return $out;
    }

    /** Average monthly spending over the full months before $month (default 6). */
    public static function averageSpending(Carbon $month, int $count = 6): int
    {
        $from = $month->copy()->startOfMonth()->subMonthsNoOverflow($count);
        $to = $month->copy()->startOfMonth()->subDay();
        $m = array_filter(self::months($from, $to), fn ($x) => $x['spending'] !== 0 || $x['income'] !== 0);

        return $m ? (int) round(array_sum(array_column($m, 'spending')) / count($m)) : 0;
    }
}
