<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Rules\MoneyAmount;
use App\Support\BudgetMonth;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $this->month($request);
        $b = new BudgetMonth($month);

        return Inertia::render('Budgets/Index', [
            'month' => $month,
            'monthStart' => $b->start->toDateString(),
            'monthEnd' => $b->end->toDateString(),
            'isCurrent' => $month === now()->format('Y-m'),
            'daysLeft' => $month === now()->format('Y-m') ? (int) now()->diffInDays($b->end->copy()->startOfDay()) + 1 : null,
            ...$b->build(),
        ]);
    }

    /** Sets one category's monthly budget; an empty amount removes it. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'amount' => ['nullable', new MoneyAmount],
        ]);
        $category = Category::findOrFail($data['category_id']);
        if ($category->kind !== 'expense') {
            return back()->withErrors(['amount' => 'Budgets are for spending categories.']);
        }

        if (blank($data['amount'] ?? null)) {
            Budget::where('category_id', $category->id)->delete();
        } else {
            Budget::updateOrCreate(['category_id' => $category->id], ['amount' => abs(Money::toCents((string) $data['amount']))]);
        }

        return back();
    }

    /** Fills every empty budget with the 3-month average, rounded up to whole dollars. */
    public function fillFromAverages(Request $request): RedirectResponse
    {
        $b = new BudgetMonth($this->month($request));
        $n = 0;
        foreach ($b->build()['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                if ($r['budget'] === null && $r['average'] > 0) {
                    Budget::create(['category_id' => $r['category_id'], 'amount' => (int) ceil($r['average'] / 100) * 100]);
                    $n++;
                }
            }
        }

        return back()->with('status', $n ? "Filled in $n budgets from your averages." : 'No empty budgets with past spending to fill.');
    }

    private function month(Request $request): string
    {
        $m = (string) $request->input('month', now()->format('Y-m'));

        return preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $m) ? $m : now()->format('Y-m');
    }
}
