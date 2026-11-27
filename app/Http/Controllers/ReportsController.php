<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\InvestmentAccount;
use App\Models\Transaction;
use App\Support\BudgetMonth;
use App\Support\InvestmentReport;
use App\Support\ReportData;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports: cash flow, net worth, budget vs actual, payees and a printable
 * year in review. Spending › By month already covers categories by month.
 */
class ReportsController extends Controller
{
    private const SPANS = [12, 24, 36];

    private function span(Request $request): int
    {
        return in_array($request->integer('months'), self::SPANS, true) ? $request->integer('months') : 12;
    }

    private static function label(string $ym, bool $year = true): string
    {
        return Carbon::parse($ym.'-01')->format($year ? 'M Y' : 'M');
    }

    private static function named(array $map): array
    {
        return array_map(fn ($k, $v) => ['name' => $k, 'amount' => $v], array_keys($map), array_values($map));
    }

    /** Income against spending, month by month, and how much was kept. */
    public function cashFlow(Request $request): Response
    {
        $span = $this->span($request);
        $end = ReportData::latestMonth();
        $from = $end->copy()->subMonthsNoOverflow($span - 1);
        $months = ReportData::months($from, $end->copy()->endOfMonth());
        $rows = [];
        foreach ($months as $ym => $m) {
            $net = $m['income'] - $m['spending'];
            $rows[] = $m + ['month' => $ym, 'label' => self::label($ym), 'net' => $net, 'rate' => $m['income'] > 0 ? $net / $m['income'] : null];
        }
        $income = array_sum(array_column($rows, 'income'));
        $spending = array_sum(array_column($rows, 'spending'));
        $withData = array_values(array_filter($rows, fn ($r) => $r['income'] || $r['spending'])) ?: $rows;
        $byNet = $withData;
        usort($byNet, fn ($a, $b) => $b['net'] <=> $a['net']);

        return Inertia::render('Reports/CashFlow', [
            'months' => $span,
            'spans' => self::SPANS,
            'rows' => $rows,
            'totals' => [
                'income' => $income,
                'spending' => $spending,
                'net' => $income - $spending,
                'rate' => $income > 0 ? ($income - $spending) / $income : null,
                'avg_income' => (int) round($income / max(1, count($withData))),
                'avg_spending' => (int) round($spending / max(1, count($withData))),
                'months_counted' => count($withData),
                'months_saved' => count(array_filter($withData, fn ($r) => $r['net'] > 0)),
            ],
            'best' => $byNet[0] ?? null,
            'worst' => $byNet[count($byNet) - 1] ?? null,
            'through' => $end->copy()->endOfMonth()->min(now())->toDateString(),
        ]);
    }

    /** What you own minus what you owe at each month end, investments included. */
    public function netWorth(Request $request): Response
    {
        $span = $this->span($request);
        $end = now()->startOfMonth();
        $from = $end->copy()->subMonthsNoOverflow($span - 1);
        $accounts = Account::all();
        $inv = new InvestmentReport(InvestmentAccount::with(['balances', 'activities'])->get(), 'all');
        $points = [];
        foreach (ReportData::monthKeys($from, $end) as $ym) {
            $day = Carbon::parse($ym.'-01')->endOfMonth()->startOfDay()->min(now()->startOfDay());
            $points[] = ['month' => $ym, 'label' => self::label($ym), 'date' => $day->toDateString()] + ReportData::worthOn($day, $accounts, $inv);
        }
        $now = end($points);
        $first = $points[0];

        $avgSpending = ReportData::averageSpending(ReportData::latestMonth());
        $list = Account::withBalance()->where('is_archived', false)->orderBy('name')->get()
            ->map(fn (Account $a) => ['name' => $a->name, 'type' => Account::TYPES[$a->type] ?? $a->type, 'balance' => $a->balance(), 'is_debt' => $a->isDebt()]);
        $investments = InvestmentAccount::with(['balances', 'activities'])->orderBy('name')->get()
            ->map(fn ($a) => ['name' => $a->name, 'type' => InvestmentAccount::KINDS[$a->kind] ?? 'Investment', 'balance' => $inv->valueOn($a, now()->startOfDay()) ?? 0, 'is_debt' => false]);

        return Inertia::render('Reports/NetWorth', [
            'months' => $span,
            'spans' => self::SPANS,
            'points' => $points,
            'now' => $now,
            'change' => $now['net'] - $first['net'],
            'from' => $first['date'],
            'accounts' => $list->concat($investments)->values(),
            'emergency' => [
                'cash' => $now['cash'],
                'monthly_spending' => $avgSpending,
                'months' => $avgSpending > 0 ? $now['cash'] / $avgSpending : null,
            ],
        ]);
    }

