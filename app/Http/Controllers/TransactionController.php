<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'import' => ['nullable', 'integer'],
        ]);

        $query = $this->filtered($filters);
        $totals = (clone $query)->selectRaw('coalesce(sum(case when amount > 0 then amount else 0 end), 0) as money_in, coalesce(sum(case when amount < 0 then amount else 0 end), 0) as money_out, count(*) as n')->first();

        $page = $query->with(['account:id,name', 'category.parent'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (Transaction $t) => [
                'id' => $t->id,
                'date' => $t->date->format('Y-m-d'),
                'payee' => $t->payee,
                'amount' => $t->amount,
                'memo' => $t->memo,
                'account_id' => $t->account_id,
                'account' => $t->account?->name,
                'category_id' => $t->category_id,
                'category' => $t->category?->label(),
            ]);

        return Inertia::render('Transactions/Index', [
            'transactions' => $page,
            'totals' => ['in' => (int) $totals->money_in, 'out' => (int) $totals->money_out, 'count' => (int) $totals->n],
            'filters' => array_merge(['account' => null, 'category' => null, 'from' => null, 'to' => null, 'q' => null, 'import' => null], $filters),
            'accounts' => Account::orderBy('is_archived')->orderBy('name')->get(['id', 'name', 'is_archived']),
            'categories' => Category::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Transaction::create($this->validated($request));

        return back()->with('status', 'Transaction added.');
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $transaction->update($this->validated($request));

        return back()->with('status', 'Transaction saved.');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $transaction->delete();

        return back()->with('status', 'Transaction deleted.');
    }

    private function filtered(array $f): Builder
    {
        return Transaction::query()
            ->when($f['account'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($f['category'] ?? null, function ($q, $cat) {
                if ($cat === 'none') {
                    return $q->whereNull('category_id');
                }
                $category = Category::find((int) $cat);

                return $category ? $q->whereIn('category_id', $category->idsWithChildren()) : $q->whereRaw('1 = 0');
            })
            ->when($f['import'] ?? null, fn ($q, $id) => $q->where('import_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($f['q'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('payee', 'like', $like)->orWhere('memo', 'like', $like));
            });
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'payee' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::in(['out', 'in'])],
            'amount' => ['required', new MoneyAmount],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'memo' => ['nullable', 'string', 'max:1000'],
        ]);

        $cents = abs(Money::toCents((string) $data['amount']));
        $data['amount'] = $data['direction'] === 'out' ? -$cents : $cents;
        unset($data['direction']);

        return $data;
    }
}
