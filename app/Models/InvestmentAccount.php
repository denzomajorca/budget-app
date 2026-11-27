<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentAccount extends Model
{
    public const KINDS = [
        '401k' => '401(k)',
        'ira' => 'IRA',
        'roth_ira' => 'Roth IRA',
        'brokerage' => 'Brokerage',
        'hsa' => 'HSA',
        'other' => 'Other',
    ];

    protected $fillable = ['name', 'kind', 'institution'];

    public function activities(): HasMany
    {
        return $this->hasMany(InvestmentActivity::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InvestmentBalance::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(InvestmentHolding::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(InvestmentImport::class);
    }

    /** Value of the latest saved balance, or null when none is saved yet. */
    public static function totalValue(): int
    {
        return (int) static::with(['balances' => fn ($q) => $q->orderByDesc('date')])->get()
            ->sum(fn (self $a) => $a->balances->first()?->balance ?? 0);
    }
}
