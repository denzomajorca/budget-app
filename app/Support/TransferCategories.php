<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\PayeeRule;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * One Transfer category with two subcategories: Credit Card Payment and
 * Savings. Old bank-file groups ("Checking › TFCU Checking", "Credit Cards ›
 * Amex", "Savings › Chase Savings") are sorted into them. Loan payments such
 * as Room2Go are spending, under Loan Payment.
 */
class TransferCategories
{
    private const CARD = '/american express|\bamex\b|freedom|sapphire|saphire|preferred|credit ?card|\bcard\b|visa|mastercard|discover|payment thank|autopay|epay/i';

    private const SAVINGS = '/\bsav(ings?)?\b|\bsav\b|to sav|from sav/i';

    private const LOAN = '/room ?(2|to) ?go/i';

    /** @var array<string,int>|null */
    private static ?array $ids = null;

    /** @return array{transfer:int,card:int,savings:int,loan:int} category ids, made if missing */
    public static function ids(): array
    {
        if (self::$ids) {
            return self::$ids;
        }
        $transfer = Category::whereNull('parent_id')->where('name', 'Transfer')->first();
        if (! $transfer) {
            $transfer = Category::create(['name' => 'Transfer', 'kind' => 'transfer']);
        } elseif ($transfer->kind !== 'transfer') {
            $transfer->update(['kind' => 'transfer']);
        }
        $sub = fn (Category $main, string $name) => (Category::where('parent_id', $main->id)->where('name', $name)->first()
            ?? Category::create(['name' => $name, 'parent_id' => $main->id, 'kind' => $main->kind]))->id;

        $loan = Category::whereNull('parent_id')->where('name', 'Loan Payment')->first()
            ?? Category::create(['name' => 'Loan Payment', 'kind' => 'expense']);
        if ($loan->kind !== 'expense') {
            $loan->update(['kind' => 'expense']);
        }

        return self::$ids = [
            'transfer' => $transfer->id,
            'card' => $sub($transfer, 'Credit Card Payment'),
            'savings' => $sub($transfer, 'Savings'),
            'loan' => $sub($loan, 'Room2Go'),
        ];
    }

    public static function forget(): void
    {
        self::$ids = null;
    }

    /**
     * Which of the four a transfer belongs to, from what we know about it:
     * the payee, the bank file's category text, its own account and, when
     * paired, the account on the other side.
     *
     * @return 'transfer'|'card'|'savings'|'loan'
     */
    public static function classify(?string $payee, ?string $categoryText, ?Account $own = null, ?Account $other = null): string
    {
        $payee = (string) $payee;
        $categoryText = (string) $categoryText;
        if (preg_match(self::LOAN, $payee.' '.$categoryText)) {
            return 'loan';
        }
        if ($other?->type === 'credit_card') {
            return 'card';
        }
        if ($other?->type === 'savings') {
            return 'savings';
        }
        foreach ([$payee, $categoryText] as $text) {
            if (preg_match(self::CARD, $text)) {
                return 'card';
            }
            if (preg_match(self::SAVINGS, $text)) {
                return 'savings';
            }
        }
        // An account named in the category text, like "Checking › TFCU Savings".
        foreach (Account::all(['id', 'name', 'type']) as $a) {
            if ($categoryText !== '' && $a->id !== $own?->id && stripos($categoryText, $a->name) !== false) {
                if ($a->type === 'credit_card') {
                    return 'card';
                }
                if ($a->type === 'savings') {
                    return 'savings';
                }
            }
        }
        if ($own?->type === 'credit_card') {
            return 'card';
        }
        if ($own?->type === 'savings') {
            return 'savings';
        }

        return 'transfer';
    }

    /** Is this bank-file row a transfer group or Room2Go, which forImportRow() files for you? */
    public static function isMapped(?string $payee, ?string $categoryText): bool
    {
        $path = CategoryPath::split($categoryText);

        return ($path && CategoryPath::kindFor($path[0]) === 'transfer') || (bool) preg_match(self::LOAN, (string) $payee.' '.(string) $categoryText);
    }

    /** Category id for a bank-file row whose category is a transfer group or Room2Go, or null to leave it alone. */
    public static function forImportRow(?string $payee, ?string $categoryText, Account $own): ?int
    {
        if (! self::isMapped($payee, $categoryText)) {
            return null;
        }

        return self::ids()[self::classify($payee, $categoryText, $own)];
    }

    /**
     * Move every transaction and payee rule out of the old transfer groups,
     * then delete those groups. Pairs stay linked; Room2Go stops being a
     * transfer.
     */
    public static function reorganize(): void
    {
        self::forget();
        $ids = self::ids();
        $keep = [$ids['transfer'], $ids['card'], $ids['savings']];

        $old = Category::with('parent')->where('kind', 'transfer')->whereNotIn('id', $keep)->get()->keyBy('id');
        // Room2Go may sit in a spending group too, e.g. "Bills › Room2Go".
        $loanish = Category::with('parent')->where('kind', '!=', 'transfer')->where('name', 'not like', 'Loan Payment')
            ->whereNotIn('id', [$ids['loan']])->get()->filter(fn (Category $c) => preg_match(self::LOAN, $c->name))->keyBy('id');
        $all = $old->union($loanish);
        // Rows filed straight under "Transfer" get sorted too, but that category stays.
        $sources = $all->union(Category::whereKey($ids['transfer'])->get()->keyBy('id'));
        $accounts = Account::all()->keyBy('id');

        DB::transaction(function () use ($all, $old, $ids, $accounts, $sources) {

            Transaction::with('transfer')->whereIn('category_id', $sources->keys())->chunkById(500, function ($rows) use ($sources, $ids, $accounts) {
                foreach ($rows as $t) {
                    $kind = self::classify($t->payee, $sources[$t->category_id]->label(), $accounts[$t->account_id] ?? null, $t->transfer ? ($accounts[$t->transfer->account_id] ?? null) : null);
                    $update = ['category_id' => $ids[$kind]];
                    if ($kind === 'loan') {
                        // Spending, not a transfer: let go of any pairing.
                        if ($t->transfer_id) {
                            Transaction::whereKey($t->transfer_id)->update(['transfer_id' => null]);
                        }
                        $update += ['transfer_id' => null, 'transfer_outside' => false];
                    }
                    $t->update($update);
                }
            });
            // The other side of a pair follows, so both halves read the same.
            Transaction::with('transfer')->whereNotNull('transfer_id')->whereIn('category_id', [$ids['transfer'], $ids['card'], $ids['savings']])
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $t) {
                        if ($t->transfer && $t->transfer->category_id !== $t->category_id && $t->category_id !== self::ids()['transfer']) {
                            $t->transfer->update(['category_id' => $t->category_id]);
                        }
                    }
                });

            foreach (PayeeRule::whereIn('category_id', $all->keys())->get() as $r) {
                $r->update(['category_id' => $ids[self::classify($r->match, $all[$r->category_id]->label())]]);
            }

            // Delete the emptied groups: subcategories first, then mains with nothing left.
            foreach ($all->sortByDesc(fn (Category $c) => $c->parent_id ? 1 : 0) as $c) {
                if (! Transaction::where('category_id', $c->id)->exists() && ! Category::where('parent_id', $c->id)->exists()) {
                    $c->delete();
                }
            }
            foreach ($old->pluck('parent_id')->filter()->unique() as $pid) {
                $p = Category::find($pid);
                if ($p && $p->kind === 'transfer' && ! in_array($p->id, [$ids['transfer']], true)
                    && ! Category::where('parent_id', $p->id)->exists() && ! Transaction::where('category_id', $p->id)->exists()) {
                    $p->delete();
                }
            }
        });

        Transfers::pair();
    }
}
