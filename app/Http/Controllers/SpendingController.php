<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\BudgetMonth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spending by category for a month, quarter or year, compared with the
 * period before or the same period a year earlier. When the chosen period
 * isn't finished (or the imported files stop part-way through it), the
 * comparison period is cut to the same number of days.
 */
class SpendingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $type = in_array($request->input('type'), ['month', 'quarter', 'year'], true) ? $request->input('type') : 'month';
        $last = Transaction::max('date');
        $first = Transaction::min('date');
        $lastDay = $last ? Carbon::parse($last)->startOfDay() : now()->startOfDay();
        $today = now()->startOfDay();
        $periods = $this->periods($type, $first ? Carbon::parse($first) : $today, $lastDay->gt($today) ? $lastDay : $today);

        $key = (string) $request->input('key', '');
        if (! in_array($key, array_column($periods, 'key'), true)) {
            $key = $this->keyFor($type, $lastDay); // default: the period with the latest transaction
        }
        $cmp = $type === 'year' ? 'ly' : ($request->input('cmp') === 'ly' ? 'ly' : 'prev');

        [$aStart, $aEnd] = $this->range($type, $key);
        $shift = $cmp === 'ly' ? 12 : ['month' => 1, 'quarter' => 3, 'year' => 12][$type];
        $bStart = $aStart->copy()->subMonthsNoOverflow($shift);
        $bEnd = $aEnd->copy()->subMonthsNoOverflow($shift)->endOfMonth();

        $cutoff = $lastDay->lt($aEnd) ? $lastDay->copy() : null;
        if ($cutoff && $cutoff->lt($aStart)) {
            $cutoff = null; // nothing in this period yet
        }
        $aTo = $cutoff ?? $aEnd;
        $bTo = $bEnd;
        if ($cutoff) {
            $same = $bStart->copy()->addDays((int) $aStart->diffInDays($cutoff));
            $bTo = $same->lt($bEnd) ? $same : $bEnd;
        }

        $A = BudgetMonth::spent($aStart, $aTo);
        $B = BudgetMonth::spent($bStart, $bTo);

        $groups = [];
        foreach (Category::whereNull('parent_id')->where('kind', 'expense')->with('children')->get() as $main) {
            $rows = [];
            foreach ($main->children as $sub) {
                $rows[] = ['category' => (string) $sub->id, 'name' => $sub->name, 'a' => $A[$sub->id] ?? 0, 'b' => $B[$sub->id] ?? 0];
            }
            if ($main->children->isNotEmpty() && (($A[$main->id] ?? 0) || ($B[$main->id] ?? 0))) {
                $rows[] = ['category' => 'only-'.$main->id, 'name' => 'Other '.$main->name, 'a' => $A[$main->id] ?? 0, 'b' => $B[$main->id] ?? 0];
            }
            $rows = array_values(array_filter($rows, fn ($r) => $r['a'] || $r['b']));
            usort($rows, fn ($x, $y) => [$y['a'], $y['b']] <=> [$x['a'], $x['b']]);
            $a = array_sum(array_column($rows, 'a')) + ($main->children->isEmpty() ? ($A[$main->id] ?? 0) : 0);
            $b = array_sum(array_column($rows, 'b')) + ($main->children->isEmpty() ? ($B[$main->id] ?? 0) : 0);
            if ($a || $b) {
                $groups[] = ['id' => $main->id, 'name' => $main->name, 'a' => $a, 'b' => $b, 'rows' => $rows];
            }
        }
        usort($groups, fn ($x, $y) => [$y['a'], $y['b']] <=> [$x['a'], $x['b']]);

        return Inertia::render('Spending/Index', [
            'type' => $type,
            'period' => $key,
            'cmp' => $cmp,
            'periods' => $periods,
            'a' => ['label' => $this->label($type, $key), 'from' => $aStart->toDateString(), 'to' => $aTo->toDateString()],
            'b' => ['label' => $this->label($type, $this->keyFor($type, $bStart)), 'from' => $bStart->toDateString(), 'to' => $bTo->toDateString(), 'hasData' => $first !== null && Carbon::parse($first)->lte($bTo)],
            'partial' => $cutoff ? $cutoff->toDateString() : null,
            'groups' => $groups,
            'totals' => ['a' => array_sum(array_column($groups, 'a')), 'b' => array_sum(array_column($groups, 'b'))],
        ]);
    }

    private function keyFor(string $type, Carbon $d): string
    {
        return match ($type) {
            'month' => $d->format('Y-m'),
            'quarter' => $d->year.'-Q'.$d->quarter,
            'year' => (string) $d->year,
        };
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function range(string $type, string $key): array
    {
        return match ($type) {
            'month' => [($s = Carbon::createFromFormat('!Y-m', $key))->copy()->startOfMonth(), $s->copy()->endOfMonth()->startOfDay()],
            'quarter' => [($s = Carbon::create((int) substr($key, 0, 4), ((int) substr($key, -1) - 1) * 3 + 1, 1)), $s->copy()->addMonths(2)->endOfMonth()->startOfDay()],
            'year' => [Carbon::create((int) $key, 1, 1), Carbon::create((int) $key, 12, 31)],
        };
    }

    private function label(string $type, string $key): string
    {
        [$s] = $this->range($type, $key);

        return match ($type) {
            'month' => $s->format('F Y'),
            'quarter' => 'Q'.$s->quarter.' '.$s->year,
            'year' => (string) $s->year,
        };
    }

    /** Newest first, from the first transaction's period to today's. */
    private function periods(string $type, Carbon $from, Carbon $to): array
    {
        $out = [];
        $d = $to->copy()->startOfMonth();
        $stop = $this->keyFor($type, $from);
        for ($i = 0; $i < 600; $i++) {
            $k = $this->keyFor($type, $d);
            if (! in_array($k, array_column($out, 'key'), true)) {
                $out[] = ['key' => $k, 'label' => $this->label($type, $k)];
            }
            if ($k === $stop) {
                break;
            }
            $d->subMonthNoOverflow();
        }

        return $out;
    }
}
