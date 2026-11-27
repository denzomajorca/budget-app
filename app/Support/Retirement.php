<?php

namespace App\Support;

use App\Models\InvestmentAccount;
use App\Models\InvestmentActivity;
use Illuminate\Support\Carbon;

/**
 * A simple retirement projection, month by month, in today's dollars
 * (returns minus inflation). Before retirement your savings and 401(k)
 * loan repayments go in; after, your spending minus Social Security comes
 * out, with tax taken from 401(k) and IRA money. It is an estimate, not advice.
 */
class Retirement
{
    /** Kinds whose withdrawals are treated as tax-free. */
    public const TAX_FREE = ['roth_ira', 'hsa', 'brokerage'];

    /** What the app already knows. Money in cents. */
    public static function facts(?Carbon $today = null): array
    {
        $today ??= now();
        $accounts = InvestmentAccount::with(['balances', 'activities', 'holdings'])->orderBy('name')->get();
        $inv = new InvestmentReport($accounts, 'all', $today);

        $list = [];
        $pretax = 0;
        $free = 0;
        $loan = 0;
        foreach ($accounts as $a) {
            $value = $inv->valueOn($a, $today) ?? 0;
            $taxFree = in_array($a->kind, self::TAX_FREE, true);
            $taxFree ? $free += $value : $pretax += $value;
            $l = $a->balances->whereNotNull('loan_balance')->sortBy('date')->last();
            $loan += max(0, (int) $l?->loan_balance);
            $list[] = ['name' => $a->name, 'kind' => InvestmentAccount::KINDS[$a->kind] ?? 'Other', 'value' => $value, 'tax_free' => $taxFree];
        }

        $acts = $accounts->flatMap->activities;
        $mix = $inv->holdings();

        return [
            'accounts' => $list,
            'pretax' => $pretax,
            'tax_free' => $free,
            'loan' => $loan,
            'saving_monthly' => self::monthly($acts, ['employee', 'employer', 'deposit', 'statement_in'], 12, $today),
            'loan_payment_monthly' => $loan > 0 ? self::monthly($acts, ['loan_repayment'], 3, $today) : 0,
            'stock_share' => $mix['total'] > 0 ? (int) round($mix['mix']['stocks'] / $mix['total'] * 100) : null,
            'spending_monthly' => ReportData::averageSpending($today, 12),
            'cash' => ReportData::worthOn($today, null, $inv)['cash'],
        ];
    }

    /** Average a month of these kinds over the last $months (or since the first one, if newer). */
    private static function monthly($acts, array $kinds, int $months, Carbon $today): int
    {
        $rows = $acts->filter(fn ($x) => in_array($x->kind, $kinds, true) && $x->date->lte($today));
        if ($rows->isEmpty()) {
            return 0;
        }
        $from = $today->copy()->subMonthsNoOverflow($months);
        $first = $rows->min('date');
        $span = $first->gt($from) ? max(1, (int) ceil($first->diffInDays($today) / 30.44)) : $months;
        $sum = $rows->filter(fn ($x) => $x->date->gt($from))->sum(fn ($x) => InvestmentActivity::signed($x->kind, $x->amount));

        return (int) round($sum / $span);
    }

    /** Starting answers, filled in from the app where it can. Whole dollars and percents. */
    public static function defaults(array $facts): array
    {
        return [
            'birth_year' => null,
            'retire_age' => 65,
            'plan_to_age' => 95,
            'saving' => (int) round(max(0, $facts['saving_monthly']) / 100),
            'loan_payment' => (int) round($facts['loan_payment_monthly'] / 100),
            'roth_start' => 0,
            'roth_monthly' => 0,
            'social_security' => 0,
            'ss_age' => 67,
            'spending' => (int) round($facts['spending_monthly'] / 100),
            'stock_now' => $facts['stock_share'] ?? 60,
            'stock_later' => 50,
            'stock_return' => 7.0,
            'bond_return' => 4.0,
            'inflation' => 2.5,
            'tax_rate' => 12,
        ];
    }

    public const RULES = [
        'birth_year' => ['nullable', 'integer', 'between:1920,2010'],
        'retire_age' => ['required', 'integer', 'between:40,80'],
        'plan_to_age' => ['required', 'integer', 'between:70,110'],
        'saving' => ['required', 'integer', 'between:0,100000'],
        'loan_payment' => ['required', 'integer', 'between:0,100000'],
        'roth_start' => ['required', 'integer', 'between:0,10000000'],
        'roth_monthly' => ['required', 'integer', 'between:0,100000'],
        'social_security' => ['required', 'integer', 'between:0,100000'],
        'ss_age' => ['required', 'integer', 'between:62,70'],
        'spending' => ['required', 'integer', 'between:0,1000000'],
        'stock_now' => ['required', 'integer', 'between:0,100'],
        'stock_later' => ['required', 'integer', 'between:0,100'],
        'stock_return' => ['required', 'numeric', 'between:-10,20'],
        'bond_return' => ['required', 'numeric', 'between:-10,20'],
        'inflation' => ['required', 'numeric', 'between:0,15'],
        'tax_rate' => ['required', 'integer', 'between:0,60'],
    ];

