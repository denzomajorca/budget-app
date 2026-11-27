<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PlanItem extends Model
{
    /** Frequency choices: "<n>w" every n weeks, "<n>m" every n months. */
    public const FREQUENCIES = ['1w' => 'Weekly', '2w' => 'Every 2 weeks', '1m' => 'Monthly', '3m' => 'Quarterly', '6m' => 'Every 6 months', '12m' => 'Yearly'];

    protected $fillable = ['group', 'name', 'category_id', 'amount', 'every_months', 'every_weeks', 'next_due', 'is_estimate', 'saved', 'due_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'saved' => 'integer',
            'every_months' => 'integer',
            'every_weeks' => 'integer',
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
        if ($this->group !== 'bill' || ! $this->next_due || (! $this->every_months && ! $this->every_weeks)) {
            return [];
        }
        $out = [];
        $start = $this->next_due->copy()->startOfDay();
        for ($i = 0; $i < 2000; $i++) {
            $d = $this->every_weeks
                ? $start->copy()->addWeeks($i * $this->every_weeks)
                : $start->copy()->addMonthsNoOverflow($i * $this->every_months);
            if ($d->gt($to)) {
                break;
            }
            if ($d->gte($from)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    public function frequencyKey(): ?string
    {
        return $this->every_weeks ? $this->every_weeks.'w' : ($this->every_months ? $this->every_months.'m' : null);
    }

    /** A bill's average cost per month. */
    public function billPerMonth(): int
    {
        if ($this->every_weeks) {
            return (int) round($this->amount * 52 / 12 / $this->every_weeks);
        }

        return (int) round($this->amount / max(1, (int) $this->every_months));
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
