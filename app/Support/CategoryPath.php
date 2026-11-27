<?php

namespace App\Support;

use App\Models\Category;

/**
 * Turns bank-file category text like "Food and Dining:Groceries" or
 * "Travel:(blank)" into a category, creating any that are missing.
 */
class CategoryPath
{
    /** @return array{0:string,1:?string}|null [main, sub] or null when empty */
    public static function split(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '' || strcasecmp($text, '(blank)') === 0 || strcasecmp($text, 'uncategorized') === 0) {
            return null;
        }
        [$main, $sub] = array_pad(array_map('trim', explode(':', $text, 2)), 2, null);
        if ($sub === '' || ($sub !== null && strcasecmp($sub, '(blank)') === 0)) {
            $sub = null;
        }
        if ($main === '' || strcasecmp($main, '(blank)') === 0) {
            return $sub === null ? null : [$sub, null];
        }

        return [mb_substr($main, 0, 100), $sub === null ? null : mb_substr($sub, 0, 100)];
    }

    public static function kindFor(string $main): string
    {
        return match (true) {
            (bool) preg_match('/income|paycheck|payroll|salary|interest|dividend/i', $main) => 'income',
            Transfers::looksLikeAccount($main) => 'transfer',
            default => 'expense',
        };
    }

    /** Key used to look up existing categories regardless of letter case. */
    public static function key(string $main, ?string $sub): string
    {
        return mb_strtolower($main).':'.mb_strtolower((string) $sub);
    }

    /** @return array<string,int> key => id, for every existing category */
    public static function existing(): array
    {
        $map = [];
        foreach (Category::with('parent')->get() as $c) {
            $map[$c->parent ? self::key($c->parent->name, $c->name) : self::key($c->name, null)] = $c->id;
        }

        return $map;
    }
}
