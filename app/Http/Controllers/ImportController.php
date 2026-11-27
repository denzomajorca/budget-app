<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Support\CategoryPath;
use App\Support\TransferCategories;
use App\Support\Transfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank files are read in the browser (CSV, XLS, XLSX). The browser sends
 * the rows here as plain values: date, amount in cents, payee, category
 * text ("Main:Sub") and memo.
 */
class ImportController extends Controller
{
    public const MAX_ROWS = 10000;

    public function index(Request $request): Response
    {
        $done = $request->integer('done') ? Import::with('account')->find($request->integer('done')) : null;

        return Inertia::render('Import/Index', [
            'accounts' => Account::withBalance()->where('is_archived', false)->orderBy('name')->get()
                ->map(fn (Account $a) => AccountController::present($a) + ['import_settings' => $a->import_settings]),
            'imports' => Import::with('account:id,name')->latest('id')->limit(10)->get()->map(fn (Import $i) => self::presentImport($i)),
            'done' => $done ? self::presentImport($done) + ['account' => AccountController::present(Account::withBalance()->find($done->account_id))] : null,
        ]);
    }

    /** Marks rows already in the account and lists categories that would be created. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateRows($request, false);
        $dups = $this->duplicates((int) $data['account_id'], $data['rows']);

        $existing = CategoryPath::existing();
        $new = [];
        foreach ($data['rows'] as $r) {
            $path = CategoryPath::split($r['category'] ?? null);
            if (! $path || TransferCategories::isMapped($r['payee'] ?? null, $r['category'] ?? null)) {
                continue;
            }
            [$main, $sub] = $path;
            if (! isset($existing[CategoryPath::key($main, null)])) {
                $new[CategoryPath::key($main, null)] = $main;
            }
            if ($sub !== null && ! isset($existing[CategoryPath::key($main, $sub)])) {
                $new[CategoryPath::key($main, $sub)] = $main.' › '.$sub;
            }
        }

        return response()->json(['duplicates' => $dups, 'new_categories' => array_values($new)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRows($request, true);
        $account = Account::findOrFail($data['account_id']);

        TransferCategories::forget();
        $import = DB::transaction(function () use ($data, $account) {
            $existing = CategoryPath::existing();
            $created = 0;
            $categoryId = function (?string $text) use (&$existing, &$created): ?int {
                $path = CategoryPath::split($text);
                if (! $path) {
                    return null;
                }
                [$main, $sub] = $path;
                $mainKey = CategoryPath::key($main, null);
                if (! isset($existing[$mainKey])) {
                    $existing[$mainKey] = Category::create(['name' => $main, 'kind' => CategoryPath::kindFor($main)])->id;
                    $created++;
                }
                if ($sub === null) {
                    return $existing[$mainKey];
                }
                $subKey = CategoryPath::key($main, $sub);
                if (! isset($existing[$subKey])) {
                    $parent = Category::find($existing[$mainKey]);
                    $existing[$subKey] = Category::create(['name' => $sub, 'parent_id' => $parent->id, 'kind' => $parent->kind])->id;
                    $created++;
                }

                return $existing[$subKey];
            };

            $dates = array_column($data['rows'], 'date');
            $import = Import::create([
                'account_id' => $account->id,
                'file_name' => mb_substr($data['file_name'], 0, 255),
                'rows_imported' => count($data['rows']),
                'rows_skipped' => (int) ($data['skipped'] ?? 0),
                'first_date' => $dates ? min($dates) : null,
                'last_date' => $dates ? max($dates) : null,
            ]);

            $now = now();
            $rule = PayeeRule::matcher();
            $insert = [];
            foreach ($data['rows'] as $r) {
                $insert[] = [
                    'account_id' => $account->id,
                    'import_id' => $import->id,
                    'date' => $r['date'],
                    'payee' => mb_substr(trim($r['payee']), 0, 255),
                    'amount' => (int) $r['amount'],
                    // A rule you saved wins over the bank file's category.
                    'category_id' => $rule($r['payee'])
                        ?? TransferCategories::forImportRow($r['payee'], $r['category'] ?? null, $account)
                        ?? $categoryId($r['category'] ?? null),
                    'needs_review' => true,
                    'memo' => filled($r['memo'] ?? null) ? mb_substr(trim($r['memo']), 0, 1000) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                Transaction::insert($chunk);
            }

            $import->update(['categories_created' => $created]);
            if (! empty($data['settings'])) {
                $account->update(['import_settings' => $data['settings']]);
            }

            return $import;
        });
        Transfers::pair();

        return redirect()->route('imports.index', ['done' => $import->id])
            ->with('status', $import->rows_imported.' transactions imported.');
    }

    /** Undo: removes the transactions this import added. Categories stay. */
    public function destroy(Import $import): RedirectResponse
    {
        DB::transaction(function () use ($import) {
            Transaction::whereIn('transfer_id', $import->transactions()->select('id'))->update(['transfer_id' => null]);
            $import->transactions()->update(['transfer_id' => null]);
            $import->transactions()->delete();
            $import->delete();
        });

        return redirect()->route('imports.index')->with('status', 'Import undone.');
    }

    public static function presentImport(Import $i): array
    {
        return [
            'id' => $i->id,
            'file_name' => $i->file_name,
            'account_id' => $i->account_id,
            'account_name' => $i->account?->name,
            'rows_imported' => $i->rows_imported,
            'rows_skipped' => $i->rows_skipped,
            'categories_created' => $i->categories_created,
            'first_date' => $i->first_date?->format('Y-m-d'),
            'last_date' => $i->last_date?->format('Y-m-d'),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function validateRows(Request $request, bool $full): array
    {
        $rules = [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            'rows.*.amount' => ['required', 'integer', 'between:-99999999999,99999999999'],
            'rows.*.payee' => ['required', 'string', 'max:500'],
            'rows.*.category' => ['nullable', 'string', 'max:250'],
        ];
        if ($full) {
            $rules += [
                'file_name' => ['required', 'string', 'max:255'],
                'skipped' => ['nullable', 'integer', 'min:0'],
                'settings' => ['nullable', 'array'],
                'rows.*.memo' => ['nullable', 'string', 'max:2000'],
            ];
        }

        return $request->validate($rules, [
            'rows.max' => 'A file can have up to '.self::MAX_ROWS.' rows. Split it into smaller files.',
            'rows.*.date.date_format' => 'Row :position has a date that could not be read.',
        ]);
    }

    /**
     * One flag per row: true when the account already has a transaction with
     * the same date, amount and payee. Repeats are counted, so two identical
     * charges in a file only match two identical ones already saved.
     *
     * @return list<bool>
     */
    private function duplicates(int $accountId, array $rows): array
    {
        $norm = fn (string $p) => mb_strtolower(preg_replace('/\s+/', ' ', trim($p)));
        $dates = array_column($rows, 'date');
        $have = [];
        Transaction::where('account_id', $accountId)
            ->whereBetween('date', [min($dates), max($dates)])
            ->get(['date', 'amount', 'payee'])
            ->each(function (Transaction $t) use (&$have, $norm) {
                $k = $t->date->format('Y-m-d').'|'.$t->amount.'|'.$norm($t->payee);
                $have[$k] = ($have[$k] ?? 0) + 1;
            });

        return array_map(function ($r) use (&$have, $norm) {
            $k = $r['date'].'|'.((int) $r['amount']).'|'.$norm($r['payee']);
            if (($have[$k] ?? 0) > 0) {
                $have[$k]--;

                return true;
            }

            return false;
        }, array_values($rows));
    }
}
