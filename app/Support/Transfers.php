<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Money moved between your own accounts. Each side stays in its own account
 * (so balances are right) but is filed under a Transfer category, which keeps
 * it out of spending, income, budgets and the expense plan.
 */
class Transfers
{
    /** Days apart the two sides of a transfer may be dated. */
    public const WINDOW_DAYS = 5;

    /** Does this main category name look like one of your accounts rather than a spending category? */
    public static function looksLikeAccount(string $main, ?array $accountNames = null): bool
    {
        $name = mb_strtolower(trim($main, " \t[]"));
        if (preg_match('/^transfers?\b/', $name) || preg_match('/^(checking|savings?|credit ?cards?|bank accounts?|loans?)( accounts?| payments?)?$/', $name)) {
            return true;
        }
        $accountNames ??= Account::pluck('name')->all();
        foreach ($accountNames as $a) {
            if ($name === mb_strtolower(trim($a))) {
                return true;
            }
        }

        return false;
    }

    /**
     * File every category that is named like an account, plus its
     * subcategories, as a transfer. Returns how many main categories changed.
     */
    public static function fixCategories(): int
    {
        $names = Account::pluck('name')->all();
        $n = 0;
        foreach (Category::whereNull('parent_id')->where('kind', '!=', 'transfer')->get() as $main) {
            if (self::looksLikeAccount($main->name, $names)) {
                $main->update(['kind' => 'transfer']);
                $main->children()->update(['kind' => 'transfer']);
                $n++;
            }
        }

        return $n;
    }

    /** The main "Transfer" category, made if it is missing. */
    public static function defaultCategory(): Category
    {
        return Category::whereNull('parent_id')->where('kind', 'transfer')->where('name', 'Transfer')->first()
            ?? Category::create(['name' => 'Transfer', 'kind' => 'transfer']);
    }

    /**
     * Pair up the two sides of each transfer: money out of one account and
     * the same amount into another within a few days. At least one side must
     * already be in a Transfer category; the other may be uncategorized, and
     * then it is filed under the same category. Returns how many pairs were made.
     */
    public static function pair(): int
    {
        $transferIds = Category::where('kind', 'transfer')->pluck('id')->all();
        if (! $transferIds) {
            return 0;
        }

        $open = Transaction::whereNull('transfer_id')->where('transfer_outside', false)
            ->where('amount', '!=', 0)
            ->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $transferIds))
            ->orderBy('date')->orderBy('id')
            ->get(['id', 'account_id', 'date', 'amount', 'category_id']);

        // Index the candidates by amount so each lookup is quick.
        $byAmount = [];
        foreach ($open as $t) {
            $byAmount[$t->amount][] = $t;
        }

        $isTransfer = array_flip($transferIds);
        $used = [];
        $pairs = [];
        foreach ($open as $t) {
            if (isset($used[$t->id]) || ! isset($isTransfer[$t->category_id])) {
                continue; // only start from a side that is known to be a transfer
            }
            $best = null;
            $bestGap = PHP_INT_MAX;
            foreach ($byAmount[-$t->amount] ?? [] as $c) {
                if (isset($used[$c->id]) || $c->account_id === $t->account_id) {
                    continue;
                }
                $gap = abs($c->date->diffInDays($t->date));
                // Prefer a side that is also a transfer, then the closest date.
                $score = $gap * 2 + (isset($isTransfer[$c->category_id]) ? 0 : 1);
                if ($gap <= self::WINDOW_DAYS && $score < $bestGap) {
                    $best = $c;
                    $bestGap = $score;
                }
            }
            if ($best) {
                $used[$t->id] = $used[$best->id] = true;
                $pairs[] = [$t, $best];
            }
        }

        DB::transaction(function () use ($pairs) {
            foreach ($pairs as [$a, $b]) {
                Transaction::whereKey($a->id)->update(['transfer_id' => $b->id]);
                $bUpdate = ['transfer_id' => $a->id];
                if ($b->category_id === null) {
                    $bUpdate['category_id'] = $a->category_id;
                }
                Transaction::whereKey($b->id)->update($bUpdate);
            }
        });

        return count($pairs);
    }

    /** Days apart a suggested match may be, when you pair by hand. */
    public const SUGGEST_DAYS = 10;

    /**
     * The most likely other side of an unpaired transfer, from any account
     * and any category: the opposite amount, closest date first.
     */
    public static function suggestion(Transaction $t): ?Transaction
    {
        return Transaction::with(['account:id,name', 'category.parent'])
            ->where('account_id', '!=', $t->account_id)
            ->where('amount', -$t->amount)
            ->whereNull('transfer_id')
            ->whereBetween('date', [$t->date->copy()->subDays(self::SUGGEST_DAYS)->format('Y-m-d'), $t->date->copy()->addDays(self::SUGGEST_DAYS)->format('Y-m-d')])
            ->get()
            ->sortBy(fn (Transaction $c) => abs($c->date->diffInDays($t->date)))
            ->first();
    }

    /** Link two transactions as one transfer. The other side takes this one's category if it isn't a transfer yet. */
    public static function link(Transaction $a, Transaction $b): void
    {
        DB::transaction(function () use ($a, $b) {
            $a->update(['transfer_id' => $b->id, 'transfer_outside' => false]);
            $bUpdate = ['transfer_id' => $a->id, 'transfer_outside' => false];
            if ($b->category?->kind !== 'transfer') {
                $bUpdate['category_id'] = $a->category_id;
            }
            $b->update($bUpdate);
        });
    }

    /** Transactions in a Transfer category with no matching other side. */
    public static function unpairedCount(): int
    {
        return Transaction::whereNull('transfer_id')->where('transfer_outside', false)
            ->whereHas('category', fn ($q) => $q->where('kind', 'transfer'))
            ->count();
    }
}
