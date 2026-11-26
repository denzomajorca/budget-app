<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PlanItem extends Model
{
    public const FREQUENCIES = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Every 6 months', 12 => 'Yearly'];

    protected $fillable = ['group', 'name', 'category_id', 'amount', 'every_months', 'next_due', 'is_estimate', 'saved', 'due_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'saved' => 'integer',
            'every_months' => 'integer',
            'is_estimate' => 'boolean',
            'next_due' => 'date:Y-m-d',
            'due_by' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Due dates of a bill from $from to $to, stepping from its saved next due date. */
    public function dueDates(Carbon $from, Carbon $to): array
    {
        if ($this->group !== 'bill' || ! $this->next_due || ! $this->every_months) {
            return [];
        }
        $out = [];
        $start = $this->next_due->copy()->startOfDay();
        for ($i = 0; $i < 400; $i++) {
            $d = $start->copy()->addMonthsNoOverflow($i * $this->every_months);
            if ($d->gt($to)) {
                break;
            }
            if ($d->gte($from)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** Months from this month up to the month before the goal date (at least 1). */
    public function monthsLeft(Carbon $today): int
    {
        if (! $this->due_by) {
            return 12;
        }

        return max(1, ($this->due_by->year - $today->year) * 12 + $this->due_by->month - $today->month);
    }

    /** Cents to set aside each month for a goal. */
    public function monthlySetAside(Carbon $today): int
    {
        return (int) ceil(max(0, $this->amount - $this->saved) / $this->monthsLeft($today));
    }
}
