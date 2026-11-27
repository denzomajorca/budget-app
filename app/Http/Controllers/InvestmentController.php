<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Models\InvestmentActivity;
use App\Models\InvestmentBalance;
use App\Models\InvestmentHolding;
use App\Models\InvestmentImport;
use App\Rules\MoneyAmount;
use App\Support\InvestmentReport;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvestmentController extends Controller
{
    public function index(Request $request): Response
    {
        $tf = in_array($request->query('tf'), array_keys(InvestmentReport::TIMEFRAMES), true) ? $request->query('tf') : 'ytd';
        $all = InvestmentAccount::with(['balances', 'activities', 'holdings'])->orderBy('name')->get();
        $selected = $request->integer('account');
        $list = $selected && $all->contains('id', $selected) ? $all->where('id', $selected)->values() : $all;

        $report = new InvestmentReport($list, $tf);
        $rows = $list->map(fn ($a) => $report->account($a))->all();
        $ids = $list->pluck('id');

        return Inertia::render('Investments/Index', [
            'filters' => ['tf' => $tf, 'account' => $selected && $all->contains('id', $selected) ? $selected : null],
            'timeframes' => InvestmentReport::TIMEFRAMES,
            'timeframe' => $report->timeframe(),
            'kinds' => InvestmentAccount::KINDS,
            'activityKinds' => array_map(fn ($k) => ['label' => $k[0], 'flow' => $k[1]], InvestmentActivity::KINDS),
            'accounts' => $all->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'institution' => $a->institution, 'started_empty' => $a->started_empty])->values(),
            'rows' => $rows,
            'summary' => $report->summary($rows),
            'chart' => $report->chart(),
            'activity' => $report->activity(),
            'holdings' => $report->holdings(),
            'balances' => InvestmentBalance::whereIn('investment_account_id', $ids)->orderByDesc('date')->get()
                ->map(fn ($b) => ['id' => $b->id, 'account_id' => $b->investment_account_id, 'date' => $b->date->toDateString(), 'balance' => $b->balance, 'source' => $b->source]),
            'recent' => InvestmentActivity::whereIn('investment_account_id', $ids)->orderByDesc('date')->orderByDesc('id')->limit(300)->get()
                ->map(fn ($x) => [
                    'id' => $x->id,
                    'account_id' => $x->investment_account_id,
                    'date' => $x->date->toDateString(),
                    'kind' => $x->kind,
                    'description' => $x->description,
                    'fund' => $x->fund,
                    'amount' => $x->amount,
                    'units' => $x->units,
                    'price' => $x->price,
                    'imported' => $x->investment_import_id !== null,
                ]),
            'activityCount' => InvestmentActivity::whereIn('investment_account_id', $ids)->count(),
            'imports' => InvestmentImport::whereIn('investment_account_id', $ids)->latest()->limit(10)->get()
                ->map(fn ($i) => ['id' => $i->id, 'account_id' => $i->investment_account_id, 'file_name' => $i->file_name, 'type' => $i->type, 'added' => $i->added, 'skipped' => $i->skipped, 'date' => $i->created_at->toDateString()]),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $a = InvestmentAccount::create($this->accountData($request));

        return redirect()->route('investments.index', ['account' => $a->id])->with('status', 'Account added.');
    }

    public function updateAccount(Request $request, InvestmentAccount $account): RedirectResponse
    {
        $account->update($this->accountData($request));

        return back()->with('status', 'Saved.');
    }

    public function destroyAccount(InvestmentAccount $account): RedirectResponse
    {
        $account->delete();

        return redirect()->route('investments.index')->with('status', 'Account deleted.');
    }

    private function accountData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(array_keys(InvestmentAccount::KINDS))],
            'institution' => ['nullable', 'string', 'max:100'],
            'started_empty' => ['nullable', 'boolean'],
        ]) + ['started_empty' => false];
    }

    public function storeBalance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('investment_accounts', 'id')],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'balance' => ['required', new MoneyAmount],
        ], ['date.before_or_equal' => 'The statement date can’t be in the future.']);
        $b = InvestmentBalance::where('investment_account_id', $data['account_id'])->whereDate('date', $data['date'])->first()
            ?? new InvestmentBalance(['investment_account_id' => $data['account_id'], 'date' => $data['date']]);
        $b->fill(['balance' => abs(Money::toCents((string) $data['balance'])), 'source' => 'typed', 'investment_import_id' => null])->save();

        return back()->with('status', 'Balance saved.');
    }

    public function destroyBalance(InvestmentBalance $balance): RedirectResponse
    {
        $balance->delete();

        return back()->with('status', 'Balance removed.');
    }

    public function storeActivity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('investment_accounts', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in(array_keys(InvestmentActivity::KINDS))],
            'amount' => ['required', new MoneyAmount],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        InvestmentActivity::create([
            'investment_account_id' => $data['account_id'],
            'date' => $data['date'],
            'kind' => $data['kind'],
            'description' => ($data['description'] ?? null) ?: InvestmentActivity::KINDS[$data['kind']][0],
            'amount' => InvestmentActivity::signed($data['kind'], Money::toCents((string) $data['amount'])),
        ]);

        return back()->with('status', 'Added.');
    }

    public function destroyActivity(InvestmentActivity $activity): RedirectResponse
    {
        $activity->delete();

        return back()->with('status', 'Removed.');
    }

    /**
     * Activity or holdings read from a file in the browser. Activity rows
     * already brought in are skipped, so the same file can be imported
     * again with newer dates.
     */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('investment_accounts', 'id')],
            'file_name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['activity', 'holdings'])],
            'as_of' => ['required_if:type,holdings', 'nullable', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1', 'max:10000'],
            'rows.*.date' => ['required_if:type,activity', 'nullable', 'date_format:Y-m-d'],
            'rows.*.kind' => ['required_if:type,activity', 'nullable', Rule::in(array_keys(InvestmentActivity::KINDS))],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
            'rows.*.name' => ['nullable', 'string', 'max:150'],
            'rows.*.fund' => ['nullable', 'string', 'max:150'],
            'rows.*.symbol' => ['nullable', 'string', 'max:20'],
            'rows.*.amount' => ['nullable', 'integer', 'between:-99999999999,99999999999'],
            'rows.*.value' => ['nullable', 'integer', 'between:-99999999999,99999999999'],
            'rows.*.cost' => ['nullable', 'integer', 'between:-99999999999,99999999999'],
            'rows.*.units' => ['nullable', 'numeric'],
            'rows.*.quantity' => ['nullable', 'numeric'],
            'rows.*.price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.ref' => ['nullable', 'string', 'max:100'],
        ]);

        return $data['type'] === 'holdings' ? $this->importHoldings($data) : $this->importActivity($data);
    }

    private function importActivity(array $data): RedirectResponse
    {
        $accountId = (int) $data['account_id'];
        $existing = InvestmentActivity::where('investment_account_id', $accountId)->whereNotNull('import_key')->pluck('import_key')->flip();
        $seen = [];
        $new = [];
        foreach ($data['rows'] as $r) {
            $amount = InvestmentActivity::signed($r['kind'], (int) ($r['amount'] ?? 0));
            $base = implode('|', [$r['date'], $r['kind'], mb_strtolower(trim($r['description'] ?? '')), $r['fund'] ?? '', $amount, $r['units'] ?? '', $r['ref'] ?? '']);
            // Identical rows inside one file are real (two equal buys on one
            // day), so the nth copy gets its own key.
            $n = $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = sha1($base.'#'.$n);
            if (isset($existing[$key])) {
                continue;
            }
            $new[] = [
                'date' => $r['date'],
                'kind' => $r['kind'],
                'description' => trim($r['description'] ?? '') ?: InvestmentActivity::KINDS[$r['kind']][0],
                'fund' => ($r['fund'] ?? null) ?: null,
                'symbol' => ($r['symbol'] ?? null) ?: null,
                'amount' => $amount,
                'units' => isset($r['units']) ? abs((float) $r['units']) : null,
                'price' => $r['price'] ?? null,
                'import_key' => $key,
            ];
        }
        $skipped = count($data['rows']) - count($new);
        if (! $new) {
            return back()->with('status', 'Nothing new in that file: all '.$skipped.' rows were already here.');
        }
        DB::transaction(function () use ($data, $accountId, $new, $skipped) {
            $import = InvestmentImport::create(['investment_account_id' => $accountId, 'file_name' => $data['file_name'], 'type' => 'activity', 'added' => count($new), 'skipped' => $skipped]);
            $now = now();
            foreach (array_chunk($new, 500) as $chunk) {
                InvestmentActivity::insert(array_map(fn ($r) => $r + ['investment_account_id' => $accountId, 'investment_import_id' => $import->id, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });

        return back()->with('status', 'Imported '.count($new).' rows'.($skipped ? ', skipped '.$skipped.' already here' : '').'.');
    }

    private function importHoldings(array $data): RedirectResponse
    {
        $accountId = (int) $data['account_id'];
        $rows = array_values(array_filter($data['rows'], fn ($r) => trim($r['name'] ?? $r['symbol'] ?? '') !== '' && isset($r['value'])));
        if (! $rows) {
            return back()->withErrors(['rows' => 'No holdings with a value were found in that file.']);
        }
        $total = 0;
        DB::transaction(function () use ($data, $accountId, $rows, &$total) {
            $import = InvestmentImport::create(['investment_account_id' => $accountId, 'file_name' => $data['file_name'], 'type' => 'holdings', 'added' => count($rows)]);
            // A newer file for the same day replaces the older one.
            InvestmentHolding::where('investment_account_id', $accountId)->whereDate('as_of', $data['as_of'])->delete();
            foreach ($rows as $r) {
                InvestmentHolding::create([
                    'investment_account_id' => $accountId,
                    'investment_import_id' => $import->id,
                    'as_of' => $data['as_of'],
                    'symbol' => ($r['symbol'] ?? null) ?: null,
                    'name' => trim($r['name'] ?? '') ?: $r['symbol'],
                    'quantity' => $r['quantity'] ?? null,
                    'price' => $r['price'] ?? null,
                    'value' => (int) $r['value'],
                    'cost' => $r['cost'] ?? null,
                ]);
                $total += (int) $r['value'];
            }
            $existing = InvestmentBalance::where('investment_account_id', $accountId)->whereDate('date', $data['as_of'])->first();
            if (! $existing || $existing->source !== 'typed') {
                $existing?->delete();
                InvestmentBalance::create(['investment_account_id' => $accountId, 'investment_import_id' => $import->id, 'date' => $data['as_of'], 'balance' => $total, 'source' => 'holdings']);
            }
        });

        return back()->with('status', 'Saved '.count($rows).' holdings worth $'.number_format($total / 100, 2, '.', ',').' as of '.$data['as_of'].'.');
    }

    public function destroyImport(InvestmentImport $import): RedirectResponse
    {
        $import->delete();

        return back()->with('status', 'Import undone.');
    }
}
