<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentImport extends Model
{
    protected $fillable = ['investment_account_id', 'file_name', 'type', 'added', 'skipped'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class, 'investment_account_id');
    }
}
