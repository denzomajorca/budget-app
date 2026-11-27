<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentBalance extends Model
{
    protected $fillable = ['investment_account_id', 'investment_import_id', 'date', 'balance', 'source'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'balance' => 'integer'];
    }
}
