<?php

namespace App\Support;

use App\Models\InvestmentAccount;
use App\Models\InvestmentActivity;
use App\Models\InvestmentBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out what investment accounts are worth and how they grew.
 *
 * Growth is the change in value minus the money you put in (or plus what
 * you took out), between two saved balances. Return is that growth divided
 * by the starting value plus half of the money added along the way, a
 * standard shortcut that is close enough for personal tracking.
 */
class InvestmentReport
{
    public const TIMEFRAMES = [
        'ytd' => 'Year to date',
        '1y' => 'Last 12 months',
        '3y' => 'Last 3 years',
        'all' => 'All time',
    ];

    /** @var Collection<int, InvestmentAccount> */
    private Collection $accounts;

    private Carbon $today;

    private ?Carbon $from;

    /** @param Collection<int, InvestmentAccount> $accounts with balances and activities loaded */
    public function __construct(Collection $accounts, private string $tf, ?Carbon $today = null)
    {
        $this->accounts = $accounts;
        $this->today = ($today ?? now())->copy()->startOfDay();
        $this->from = self::fromDate($tf, $this->today);
    }

    public static function fromDate(string $tf, Carbon $today): ?Carbon
    {
        return match ($tf) {
            'ytd' => $today->copy()->subYearNoOverflow()->endOfYear()->startOfDay(),
            '1y' => $today->copy()->subYearNoOverflow(),
            '3y' => $today->copy()->subYearsNoOverflow(3),
            default => null,
        };
    }

    public function timeframe(): array
    {
        return ['key' => $this->tf, 'label' => self::TIMEFRAMES[$this->tf], 'from' => $this->from?->toDateString(), 'to' => $this->today->toDateString()];
    }

    /** Money in or out of one account, newest balance first is not assumed. */
    private function flows(InvestmentAccount $a): Collection
    {
        $kinds = InvestmentActivity::flowKinds();

        return $a->activities->filter(fn ($x) => in_array($x->kind, $kinds, true));
    }

    private function flowBetween(InvestmentAccount $a, ?Carbon $after, Carbon $upTo): int
    {
        return (int) $this->flows($a)
            ->filter(fn ($x) => (! $after || $x->date->gt($after)) && $x->date->lte($upTo))
            ->sum('amount');
    }

    /**
     * Saved balances, plus a $0 balance the day before the first money went
     * in when the account started empty.
     */
    private function balancesOf(InvestmentAccount $a): Collection
    {
        $bal = $a->balances->sortBy('date')->values();
        $firstFlow = $this->flows($a)->min('date');
        if ($a->started_empty && $firstFlow && (! $bal->first() || $bal->first()->date->gt($firstFlow))) {
            $bal->prepend(new InvestmentBalance(['date' => Carbon::parse($firstFlow)->subDay(), 'balance' => 0]));
        }

        return $bal->values();
    }

    /** One account's growth over the timeframe. */
    public function account(InvestmentAccount $a): array
    {
        $bal = $this->balancesOf($a);
        $last = $a->balances->isEmpty() ? null : $bal->last();
        $base = [
            'id' => $a->id,
            'name' => $a->name,
            'kind' => $a->kind,
            'kind_label' => InvestmentAccount::KINDS[$a->kind] ?? 'Other',
            'institution' => $a->institution,
            'value' => $last?->balance,
            'value_date' => $last?->date->toDateString(),
            'start' => null,
            'start_date' => null,
            'put_in' => null,
            'gain' => null,
            'return' => null,
            'annual' => null,
            'partial' => false,
            'opened' => false,
            'needs' => $last ? null : 'balance',
        ];
        if (! $last) {
            return $base;
        }
        $start = $this->from ? $bal->filter(fn ($b) => $b->date->lte($this->from))->last() : null;
        $partial = false;
        if (! $start) {
            $start = $bal->first();
            $partial = (bool) $this->from;
        }
        if ($start === $last) {
            return ['needs' => 'second_balance'] + $base;
        }
        $putIn = $this->flowBetween($a, $start->date, $last->date);

        return array_merge($base, $this->growth($start->balance, $last->balance, $putIn, $start->date, $last->date), [
            'start' => $start->balance,
            'start_date' => $start->date->toDateString(),
            'put_in' => $putIn,
            'partial' => $partial && $start->exists,
            'opened' => ! $start->exists,
        ]);
    }

