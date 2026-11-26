<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $today = now()->startOfDay();
        $items = PlanItem::with('category.parent')->orderBy('name')->get();

        // Averages use the 90 days up to the latest transaction, so a file
        // imported a few weeks ago still gives a fair monthly figure.
        $last = Transaction::max('date');
        $avgTo = $last ? Carbon::parse($last)->startOfDay() : $today;
        $avgFrom = $avgTo->copy()->subDays(89);
        $spent = Transaction::whereNotNull('category_id')->whereBetween('date', [$avgFrom->toDateString(), $avgTo->toDateString()])
            ->groupBy('category_id')->select('category_id', DB::raw('sum(amount) as total'))->pluck('total', 'category_id');
        $perMonth = fn (int $cents) => (int) round($cents * (365.25 / 12) / 90);
        $categories = Category::with('children')->get()->keyBy('id');
        $avgFor = function (?int $categoryId) use ($categories, $spent, $perMonth): ?int {
            if (! $categoryId || ! isset($categories[$categoryId])) {
                return null;
            }
            $ids = array_merge([$categoryId], $categories[$categoryId]->children->pluck('id')->all());

            return max(0, $perMonth(-(int) collect($ids)->sum(fn ($id) => $spent[$id] ?? 0)));
        };
        $incomeIds = $categories->where('kind', 'income')->keys();
        $income = $perMonth((int) $incomeIds->sum(fn ($id) => $spent[$id] ?? 0));

        $present = fn (PlanItem $i) => [
            'id' => $i->id,
            'group' => $i->group,
            'name' => $i->name,
            'category_id' => $i->category_id,
            'category' => $i->category?->label(),
            'amount' => $i->amount,
            'every_months' => $i->every_months,
            'frequency' => $i->every_months ? (PlanItem::FREQUENCIES[$i->every_months] ?? "Every {$i->every_months} months") : null,
            'next_due' => $i->group === 'bill' ? ($i->dueDates($today, $today->copy()->addYears(2))[0] ?? $i->next_due)?->format('Y-m-d') : null,
            'is_estimate' => $i->is_estimate,
            'per_month' => match ($i->group) {
                'bill' => (int) round($i->amount / max(1, $i->every_months)),
                'flex' => $i->amount,
                'goal' => $i->monthlySetAside($today),
            },
            'saved' => $i->saved,
            'due_by' => $i->due_by?->format('Y-m-d'),
            'average' => $avgFor($i->category_id),
        ];

        $bills = $items->where('group', 'bill')->values();
        $flex = $items->where('group', 'flex')->values();
        $goals = $items->where('group', 'goal')->values();

        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $start = $today->copy()->startOfMonth()->addMonthsNoOverflow($m);
            $end = $start->copy()->endOfMonth();
            $months[] = [
                'month' => $start->format('Y-m'),
                'bills' => $bills->sum(fn (PlanItem $b) => count($b->dueDates($start, $end)) * $b->amount),
                'flex' => $flex->sum('amount'),
                'goals' => $goals->sum(fn (PlanItem $g) => ! $g->due_by || $start->lt($g->due_by->copy()->startOfMonth()) ? $g->monthlySetAside($today) : 0),
            ];
        }

        $upcoming = $bills->flatMap(fn (PlanItem $b) => array_map(fn ($d) => ['date' => $d->format('Y-m-d'), 'name' => $b->name, 'amount' => $b->amount, 'is_estimate' => $b->is_estimate], $b->dueDates($today, $today->copy()->addDays(60))))
            ->sortBy('date')->values();

        return Inertia::render('Plan/Index', [
            'bills' => $bills->map($present),
            'flex' => $flex->map($present),
            'goals' => $goals->map($present),
            'months' => $months,
            'upcoming' => $upcoming,
            'income' => $income,
            'averagesThrough' => $last ? $avgTo->toDateString() : null,
            'categories' => array_values(array_map(fn ($o) => $o + ['average' => $avgFor($o['id'])], array_filter(Category::options(), fn ($o) => $o['kind'] === 'expense'))),
            'frequencies' => PlanItem::FREQUENCIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PlanItem::create($this->validated($request));

        return back()->with('status', 'Added to your plan.');
    }

    public function update(Request $request, PlanItem $item): RedirectResponse
    {
        $item->update($this->validated($request, $item->group));

        return back()->with('status', 'Saved.');
    }

    public function destroy(PlanItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('status', 'Removed from your plan.');
    }

    private function validated(Request $request, ?string $group = null): array
    {
        $group ??= $request->input('group');
        $data = $request->validate([
            'group' => [$group ? 'nullable' : 'required', Rule::in(['bill', 'flex', 'goal'])],
            'name' => ['required', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'amount' => ['required', new MoneyAmount],
            'every_months' => [$group === 'bill' ? 'required' : 'nullable', Rule::in(array_keys(PlanItem::FREQUENCIES))],
            'next_due' => [$group === 'bill' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'is_estimate' => ['nullable', 'boolean'],
            'saved' => ['nullable', new MoneyAmount],
            'due_by' => [$group === 'goal' ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ], ['next_due.required' => 'Pick the next date it is due.', 'due_by.required' => 'Pick when you need the money.']);

        return [
            'group' => $group,
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'amount' => abs(Money::toCents((string) $data['amount'])),
            'every_months' => $group === 'bill' ? (int) $data['every_months'] : null,
            'next_due' => $group === 'bill' ? $data['next_due'] : null,
            'is_estimate' => $group === 'bill' && (bool) ($data['is_estimate'] ?? false),
            'saved' => $group === 'goal' && filled($data['saved'] ?? null) ? abs(Money::toCents((string) $data['saved'])) : 0,
            'due_by' => $group === 'goal' ? $data['due_by'] : null,
        ];
    }
}
