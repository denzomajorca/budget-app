<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Support\Money;
use App\Support\Transfers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'transfers' => ['nullable', Rule::in(['unpaired'])],
            'review' => ['nullable', Rule::in(['1'])],
        ]);

        $query = $this->filtered($filters);
        $totals = (clone $query)->selectRaw('coalesce(sum(case when amount > 0 then amount else 0 end), 0) as money_in, coalesce(sum(case when amount < 0 then amount else 0 end), 0) as money_out, count(*) as n')->first();

        $page = $query->with(['account:id,name', 'category.parent', 'transfer.account:id,name'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (Transaction $t) => $this->present($t, ($filters['transfers'] ?? null) === 'unpaired'));

        return Inertia::render('Transactions/Index', [
            'transactions' => $page,
            'totals' => ['in' => (int) $totals->money_in, 'out' => (int) $totals->money_out, 'count' => (int) $totals->n],
            'filters' => array_merge(['account' => null, 'category' => null, 'from' => null, 'to' => null, 'q' => null, 'import' => null, 'transfers' => null, 'review' => null], $filters),
            'unpaired_transfers' => Transfers::unpairedCount(),
            'to_review' => Transaction::where('needs_review', true)->count(),
            'uncategorized' => Transaction::whereNull('category_id')->count(),
            'rules' => PayeeRule::with('category.parent')->orderBy('match')->get()->map(fn (PayeeRule $r) => ['id' => $r->id, 'match' => $r->match, 'category' => $r->category?->label()]),
            'accounts' => Account::orderBy('is_archived')->orderBy('name')->get(['id', 'name', 'is_archived']),
            'categories' => Category::options(),
        ]);
    }

    private function present(Transaction $t, bool $suggest): array
    {
        $match = $suggest ? Transfers::suggestion($t) : null;

        return [
            'id' => $t->id,
            'date' => $t->date->format('Y-m-d'),
            'payee' => $t->payee,
            'amount' => $t->amount,
            'memo' => $t->memo,
            'account_id' => $t->account_id,
            'account' => $t->account?->name,
            'category_id' => $t->category_id,
            'category' => $t->category?->label(),
            'needs_review' => $t->needs_review,
            'is_transfer' => $t->category?->kind === 'transfer',
            'transfer_outside' => $t->transfer_outside,
            // "CHASE Checking → CHASE Savings", from where the money left to where it arrived.
            'transfer' => $t->transfer ? ($t->amount < 0
                ? $t->account?->name.' → '.$t->transfer->account?->name
                : $t->transfer->account?->name.' → '.$t->account?->name) : null,
            'match' => $match ? [
                'id' => $match->id,
                'date' => $match->date->format('Y-m-d'),
                'payee' => $match->payee,
                'amount' => $match->amount,
                'account' => $match->account?->name,
                'category' => $match->category?->label(),
            ] : null,
        ];
    }

    /** Link an unpaired transfer with its other side, picked by you. */
    public function pair(Request $request, Transaction $transaction): RedirectResponse
    {
        $other = Transaction::findOrFail($request->validate(['other_id' => ['required', 'integer']])['other_id']);
        if ($other->account_id === $transaction->account_id || $other->amount !== -$transaction->amount || $other->transfer_id || $transaction->transfer_id) {
            return back()->withErrors(['other_id' => "Those two can't be paired: they need opposite amounts in different accounts, and neither can already be paired."]);
        }
        if ($transaction->category?->kind !== 'transfer' && $other->category?->kind === 'transfer') {
            [$transaction, $other] = [$other, $transaction]; // keep the side that is already a transfer as the guide
        }
        Transfers::link($transaction, $other);

        return back()->with('status', 'Paired as one transfer.');
    }

    public function store(Request $request): RedirectResponse
    {
        Transaction::create($this->validated($request));
        Transfers::pair();

        return back()->with('status', 'Transaction added.');
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $this->validated($request);
        $relinks = $data['amount'] !== $transaction->amount || (int) $data['account_id'] !== $transaction->account_id
            || abs($transaction->date->diffInDays($data['date'])) > Transfers::WINDOW_DAYS
            || ! ($data['category_id'] && Category::whereKey($data['category_id'])->where('kind', 'transfer')->exists());
        DB::transaction(function () use ($transaction, $data, $relinks) {
            if ($relinks && $transaction->transfer_id) {
                Transaction::whereKey($transaction->transfer_id)->update(['transfer_id' => null]);
                $data['transfer_id'] = null;
            }
            $transaction->update($data + ['needs_review' => false]);
        });
        Transfers::pair();

        return back()->with('status', 'Transaction saved.');
    }

    /**
     * Change many transactions at once: the ticked ones (`ids`), or every
     * one that matches the current filters (`all`). Sets a category and/or
     * marks them reviewed.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required_without:all', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'all' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'action' => ['required', Rule::in(['category', 'reviewed', 'outside'])],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ]);

        $query = ! empty($data['all'])
            ? $this->filtered(array_intersect_key((array) ($data['filters'] ?? []), array_flip(['account', 'category', 'from', 'to', 'q', 'import', 'transfers', 'review'])))
            : Transaction::whereIn('id', $data['ids']);
        $ids = $query->pluck('id');

        $n = DB::transaction(function () use ($ids, $data) {
            $n = 0;
            foreach ($ids->chunk(500) as $chunk) {
                $q = Transaction::whereIn('id', $chunk);
                if ($data['action'] === 'category') {
                    $toTransfer = $data['category_id'] && Category::whereKey($data['category_id'])->where('kind', 'transfer')->exists();
                    if (! $toTransfer) {
                        // No longer a transfer, so let go of the other side too.
                        Transaction::whereIn('transfer_id', $chunk)->update(['transfer_id' => null]);
                        $q->update(['transfer_id' => null]);
                    }
                    $n += Transaction::whereIn('id', $chunk)->update(['category_id' => $data['category_id'], 'needs_review' => false]);
                } elseif ($data['action'] === 'outside') {
                    $n += $q->whereNull('transfer_id')->update(['transfer_outside' => true]);
                } else {
                    $n += $q->update(['needs_review' => false]);
                }
            }

            return $n;
        });
        if ($data['action'] === 'category') {
            Transfers::pair();
        }

        $what = $n === 1 ? '1 transaction' : $n.' transactions';

        return back()->with('status', match ($data['action']) {
            'category' => "Category set on {$what}.",
            'outside' => "{$what} marked as going to or from an account outside the app.",
            default => "{$what} marked reviewed.",
        });
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
                if (str_starts_with($cat, 'only-')) {
                    return $q->where('category_id', (int) substr($cat, 5)); // a main category without its subcategories
                }
                $category = Category::find((int) $cat);

                return $category ? $q->whereIn('category_id', $category->idsWithChildren()) : $q->whereRaw('1 = 0');
            })
            ->when(($f['transfers'] ?? null) === 'unpaired', fn ($q) => $q->whereNull('transfer_id')->where('transfer_outside', false)->whereHas('category', fn ($c) => $c->where('kind', 'transfer')))
            ->when(($f['review'] ?? null) === '1', fn ($q) => $q->where('needs_review', true))
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
