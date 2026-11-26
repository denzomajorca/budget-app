<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::withBalance()->withCount('transactions')->orderBy('is_archived')->orderBy('name')->get();

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts->map(fn (Account $a) => self::present($a) + [
                'transactions_count' => $a->transactions_count,
            ]),
            'types' => Account::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Account::create($this->validated($request));

        return back()->with('status', 'Account added.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $account->update($this->validated($request) + ['is_archived' => $request->boolean('is_archived')]);

        return back()->with('status', 'Account saved.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $account->delete();

        return back()->with('status', 'Account deleted.');
    }

    /**
     * "My bank says the balance is X on this date": works out the starting
     * balance so the running balance matches the bank.
     */
    public function setBalance(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'balance' => ['required', new MoneyAmount],
            'as_of' => ['required', 'date_format:Y-m-d'],
        ]);
        $target = Money::toCents((string) $data['balance']);
        if ($account->isDebt()) {
            $target = -abs($target);
        }
        $upTo = (int) $account->transactions()->whereDate('date', '<=', $data['as_of'])->sum('amount');
        $first = $account->transactions()->min('date');

        $account->update([
            'opening_balance' => $target - $upTo,
            'opening_date' => $first ? Carbon::parse($first)->subDay()->toDateString() : $data['as_of'],
        ]);

        return back()->with('status', 'Balance set.');
    }

    /** Shape sent to the pages. Balances are cents; debts are negative. */
    public static function present(Account $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'type_label' => Account::TYPES[$a->type] ?? $a->type,
            'institution' => $a->institution,
            'opening_balance' => $a->opening_balance,
            'opening_date' => $a->opening_date?->format('Y-m-d'),
            'is_archived' => $a->is_archived,
            'is_debt' => $a->isDebt(),
            'balance' => $a->balance(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'institution' => ['nullable', 'string', 'max:100'],
            'opening_balance' => ['nullable', new MoneyAmount],
            'opening_date' => ['nullable', 'date'],
        ]);

        // For cards and loans people type what they owe as a positive number;
        // it is stored as a negative balance.
        $cents = filled($data['opening_balance'] ?? null) ? Money::toCents((string) $data['opening_balance']) : 0;
        if (in_array($data['type'], ['credit_card', 'loan'], true)) {
            $cents = -abs($cents);
        }
        $data['opening_balance'] = $cents;

        return $data;
    }
}