    /** Each budget line for a year: budgeted, spent, and the months it went over. */
    public function budget(Request $request): Response
    {
        $latest = ReportData::latestMonth();
        $year = $request->integer('year') ?: $latest->year;
        $lastMonth = $year < $latest->year ? 12 : ($year === $latest->year ? $latest->month : 0);
        $budgets = Budget::pluck('amount', 'category_id');
        $lines = [];
        $monthTotals = [];
        for ($m = 1; $m <= $lastMonth; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $b = (new BudgetMonth($ym))->build();
            $mt = ['month' => $ym, 'label' => self::label($ym, false), 'budget' => 0, 'spent' => 0, 'spent_all' => $b['totals']['spent'] + $b['totals']['uncategorized']];
            foreach ($b['lines'] as $r) {
                if ($r['budget'] === null) {
                    continue;
                }
                $id = $r['category_id'];
                $lines[$id] ??= ['category_id' => $id, 'name' => $r['parent'] ? $r['parent'].' › '.$r['name'] : $r['name'], 'monthly' => $r['budget'], 'hidden' => (bool) $r['hidden'], 'position' => $r['position'], 'spent' => [], 'budget' => 0, 'total' => 0, 'over' => 0];
                $lines[$id]['spent'][$m] = $r['spent'];
                $lines[$id]['budget'] += $r['budget'];
                $lines[$id]['total'] += $r['spent'];
                $lines[$id]['over'] += $r['spent'] > $r['budget'] ? 1 : 0;
                $mt['budget'] += $r['budget'];
                $mt['spent'] += $r['spent'];
            }
            $monthTotals[] = $mt;
        }
        $lines = array_values(array_map(function ($l) use ($lastMonth) {
            $l['spent'] = array_map(fn ($m) => $l['spent'][$m] ?? 0, range(1, max(1, $lastMonth)));

            return $l;
        }, $lines));
        usort($lines, fn ($a, $b) => ($b['total'] - $b['budget']) <=> ($a['total'] - $a['budget']));
        $years = Transaction::selectRaw('min(date) as a, max(date) as b')->first();
        $firstYear = $years?->a ? Carbon::parse($years->a)->year : $latest->year;

        return Inertia::render('Reports/Budget', [
            'year' => $year,
            'years' => range($latest->year, $firstYear),
            'months' => $monthTotals,
            'lines' => $lines,
            'has_budgets' => $budgets->isNotEmpty(),
            'totals' => [
                'budget' => array_sum(array_column($monthTotals, 'budget')),
                'spent' => array_sum(array_column($monthTotals, 'spent')),
                'spent_all' => array_sum(array_column($monthTotals, 'spent_all')),
            ],
        ]);
    }

    private function period(Request $request): array
    {
        $latest = ReportData::latestMonth();
        $p = (string) $request->query('period', '12m');
        if (preg_match('/^\d{4}$/', $p)) {
            return [$p, Carbon::create((int) $p, 1, 1), Carbon::create((int) $p, 12, 31), $p];
        }
        $from = $latest->copy()->subMonthsNoOverflow(11);

        return ['12m', $from, $latest->copy()->endOfMonth(), 'Last 12 months'];
    }

    /** Where the money goes, by who was paid. */
    public function payees(Request $request): Response
    {
        [$key, $from, $to, $label] = $this->period($request);
        $rows = ReportData::payees($from, $to);
        $total = array_sum(array_column($rows, 'total'));
        $latest = ReportData::latestMonth();
        $years = Transaction::selectRaw('min(date) as a')->first();
        $firstYear = $years?->a ? Carbon::parse($years->a)->year : $latest->year;

        return Inertia::render('Reports/Payees', [
            'period' => $key,
            'label' => $label,
            'periods' => array_merge(['12m' => 'Last 12 months'], array_combine(array_map('strval', range($latest->year, $firstYear)), array_map('strval', range($latest->year, $firstYear)))),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => array_slice($rows, 0, 200),
            'count' => count($rows),
            'total' => $total,
            'top10' => $total > 0 ? array_sum(array_column(array_slice($rows, 0, 10), 'total')) / $total : null,
        ]);
    }

