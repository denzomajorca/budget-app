<?php

namespace App\Support;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Budget figures for one month: per spending category, the monthly budget,
 * what was spent (refunds count against it), and the average of the three
 * months before. Main categories add up their subcategories.
 */
class BudgetMonth
{
    public Carbon $start;

    public Carbon $end;

    public function __construct(string $month)
    {
        $this->start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $this->end = $this->start->copy()->endOfMonth();
    }

    /** @return array<int,int> category id => cents spent (positive = spent) */
    public static function spent(Carbon $from, Carbon $to): array
    {
        return Transaction::whereNotNull('category_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->groupBy('category_id')
            ->select('category_id', DB::raw('sum(amount) as total'))
            ->pluck('total', 'category_id')
            ->map(fn ($t) => -(int) $t)
            ->all();
    }

    public function build(): array
    {
        $budgets = Budget::pluck('amount', 'category_id')->all();
        $spent = self::spent($this->start, $this->end);
        $avgFrom = $this->start->copy()->subMonthsNoOverflow(3);
        $prior = self::spent($avgFrom, $this->start->copy()->subDay());
        $avg = fn (int $id) => (int) round(($prior[$id] ?? 0) / 3);

        $groups = [];
        $tot = ['budget' => 0, 'spent' => 0, 'unbudgeted' => 0];
        $mains = Category::whereNull('parent_id')->where('kind', 'expense')->with('children')->orderBy('name')->get();

        foreach ($mains as $main) {
            $rows = [];
            foreach ($main->children as $sub) {
                $rows[] = $this->row($sub->id, $sub->name, $budgets, $spent, $avg($sub->id)) + $this->layout($sub, $main->name);
            }
            $own = $this->row($main->id, $main->children->isEmpty() ? $main->name : 'Other '.$main->name, $budgets, $spent, $avg($main->id));
            $own['is_main'] = true;
            $own += $this->layout($main, $main->children->isEmpty() ? null : $main->name);
            // A main category with subcategories only shows its own row when it is used.
            if ($main->children->isEmpty() || $own['budget'] !== null || $own['spent'] !== 0 || $own['average'] !== 0) {
                $rows[] = $own;
            }

            $g = [
                'id' => $main->id,
                'name' => $main->name,
                'has_children' => $main->children->isNotEmpty(),
                'budget' => array_sum(array_map(fn ($r) => $r['budget'] ?? 0, $rows)),
                'spent' => array_sum(array_column($rows, 'spent')),
                'average' => array_sum(array_column($rows, 'average')),
                'rows' => $rows,
            ];
            $tot['budget'] += $g['budget'];
            $tot['spent'] += $g['spent'];
            $tot['unbudgeted'] += array_sum(array_map(fn ($r) => $r['budget'] === null ? max(0, $r['spent']) : 0, $rows));
            $groups[] = $g;
        }

        $uncategorized = -(int) Transaction::whereNull('category_id')->where('amount', '<', 0)
            ->whereDate('date', '>=', $this->start->toDateString())->whereDate('date', '<=', $this->end->toDateString())->sum('amount');

        // One flat list for the page: subcategories, and main categories that have none, in your order.
        $lines = [];
        foreach ($groups as $g) {
            foreach ($g['rows'] as $r) {
                $lines[] = $r;
            }
        }
        $natural = array_flip(array_column($lines, 'category_id'));
        usort($lines, fn ($a, $b) => [$a['position'] ?? PHP_INT_MAX, $natural[$a['category_id']]] <=> [$b['position'] ?? PHP_INT_MAX, $natural[$b['category_id']]]);

        return ['groups' => $groups, 'lines' => $lines, 'totals' => $tot + ['uncategorized' => $uncategorized]];
    }

    /** @return array{parent:?string,hidden:?bool,position:?int} */
    private function layout(Category $c, ?string $parent): array
    {
        return ['parent' => $parent, 'hidden' => $c->budget_hidden, 'position' => $c->budget_position];
    }

    private function row(int $id, string $name, array $budgets, array $spent, int $average): array
    {
        return [
            'category_id' => $id,
            'name' => $name,
            'is_main' => false,
            'budget' => isset($budgets[$id]) ? (int) $budgets[$id] : null,
            'spent' => (int) ($spent[$id] ?? 0),
            'average' => max(0, $average),
        ];
    }
}
