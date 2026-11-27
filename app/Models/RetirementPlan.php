<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetirementPlan extends Model
{
    protected $fillable = ['inputs'];

    protected function casts(): array
    {
        return ['inputs' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self(['inputs' => []]);
    }
}