    /** One page that sums up a year, to read, print or save as a PDF. */
    public function year(Request $request): Response
    {
        $latest = ReportData::latestMonth();
        $last = Transaction::max('date');
        $year = $request->integer('year') ?: $latest->year;
        $from = Carbon::create($year, 1, 1);
        $partial = $year === now()->year;
        // A year still running is compared with the same part of last year.
        $to = $partial ? Carbon::parse($last ?? now())->min(Carbon::create($year, 12, 31)) : Carbon::create($year, 12, 31);
        $prevFrom = $from->copy()->subYear();
        $prevTo = $to->copy()->subYearNoOverflow();

        $cur = ReportData::totals($from, $to);
        // Only compare when your records reach back over the whole earlier period.
        $first = Transaction::min('date');
        $comparable = $first !== null && Carbon::parse($first)->lte($prevFrom->copy()->endOfMonth());
        $prev = $comparable ? ReportData::totals($prevFrom, $prevTo) : ['income' => 0, 'spending' => 0];
        $rate = fn ($t) => $t['income'] > 0 ? ($t['income'] - $t['spending']) / $t['income'] : null;

        $cats = ReportData::byCategory($from, $to);
        $prevCats = collect($comparable ? ReportData::byCategory($prevFrom, $prevTo) : [])->keyBy(fn ($c) => $c['id'] ?? 0);
        $cats = array_map(fn ($c) => $c + ['previous' => $prevCats[$c['id'] ?? 0]['total'] ?? null], array_slice($cats, 0, 10));

        $months = [];
        foreach (ReportData::months($from, Carbon::create($year, 12, 31)) as $ym => $m) {
            $months[] = $m + ['month' => $ym, 'label' => self::label($ym, false), 'net' => $m['income'] - $m['spending'], 'future' => $ym > $to->format('Y-m')];
        }

        $accounts = Account::all();
        $invAccounts = InvestmentAccount::with(['balances', 'activities'])->get();
        $invAll = new InvestmentReport($invAccounts, 'all');
        $worthStart = ReportData::worthOn($from->copy()->subDay(), $accounts, $invAll);
        $worthEnd = ReportData::worthOn($to->copy()->min(now()), $accounts, $invAll);

        $inv = new InvestmentReport($invAccounts, 'custom', $partial ? now() : $to->copy(), $from->copy()->subDay());
        $invRows = $invAccounts->map(fn ($a) => $inv->account($a))->all();
        $invSummary = $inv->summary($invRows);

        // Budget: lines with a budget, over the months of the year so far.
        $budgeted = 0;
        $spentOnBudget = 0;
        $over = [];
        for ($m = 1; $m <= ($partial ? $to->month : 12); $m++) {
            $b = (new BudgetMonth(sprintf('%04d-%02d', $year, $m)))->build();
            foreach ($b['lines'] as $r) {
                if ($r['budget'] === null) {
                    continue;
                }
                $budgeted += $r['budget'];
                $spentOnBudget += $r['spent'];
                $name = $r['parent'] ? $r['parent'].' › '.$r['name'] : $r['name'];
                $over[$name] = ($over[$name] ?? 0) + $r['spent'] - $r['budget'];
            }
        }
        arsort($over);
        $years = Transaction::selectRaw('min(date) as a')->first();
        $firstYear = $years?->a ? Carbon::parse($years->a)->year : $latest->year;
        $avgSpending = ReportData::averageSpending($to->copy()->addDay()->startOfMonth(), 6);

        return Inertia::render('Reports/Year', [
            'year' => $year,
            'years' => range($latest->year, $firstYear),
            'partial' => $partial,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'current' => $cur + ['net' => $cur['income'] - $cur['spending'], 'rate' => $rate($cur)],
            'previous' => ($prev['income'] || $prev['spending']) ? $prev + ['net' => $prev['income'] - $prev['spending'], 'rate' => $rate($prev)] : null,
            'months' => $months,
            'categories' => $cats,
            'payees' => array_slice(ReportData::payees($from, $to), 0, 10),
            'worth' => ['start' => $worthStart, 'end' => $worthEnd],
            'investments' => $invSummary['with_value'] ? $invSummary : null,
            'budget' => $budgeted ? [
                'budgeted' => $budgeted,
                'spent' => $spentOnBudget,
                'over' => self::named(array_slice(array_filter($over, fn ($v) => $v > 0), 0, 5, true)),
                'under' => self::named(array_slice(array_reverse(array_filter($over, fn ($v) => $v < 0), true), 0, 5, true)),
            ] : null,
            'emergency' => ['cash' => $worthEnd['cash'], 'monthly_spending' => $avgSpending, 'months' => $avgSpending > 0 ? $worthEnd['cash'] / $avgSpending : null],
        ]);
    }
}
