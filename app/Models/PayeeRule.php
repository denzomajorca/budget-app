<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "If the payee contains <match>, file it under <category>." */
class PayeeRule extends Model
{
    protected $fillable = ['match', 'category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public static function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
    }

    /**
     * A matcher for many payees at once. The longest matching rule wins, so
     * "amazon prime" beats "amazon".
     *
     * @return callable(string): ?int category id
     */
    public static function matcher(): callable
    {
        $rules = self::orderByRaw('length(`match`) desc')->get(['match', 'category_id'])->all();

        return function (string $payee) use ($rules): ?int {
            $p = self::normalize($payee);
            foreach ($rules as $r) {
                if ($r->match !== '' && str_contains($p, $r->match)) {
                    return $r->category_id;
                }
            }

            return null;
        };
    }
}
