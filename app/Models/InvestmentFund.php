<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentFund extends Model
{
    /** label and the usual percent in stocks */
    public const CLASSES = [
        'us_stock' => ['U.S. stocks', 100],
        'intl_stock' => ['International stocks', 100],
        'bond' => ['Bonds', 0],
        'mixed' => ['Stocks and bonds mix', 80],
        'cash' => ['Cash', 0],
        'other' => ['Other', 0],
    ];

    protected $fillable = ['key', 'asset_class', 'stock_share'];

    protected function casts(): array
    {
        return ['stock_share' => 'integer'];
    }

    public static function keyFor(?string $symbol, string $name): string
    {
        return mb_substr(trim($symbol ?? '') !== '' ? mb_strtoupper(trim($symbol)) : mb_strtolower(trim($name)), 0, 150);
    }

    /**
     * A first guess from the fund's name; it can be changed on the
     * Holdings tab and is then remembered.
     *
     * @return array{0: string, 1: int}
     */
    public static function guess(?string $symbol, string $name): array
    {
        $t = mb_strtolower(($symbol ?? '').' '.$name);

        return match (true) {
            (bool) preg_match('/money market|\bcash\b|spaxx|fdrxx|fzfxx|pending activity|stable value|core position/', $t) => ['cash', 0],
            (bool) preg_match('/conservative|income portfolio|retirement income/', $t) => ['mixed', 40],
            (bool) preg_match('/balanced|moderate/', $t) => ['mixed', 60],
            (bool) preg_match('/lifestyle|multimanager|multi-manager|target|freedom|growth ls|\bls\b|allocation|blend.*bond/', $t) => ['mixed', 80],
            (bool) preg_match('/bond|treasury|fixed income|aggregate|tips|\bincome\b|govt|government|municipal/', $t) => ['bond', 0],
            (bool) preg_match('/international|intl|global ex|foreign|emerging|developed|ex[- ]u\.?s/', $t) => ['intl_stock', 100],
            default => ['us_stock', 100],
        };
    }

    /** @return array<string, array{0: string, 1: int}> key => [class, stock share] */
    public static function lookup(iterable $holdings): array
    {
        $keys = [];
        foreach ($holdings as $h) {
            $keys[self::keyFor($h->symbol, $h->name)] = $h;
        }
        $saved = static::whereIn('key', array_keys($keys))->get()->keyBy('key');
        $out = [];
        foreach ($keys as $k => $h) {
            $out[$k] = isset($saved[$k]) ? [$saved[$k]->asset_class, $saved[$k]->stock_share] : self::guess($h->symbol, $h->name);
        }

        return $out;
    }
}
