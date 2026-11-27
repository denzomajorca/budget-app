<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spending › By month: one row per category (subcategories inside), one
 * column per month, then Total and Average. Income first; transfers left out.
 * Amounts are shown as positive numbers in both sections (money in for
 * income, money out for spending).
 */
class MonthlyController extends Controller
{
    /** A month counts as "high" when it is this much over the category's average... */
    public const HIGH_RATIO = 1.25;

    /** ...and at least this many cents over it, so small categories don't light up. */
    public const HIGH_MIN = 5000;

    public const MAX_MONTHS = 36;

    public function __invoke(Request $request): Response
    {
        $valid = fn ($v) => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v);
        $last = Transaction::max('date');
        $year = $last ? Carbon::parse($last)->year : now()->year;
        $from = $valid($request->input('from')) ? Carbon::parse($request->input('from').'-01') : Carbon::create($year, 1, 1);
        $to = $valid($request->input('to')) ? Carbon::parse($request->input('to').'-01') : Carbon::create($year, 12, 1);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
            $to = $from->copy()->addMonthsNoOverflow(self::MAX_MONTHS - 1);
        }

        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }
        // Months still to come don't pull the average down.
        $through = min($to->format('Y-m'), now()->format('Y-m'));
        $counted = count(array_filter($months, fn ($m) => $m <= $through)) ?: 1;

        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', date)" : "DATE_FORMAT(date, '%Y-%m')";
        $sums = []; // [category id or 0 for none][Y-m] => cents
        Transaction::whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->copy()->endOfMonth()->toDateString())
            ->groupBy('category_id', DB::raw($monthExpr))
            ->select('category_id', DB::raw("$monthExpr as ym"), DB::raw('sum(amount) as total'))
            ->get()
            ->each(function ($r) use (&$sums) {
                $sums[(int) $r->category_id][$r->ym] = (int) $r->total;
            });

        $range = fn (string $ym) => [Carbon::parse($ym.'-01')->toDateString(), Carbon::parse($ym.'-01')->endOfMonth()->toDateString()];
        $row = function (string $label, array $ids, string $filter, int $sign) use ($sums, $months, $counted, $through) {
            $values = [];
            foreach ($months as $m) {
                $v = 0;
                foreach ($ids as $id) {
                    $v += $sums[$id][$m] ?? 0;
                }
                $values[$m] = $sign * $v;
            }
            $total = array_sum($values);
            $avg = (int) round(array_sum(array_filter($values, fn ($v, $m) => $m <= $through, ARRAY_FILTER_USE_BOTH)) / $counted);
            $high = [];
            foreach ($values as $m => $v) {
                if ($avg > 0 && $v > $avg * self::HIGH_RATIO && $v - $avg >= self::HIGH_MIN) {
                    $high[] = $m;
                }
            }

            return ['label' => $label, 'filter' => $filter, 'values' => array_values($values), 'total' => $total, 'average' => $avg, 'high' => $high];
        };

        $sections = [];
        foreach (['income' => 1, 'expense' => -1] as $kind => $sign) {
            $groups = [];
            foreach (Category::whereNull('parent_id')->where('kind', $kind)->with('children')->orderBy('name')->get() as $main) {
                $ids = $main->idsWithChildren();
                $children = [];
                foreach ($main->children as $c) {
                    $children[] = $row($c->name, [$c->id], (string) $c->id, $sign);
                }
                if ($children && array_filter($sums[$main->id] ?? [])) {
                    $children[] = $row('Other '.$main->name, [$main->id], 'only-'.$main->id, $sign);
                }
                $g = $row($main->name, $ids, (string) $main->id, $sign);
                $g['children'] = array_values(array_filter($children, fn ($c) => $c['total'] !== 0 || array_filter($c['values'])));
                if ($g['total'] !== 0 || array_filter($g['values'])) {
                    $groups[] = $g;
                }
            }
            if ($kind === 'expense' && array_filter($sums[0] ?? [])) {
                $g = $row('Uncategorized', [0], 'none', $sign);
                $g['children'] = [];
                $groups[] = $g;
            }
            $sum = function (string $label) use ($groups, $months, $counted, $through) {
                $values = array_fill(0, count($months), 0);
                foreach ($groups as $g) {
                    foreach ($g['values'] as $i => $v) {
                        $values[$i] += $v;
                    }
                }
                $counted_sum = 0;
                foreach ($months as $i => $m) {
                    if ($m <= $through) {
                        $counted_sum += $values[$i];
                    }
                }

                return ['label' => $label, 'values' => $values, 'total' => array_sum($values), 'average' => (int) round($counted_sum / $counted)];
            };
            $sections[] = ['kind' => $kind, 'title' => $kind === 'income' ? 'Income' : 'Spending', 'groups' => $groups, 'totals' => $sum($kind === 'income' ? 'Total income' : 'Total spending')];
        }

        $net = [];
        foreach ($months as $i => $m) {
            $net[] = $sections[0]['totals']['values'][$i] - $sections[1]['totals']['values'][$i];
        }

        return Inertia::render('Spending/Monthly', [
            'from' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'months' => array_map(fn ($m) => ['key' => $m, 'label' => Carbon::parse($m.'-01')->format('M'), 'year' => Carbon::parse($m.'-01')->format('Y'), 'from' => $range($m)[0], 'to' => $range($m)[1], 'future' => $m > $through], $months),
            'range' => ['from' => $from->toDateString(), 'to' => $to->copy()->endOfMonth()->toDateString()],
            'sections' => $sections,
            'net' => ['values' => $net, 'total' => array_sum($net), 'average' => $sections[0]['totals']['average'] - $sections[1]['totals']['average']],
            'counted' => $counted,
            'latest_year' => $year,
        ]);
    }
}
