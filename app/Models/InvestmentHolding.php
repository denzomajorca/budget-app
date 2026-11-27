<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentHolding extends Model
{
    protected $fillable = ['investment_account_id', 'investment_import_id', 'as_of', 'symbol', 'name', 'quantity', 'price', 'value', 'cost'];

    protected function casts(): array
    {
        return ['as_of' => 'date:Y-m-d', 'value' => 'integer', 'cost' => 'integer', 'quantity' => 'float', 'price' => 'float'];
    }
}
