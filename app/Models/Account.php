<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    public const TYPES = [
        'checking' => 'Checking',
        'savings' => 'Savings',
        'credit_card' => 'Credit card',
        'cash' => 'Cash',
        'loan' => 'Loan',
        'investment' => 'Investment',
        'other' => 'Other',
    ];

    protected $fillable = ['name', 'type', 'institution', 'opening_balance', 'opening_date', 'is_archived', 'import_settings'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'opening_date' => 'date:Y-m-d',
            'is_archived' => 'boolean',
            'import_settings' => 'array',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Adds `balance` (cents): opening balance plus every transaction. */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->withSum('transactions as transactions_total', 'amount');
    }

    public function balance(): int
    {
        return $this->opening_balance + (int) ($this->transactions_total ?? $this->transactions()->sum('amount'));
    }

    public function isDebt(): bool
    {
        return in_array($this->type, ['credit_card', 'loan'], true);
    }
}
