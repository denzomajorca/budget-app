<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    protected $fillable = ['account_id', 'file_name', 'rows_imported', 'rows_skipped', 'categories_created', 'first_date', 'last_date'];

    protected function casts(): array
    {
        return ['first_date' => 'date:Y-m-d', 'last_date' => 'date:Y-m-d'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