    private function growth(int $start, int $end, int $putIn, Carbon $from, Carbon $to): array
    {
        $gain = $end - $start - $putIn;
        $denominator = $start + $putIn / 2;
        $ret = $denominator > 0 ? $gain / $denominator : null;
        $days = $from->diffInDays($to);
        $annual = $ret !== null && $days >= 365 && $ret > -1 ? (1 + $ret) ** (365.25 / $days) - 1 : null;

        return ['gain' => $gain, 'return' => $ret, 'annual' => $annual];
    }

    public function summary(array $rows): array
    {
        $grown = array_filter($rows, fn ($r) => $r['gain'] !== null);
        $out = [
            'value' => array_sum(array_map(fn ($r) => $r['value'] ?? 0, $rows)),
            'start' => null, 'put_in' => null, 'gain' => null, 'return' => null, 'annual' => null,
            'start_date' => null,
            'counted' => count($grown),
            'with_value' => count(array_filter($rows, fn ($r) => $r['value'] !== null)),
        ];
        if (! $grown) {
            return $out;
        }
        $start = array_sum(array_column($grown, 'start'));
        $putIn = array_sum(array_column($grown, 'put_in'));
        $end = array_sum(array_column($grown, 'value'));
        $from = Carbon::parse(min(array_column($grown, 'start_date')));
        $to = Carbon::parse(max(array_column($grown, 'value_date')));

        return array_merge($out, $this->growth($start, $end, $putIn, $from, $to), [
            'start' => $start,
            'put_in' => $putIn,
            'start_date' => $from->toDateString(),
        ]);
    }

    /** Estimated value on a date: the last balance before it plus money added since. */
    private function valueOn(InvestmentAccount $a, Carbon $d): ?int
    {
        $b = $this->balancesOf($a)->filter(fn ($x) => $x->date->lte($d))->last();

        return $b ? $b->balance + $this->flowBetween($a, $b->date, $d) : null;
    }

    /**
     * Month-end points for the chart: value, and the starting value plus
     * the money put in since. The gap between the two is growth.
     */
    public function chart(): array
    {
        $firsts = $this->accounts->map(fn ($a) => $this->balancesOf($a)->min('date'))->filter();
        if ($firsts->isEmpty()) {
            return [];
        }
        $first = Carbon::parse($firsts->min());
        $begin = $this->from && $this->from->gt($first) ? $this->from->copy() : $first->copy();
        $points = [];
        $cursor = $begin->copy()->endOfMonth()->startOfDay();
        $points[] = $begin->copy();
        while ($cursor->lt($this->today)) {
            if ($cursor->gt($begin)) {
                $points[] = $cursor->copy();
            }
            $cursor = $cursor->copy()->addDay()->endOfMonth()->startOfDay();
        }
        $points[] = $this->today->copy();
        $points = collect($points)->unique(fn ($d) => $d->toDateString())->values();
        if ($points->count() < 2) {
            return [];
        }

        // Where each account's "money put in" line starts.
        $starts = [];
        foreach ($this->accounts as $a) {
            $v = $this->valueOn($a, $begin);
            if ($v !== null) {
                $starts[$a->id] = [$begin, $v];
            } elseif ($b = $this->balancesOf($a)->first()) {
                $starts[$a->id] = [$b->date, $b->balance];
            }
        }

        return $points->map(function (Carbon $d) use ($starts) {
            $value = 0;
            $basis = 0;
            foreach ($this->accounts as $a) {
                if (! isset($starts[$a->id]) || $starts[$a->id][0]->gt($d)) {
                    continue;
                }
                $value += $this->valueOn($a, $d) ?? 0;
                $basis += $starts[$a->id][1] + $this->flowBetween($a, $starts[$a->id][0], $d);
            }

            return ['date' => $d->toDateString(), 'value' => $value, 'basis' => $basis];
        })->all();
    }

