<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentActivity extends Model
{
    /**
     * label, and how the row counts: 'in' and 'out' are money you add or
     * take out (they don't count as growth); 'none' happens inside the
     * account (buys, sells, dividends, fees).
     */
    public const KINDS = [
        'employee' => ['You contributed', 'in'],
        'employer' => ['Employer contribution', 'in'],
        'loan_repayment' => ['Loan repayment', 'in'],
        'deposit' => ['Deposit', 'in'],
        'withdrawal' => ['Withdrawal', 'out'],
        'loan' => ['Loan taken out', 'out'],
        'buy' => ['Bought', 'none'],
        'sell' => ['Sold', 'none'],
        'earnings' => ['Dividend or interest', 'none'],
        'fee' => ['Fee', 'none'],
        'other' => ['Other', 'none'],
    ];

    protected $fillable = ['investment_account_id', 'investment_import_id', 'date', 'kind', 'description', 'fund', 'symbol', 'amount', 'units', 'price', 'import_key'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => 'integer',
            'units' => 'float',
            'price' => 'float',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class, 'investment_account_id');
    }

    /** Kinds that are money you put in or took out. */
    public static function flowKinds(): array
    {
        return array_keys(array_filter(self::KINDS, fn ($k) => $k[1] !== 'none'));
    }

    /** Money in is positive and money out negative, whatever sign the file used. */
    public static function signed(string $kind, int $cents): int
    {
        return match (self::KINDS[$kind][1] ?? 'none') {
            'in' => abs($cents),
            'out' => -abs($cents),
            default => $cents,
        };
    }
}
