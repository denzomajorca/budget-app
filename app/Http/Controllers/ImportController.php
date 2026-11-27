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

    public const MAX_FILES = 20;

    public function index(Request $request): Response
    {
        $done = $request->integer('done') ? Import::with('account')->find($request->integer('done')) : null;
        $batchIds = array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('batch', '')))), 0, self::MAX_FILES);
        $batch = $batchIds ? Import::with('account:id,name')->whereIn('id', $batchIds)->orderBy('id')->get()->map(fn (Import $i) => self::presentImport($i)) : [];

        return Inertia::render('Import/Index', [
            'accounts' => Account::withBalance()->where('is_archived', false)->orderBy('name')->get()
                ->map(fn (Account $a) => AccountController::present($a) + ['import_settings' => $a->import_settings]),
            'imports' => Import::with('account:id,name')->latest('id')->limit(10)->get()->map(fn (Import $i) => self::presentImport($i)),
            'batch' => $batch,
            'done' => $done ? self::presentImport($done) + ['account' => AccountController::present(Account::withBalance()->find($done->account_id))] : null,
        ]);
    }

    /** Marks rows already in the account and lists categories that would be created. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateRows($request, false);
        $dups = $this->duplicates((int) $data['account_id'], $data['rows']);

        return response()->json(['duplicates' => $dups, 'new_categories' => $this->newCategories($data['rows'])]);
    }

    /** Category names that importing these rows would add. */
    private function newCategories(array $rows): array
    {
        $existing = CategoryPath::existing();
        $new = [];
        foreach ($rows as $r) {
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

        return array_values($new);
    }

    /**
     * Several files at once: for each file, how many of its rows each account
     * already has (same date, amount and payee), and how many of its payees
     * the account has paid before. The page uses this to pick the account.
     */
    public function match(Request $request): JsonResponse
    {
        $data = $this->validateFiles($request, false);
        $accounts = Account::where('is_archived', false)->pluck('id');
        $payeeKey = fn (string $p) => implode(' ', array_slice(preg_split('/\s+/', trim(preg_replace('/[^a-z]+/', ' ', mb_strtolower($p)))) ?: [], 0, 2));
        $since = now()->subMonths(18)->toDateString();
        $known = [];
        Transaction::where('date', '>=', $since)->select('account_id', 'payee')->distinct()->get()
            ->each(function ($t) use (&$known, $payeeKey) {
                $k = $payeeKey($t->payee);
                if ($k !== '') {
                    $known[$t->account_id][$k] = true;
                }
            });

        $files = [];
        $allRows = [];
        foreach ($data['files'] as $f) {
            $exact = [];
            $familiar = [];
            foreach ($accounts as $id) {
                $exact[$id] = count(array_filter($this->duplicates($id, $f['rows'])));
                $familiar[$id] = count(array_filter($f['rows'], fn ($r) => isset($known[$id][$payeeKey($r['payee'])])));
            }
            $files[] = ['exact' => $exact, 'familiar' => $familiar];
            array_push($allRows, ...$f['rows']);
        }

        return response()->json(['files' => $files, 'new_categories' => $this->newCategories($allRows)]);
    }

    /** Imports several files, each into its own account. Rows already there are skipped unless asked. */
    public function batch(Request $request): RedirectResponse
    {
        $data = $this->validateFiles($request, true);
        $ids = [];
        foreach ($data['files'] as $f) {
            $account = Account::findOrFail($f['account_id']);
            $rows = array_values($f['rows']);
            $skipped = (int) ($f['skipped'] ?? 0);
            if (! $request->boolean('include_duplicates')) {
                $dups = $this->duplicates($account->id, $rows);
                $rows = array_values(array_filter($rows, fn ($r, $i) => ! $dups[$i], ARRAY_FILTER_USE_BOTH));
                $skipped += count(array_filter($dups));
            }
            if (! $rows) {
                continue;
            }
            $ids[] = $this->importInto($account, $f['file_name'], $rows, $skipped, $f['settings'] ?? null)->id;
        }
        Transfers::pair();

        $count = $ids ? Import::whereIn('id', $ids)->sum('rows_imported') : 0;

        return redirect()->route('imports.index', $ids ? ['batch' => implode(',', $ids)] : [])
            ->with('status', $ids ? $count.' transactions imported from '.count($ids).' '.(count($ids) === 1 ? 'file' : 'files').'.' : 'Nothing new to import: every row was already there.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRows($request, true);
        $account = Account::findOrFail($data['account_id']);

        $import = $this->importInto($account, $data['file_name'], $data['rows'], (int) ($data['skipped'] ?? 0), $data['settings'] ?? null);
        Transfers::pair();

        return redirect()->route('imports.index', ['done' => $import->id])
            ->with('status', $import->rows_imported.' transactions imported.');
    }

    private function importInto(Account $account, string $fileName, array $rows, int $skipped, ?array $settings): Import
    {
        TransferCategories::forget();

        return DB::transaction(function () use ($fileName, $rows, $skipped, $settings, $account) {
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

            $dates = array_column($rows, 'date');
            $import = Import::create([
                'account_id' => $account->id,
                'file_name' => mb_substr($fileName, 0, 255),
                'rows_imported' => count($rows),
                'rows_skipped' => $skipped,
                'first_date' => $dates ? min($dates) : null,
                'last_date' => $dates ? max($dates) : null,
            ]);

            $now = now();
            $rule = PayeeRule::matcher();
            $insert = [];
            foreach ($rows as $r) {
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
            if (! empty($settings)) {
                $account->update(['import_settings' => $settings]);
            }

            return $import;
        });
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

    private function validateFiles(Request $request, bool $full): array
    {
        $rules = [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*.rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'files.*.rows.*.date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            'files.*.rows.*.amount' => ['required', 'integer', 'between:-99999999999,99999999999'],
            'files.*.rows.*.payee' => ['required', 'string', 'max:500'],
            'files.*.rows.*.category' => ['nullable', 'string', 'max:250'],
        ];
        if ($full) {
            $rules += [
                'files.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
                'files.*.file_name' => ['required', 'string', 'max:255'],
                'files.*.skipped' => ['nullable', 'integer', 'min:0'],
                'files.*.settings' => ['nullable', 'array'],
                'files.*.rows.*.memo' => ['nullable', 'string', 'max:2000'],
            ];
        }
        $total = array_sum(array_map(fn ($f) => is_array($f['rows'] ?? null) ? count($f['rows']) : 0, (array) $request->input('files', [])));
        abort_if($total > self::MAX_ROWS * 3, 422, 'That is a lot of rows at once. Import fewer files at a time.');

        return $request->validate($rules, [
            'files.max' => 'Up to '.self::MAX_FILES.' files at a time.',
            'files.*.rows.max' => 'A file can have up to '.self::MAX_ROWS.' rows. Split it into smaller files.',
            'files.*.account_id.required' => 'Pick an account for every file.',
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