    /** Totals by kind and by month for the timeframe, plus fund purchases. */
    public function activity(): array
    {
        $rows = $this->accounts->flatMap->activities
            ->filter(fn ($x) => (! $this->from || $x->date->gt($this->from)) && $x->date->lte($this->today))
            ->sortBy('date');
        $byKind = [];
        foreach (array_keys(InvestmentActivity::KINDS) as $k) {
            $byKind[$k] = (int) $rows->where('kind', $k)->sum('amount');
        }
        $months = $rows->groupBy(fn ($x) => $x->date->format('Y-m'))->map(fn ($g, $m) => [
            'month' => $m,
            'employee' => (int) $g->where('kind', 'employee')->sum('amount'),
            'employer' => (int) $g->where('kind', 'employer')->sum('amount'),
            'loan_repayment' => (int) $g->where('kind', 'loan_repayment')->sum('amount'),
            'deposit' => (int) $g->where('kind', 'deposit')->sum('amount'),
            'out' => (int) $g->whereIn('kind', ['withdrawal', 'loan'])->sum('amount'),
            'earnings' => (int) $g->where('kind', 'earnings')->sum('amount'),
        ])->sortKeysDesc()->values();

        // Shares bought with contributions or buys, per fund, valued at the
        // latest price seen for that fund.
        $funds = [];
        $latest = [];
        foreach ($this->accounts->flatMap->activities->sortBy('date') as $x) {
            if ($x->price && $x->fund) {
                $latest[$x->fund] = ['price' => $x->price, 'date' => $x->date->toDateString()];
            }
        }
        foreach ($rows as $x) {
            if (! $x->fund || ! $x->units || ! $x->price || in_array($x->kind, ['sell', 'withdrawal', 'loan', 'fee'], true)) {
                continue;
            }
            $f = $funds[$x->fund] ??= ['name' => $x->fund, 'units' => 0.0, 'cost' => 0, 'first_price' => $x->price, 'first_date' => $x->date->toDateString()];
            $f['units'] += $x->units;
            $f['cost'] += abs($x->amount);
            $funds[$x->fund] = $f;
        }
        $funds = array_values(array_map(function ($f) use ($latest) {
            $l = $latest[$f['name']];
            $value = (int) round($f['units'] * $l['price'] * 100);

            return $f + [
                'avg_price' => $f['units'] > 0 ? $f['cost'] / 100 / $f['units'] : null,
                'last_price' => $l['price'],
                'last_date' => $l['date'],
                'value' => $value,
                'gain' => $value - $f['cost'],
                'price_change' => $f['first_price'] > 0 ? $l['price'] / $f['first_price'] - 1 : null,
            ];
        }, $funds));
        usort($funds, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'by_kind' => $byKind,
            'put_in' => (int) $rows->whereIn('kind', InvestmentActivity::flowKinds())->sum('amount'),
            'pay_dates' => $rows->where('kind', 'employee')->pluck('date')->map->toDateString()->unique()->count(),
            'first' => $rows->first()?->date->toDateString(),
            'last' => $rows->last()?->date->toDateString(),
            'months' => $months,
            'funds' => $funds,
        ];
    }

    /** Latest holdings of each account, and a combined allocation. */
    public function holdings(): array
    {
        $accounts = [];
        $combined = [];
        foreach ($this->accounts as $a) {
            $asOf = $a->holdings->max('as_of');
            if (! $asOf) {
                continue;
            }
            $rows = $a->holdings->filter(fn ($h) => $h->as_of->eq($asOf))->sortByDesc('value')->values();
            $total = (int) $rows->sum('value');
            $accounts[] = [
                'id' => $a->id,
                'name' => $a->name,
                'as_of' => $asOf->toDateString(),
                'total' => $total,
                'rows' => $rows->map(fn ($h) => [
                    'id' => $h->id,
                    'symbol' => $h->symbol,
                    'name' => $h->name,
                    'quantity' => $h->quantity,
                    'price' => $h->price,
                    'value' => $h->value,
                    'cost' => $h->cost,
                    'share' => $total > 0 ? $h->value / $total : 0,
                ])->all(),
            ];
            foreach ($rows as $h) {
                $key = $h->symbol ?: mb_strtolower($h->name);
                $combined[$key] ??= ['symbol' => $h->symbol, 'name' => $h->name, 'value' => 0];
                $combined[$key]['value'] += $h->value;
            }
        }
        $sum = array_sum(array_column($combined, 'value'));
        $combined = array_values(array_map(fn ($c) => $c + ['share' => $sum > 0 ? $c['value'] / $sum : 0], $combined));
        usort($combined, fn ($a, $b) => $b['value'] <=> $a['value']);

        return ['accounts' => $accounts, 'combined' => $combined, 'total' => $sum];
    }
}