    /** The full picture: two projections, whether it lasts, and what would close a gap. */
    public static function plan(array $in, array $facts, ?Carbon $today = null): ?array
    {
        $today ??= now();
        if (! $in['birth_year']) {
            return null;
        }
        $age = self::ageNow($in, $today);
        $expected = self::project($in, $facts, $today);
        $cautious = self::project($in, $facts, $today, -2.0);
        $lasts = fn ($p) => $p['runs_out_at'] === null;

        $status = $lasts($cautious) ? 'on_track' : ($lasts($expected) ? 'close' : 'short');
        $extra = null;
        $later = null;
        $earlier = null;
        if ($status !== 'on_track') {
            if ($lasts(self::project($in, $facts, $today, -2.0, 20000))) {
                [$lo, $hi] = [0.0, 20000.0];
                for ($i = 0; $i < 24; $i++) {
                    $mid = ($lo + $hi) / 2;
                    $lasts(self::project($in, $facts, $today, -2.0, $mid)) ? $hi = $mid : $lo = $mid;
                }
                $extra = (int) (ceil($hi / 25) * 25 * 100);
            }
            for ($r = $in['retire_age'] + 1; $r <= min(75, $in['plan_to_age'] - 1); $r++) {
                if ($lasts(self::project(['retire_age' => $r] + $in, $facts, $today, -2.0))) {
                    $later = $r;
                    break;
                }
            }
        } else {
            for ($r = max((int) ceil($age), 50); $r < $in['retire_age']; $r++) {
                if ($lasts(self::project(['retire_age' => $r] + $in, $facts, $today, -2.0))) {
                    $earlier = $r;
                    break;
                }
            }
        }

        $atRetire = $expected['at_retirement'];
        $ssAtStart = $in['ss_age'] <= $in['retire_age'] ? $in['social_security'] : 0;
        $need = max(0, $in['spending'] - $ssAtStart) * 12;

        return [
            'age' => round($age, 1),
            'years_to_go' => max(0, round($in['retire_age'] - $age, 1)),
            'status' => $status,
            'expected' => $expected,
            'cautious' => $cautious,
            // Share of savings taken out in the first year of retirement; about 4% is a common safe pace.
            'withdrawal_rate' => $atRetire > 0 ? $need * 100 * 100 / $atRetire : null,
            'four_percent_monthly' => (int) round($atRetire * 0.04 / 12),
            'extra_needed' => $extra,
            'retire_at_needed' => $later,
            'could_retire_at' => $earlier,
            'return_expected' => round(self::nominal($in, $in['stock_later']), 1),
        ];
    }

    private static function ageNow(array $in, Carbon $today): float
    {
        return $today->year - $in['birth_year'] + ($today->month - 1) / 12;
    }

    private static function nominal(array $in, float $stockShare, float $shift = 0.0): float
    {
        return $stockShare / 100 * $in['stock_return'] + (1 - $stockShare / 100) * $in['bond_return'] + $shift;
    }

    /**
     * Month by month to the plan's last age. Amounts in cents, today's dollars.
     *
     * @return array{points: list<array{age:float, balance:int}>, at_retirement:int, runs_out_at:?int, end:int}
     */
    public static function project(array $in, array $facts, Carbon $today, float $shift = 0.0, float $extraMonthly = 0.0): array
    {
        $age0 = self::ageNow($in, $today);
        $retire = (float) $in['retire_age'];
        $months = max(1, (int) ceil(($in['plan_to_age'] - $age0) * 12));
        $pretax = $facts['pretax'] / 100;
        $free = $facts['tax_free'] / 100 + $in['roth_start'];
        $loan = $facts['loan'] / 100;
        $keep = 1 - min(0.95, $in['tax_rate'] / 100);
        $points = [['age' => round($age0, 2), 'balance' => (int) round(($pretax + $free) * 100)]];
        $atRetire = $age0 >= $retire ? $pretax + $free : null;
        $ranOut = null;

        for ($m = 1; $m <= $months; $m++) {
            $age = $age0 + $m / 12;
            $share = $age < $retire && $retire > $age0
                ? $in['stock_now'] + ($in['stock_later'] - $in['stock_now']) * (($age - $age0) / ($retire - $age0))
                : $in['stock_later'];
            $real = (1 + self::nominal($in, $share, $shift) / 100) / (1 + $in['inflation'] / 100) - 1;
            $g = (1 + max(-0.99, $real)) ** (1 / 12);
            $pretax *= $g;
            $free *= $g;

            if ($age <= $retire) {
                $pretax += $in['saving'] + $extraMonthly;
                $free += $in['roth_monthly'];
                if ($loan > 0) {
                    $pay = min($loan, (float) $in['loan_payment']);
                    $pretax += $pay;
                    $loan -= $pay;
                }
                if ($atRetire === null && $age + 1 / 12 > $retire) {
                    $atRetire = $pretax + $free;
                }
            } else {
                $need = $in['spending'] - ($age >= $in['ss_age'] ? $in['social_security'] : 0);
                if ($need > 0) {
                    $fromPre = min($pretax, $need / $keep);
                    $pretax -= $fromPre;
                    $need -= $fromPre * $keep;
                    $fromFree = min($free, $need);
                    $free -= $fromFree;
                    $need -= $fromFree;
                    if ($need > 0.5 && $ranOut === null) {
                        $ranOut = (int) floor($age);
                    }
                }
            }
            if ($m % 12 === 0 || $m === $months) {
                $points[] = ['age' => round($age, 2), 'balance' => (int) round(($pretax + $free) * 100)];
            }
        }

        return [
            'points' => $points,
            'at_retirement' => (int) round(($atRetire ?? $pretax + $free) * 100),
            'runs_out_at' => $ranOut,
            'end' => (int) round(($pretax + $free) * 100),
        ];
    }
}
