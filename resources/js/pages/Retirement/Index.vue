<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { axisDollars, dollars, niceScale } from '@/lib/format';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, watch } from 'vue';

interface Inputs {
    birth_year: number | null;
    retire_age: number;
    plan_to_age: number;
    saving: number;
    loan_payment: number;
    roth_start: number;
    roth_monthly: number;
    social_security: number;
    ss_age: number;
    spending: number;
    stock_now: number;
    stock_later: number;
    stock_return: number;
    bond_return: number;
    inflation: number;
    tax_rate: number;
}
interface Projection {
    points: { age: number; balance: number }[];
    at_retirement: number;
    runs_out_at: number | null;
    end: number;
}
const props = defineProps<{
    facts: {
        accounts: { name: string; kind: string; value: number; tax_free: boolean }[];
        pretax: number;
        tax_free: number;
        loan: number;
        saving_monthly: number;
        loan_payment_monthly: number;
        stock_share: number | null;
        spending_monthly: number;
        cash: number;
    };
    defaults: Inputs;
    inputs: Inputs;
    plan: {
        age: number;
        years_to_go: number;
        status: 'on_track' | 'close' | 'short';
        expected: Projection;
        cautious: Projection;
        withdrawal_rate: number | null;
        four_percent_monthly: number;
        extra_needed: number | null;
        retire_at_needed: number | null;
        could_retire_at: number | null;
        return_expected: number;
    } | null;
}>();

const form = useForm<Inputs>({ ...props.inputs });
watch(
    () => props.inputs,
    (v) => Object.assign(form, v),
);
const save = () => form.put(route('retirement.update'), { preserveScroll: true, preserveState: true });
const reset = () => router.delete(route('retirement.reset'), { preserveScroll: true, onSuccess: () => Object.assign(form, props.inputs) });

type Key = keyof Inputs;
interface Field {
    key: Key;
    label: string;
    unit?: '$' | '%' | 'age';
    step?: string;
    hint?: string;
}
const fromApp = (_k: Key, text: string) => text;
const groups = computed<{ title: string; note?: string; fields: Field[] }[]>(() => [
    {
        title: 'You',
        fields: [
            { key: 'birth_year', label: 'Year you were born' },
            { key: 'retire_age', label: 'Retire at age', unit: 'age' },
            { key: 'plan_to_age', label: 'Plan for money to last to age', unit: 'age' },
        ],
    },
    {
        title: 'Until you retire, each month',
        fields: [
            {
                key: 'saving',
                label: 'Put into your 401(k), you and employer',
                unit: '$',
                hint: fromApp('saving', `Your last 12 months: ${dollars(props.facts.saving_monthly)}`),
            },
            {
                key: 'loan_payment',
                label: '401(k) loan repayment',
                unit: '$',
                hint: props.facts.loan ? `Goes back into the 401(k) until the ${dollars(props.facts.loan)} loan is repaid` : 'No loan found',
            },
            { key: 'roth_start', label: 'One-time deposit into a Roth IRA', unit: '$', hint: 'For example, the Fidelity Roth you plan to open' },
            { key: 'roth_monthly', label: 'Then into the Roth each month', unit: '$' },
        ],
    },
    {
        title: 'Once you retire, each month',
        fields: [
            {
                key: 'spending',
                label: 'Spending',
                unit: '$',
                hint:
                    fromApp('spending', `Your average over the last 12 months: ${dollars(props.facts.spending_monthly)}`) ||
                    'Your average over the last 12 months',
            },
            {
                key: 'social_security',
                label: 'Social Security',
                unit: '$',
                hint: 'Your estimate is on your Social Security statement at ssa.gov/myaccount',
            },
            { key: 'ss_age', label: 'Social Security starts at age', unit: 'age' },
            { key: 'tax_rate', label: 'Tax on 401(k) withdrawals', unit: '%', hint: 'Roth money comes out tax-free' },
        ],
    },
    {
        title: 'Investments',
        note: 'Moving from more stocks now to fewer at retirement is the usual way to lower risk as you get closer.',
        fields: [
            {
                key: 'stock_now',
                label: 'In stocks today',
                unit: '%',
                hint: props.facts.stock_share !== null ? `Your holdings today: ${props.facts.stock_share}%` : '',
            },
            { key: 'stock_later', label: 'In stocks once retired', unit: '%' },
            { key: 'stock_return', label: 'Stocks grow a year', unit: '%', step: '0.1' },
            { key: 'bond_return', label: 'Bonds grow a year', unit: '%', step: '0.1' },
            { key: 'inflation', label: 'Prices rise a year', unit: '%', step: '0.1' },
        ],
    },
]);

const p = computed(() => props.plan);
const retireAge = computed(() => props.inputs.retire_age);
const lastAge = computed(() => props.inputs.plan_to_age);
const headline = computed(() => {
    if (!p.value) return '';
    return { on_track: 'On track', close: 'Close, with little room to spare', short: 'Not on track yet' }[p.value.status];
});
const tone = computed(() =>
    !p.value ? '' : { on_track: 'text-emerald-700 dark:text-emerald-400', close: 'text-amber-600', short: 'text-red-600' }[p.value.status],
);
const lasts = (pr: Projection) => (pr.runs_out_at === null ? `past ${lastAge.value}` : `age ${pr.runs_out_at}`);

const notes = computed(() => {
    const x = p.value;
    if (!x) return [];
    const i = props.inputs;
    const out: string[] = [];
    out.push(
        `At ${i.retire_age}, in about ${Math.round(x.years_to_go)} years, you'd have about ${dollars(x.expected.at_retirement)} saved, in today's dollars.`,
    );
    const ssNow = i.ss_age <= i.retire_age ? i.social_security : 0;
    const need = Math.max(0, i.spending - ssNow);
    if (i.social_security && i.ss_age > i.retire_age) {
        out.push(
            `From ${i.retire_age} to ${i.ss_age} your savings cover all ${dollars(i.spending * 100)} a month. After that, Social Security covers ${dollars(i.social_security * 100)} of it.`,
        );
    } else {
        out.push(
            `Your savings need to cover ${dollars(need * 100)} of your ${dollars(i.spending * 100)} a month${ssNow ? ' after Social Security' : ''}.`,
        );
    }
    if (x.withdrawal_rate !== null && need > 0) {
        const r = x.withdrawal_rate.toFixed(1);
        out.push(
            x.withdrawal_rate <= 4
                ? `That's ${r}% of your savings in the first year. Around 4% is a common pace for money to last 30 years.`
                : `That's ${r}% of your savings in the first year, above the 4% often used as a pace that lasts 30 years.`,
        );
    }
    out.push(
        `With the growth you set, your money lasts ${lasts(x.expected)}. If investments grow 2 points a year less, it lasts ${lasts(x.cautious)}.`,
    );
    return out;
});
const fixes = computed(() => {
    const x = p.value;
    if (!x) return [];
    const out: string[] = [];
    if (x.extra_needed !== null) out.push(`Put about ${dollars(x.extra_needed)} more a month into your 401(k) until you retire.`);
    if (x.retire_at_needed !== null) out.push(`Or retire at ${x.retire_at_needed} instead of ${props.inputs.retire_age}.`);
    out.push('Or plan to spend less once retired, or wait to start Social Security: each year you wait (up to 70) raises it about 7 to 8%.');
    return out;
});

// Chart: savings by age, two paths.
const narrow = useMediaQuery('(max-width: 640px)');
const W = computed(() => (narrow.value ? 360 : 720));
const H = computed(() => (narrow.value ? 220 : 260));
const pad = computed(() => ({ l: narrow.value ? 46 : 56, r: 10, t: 12, b: 24 }));
const scale = computed(() =>
    niceScale(0, Math.max(...(p.value ? [...p.value.expected.points, ...p.value.cautious.points].map((q) => q.balance) : [1]), 1)),
);
const ageMin = computed(() => p.value?.expected.points[0]?.age ?? 0);
const x = (age: number) => pad.value.l + ((age - ageMin.value) / Math.max(1, lastAge.value - ageMin.value)) * (W.value - pad.value.l - pad.value.r);
const y = (v: number) => pad.value.t + ((scale.value.max - v) / (scale.value.max - scale.value.min || 1)) * (H.value - pad.value.t - pad.value.b);
const line = (pr: Projection) => pr.points.map((q) => `${x(q.age)},${y(Math.max(0, q.balance))}`).join(' ');
const ageTicks = computed(() => {
    const step = narrow.value ? 10 : 5;
    const out: number[] = [];
    for (let a = Math.ceil(ageMin.value / step) * step; a <= lastAge.value; a += step) out.push(a);
    return out;
});
</script>

<template>
    <Head title="Retirement" />
    <AppLayout :breadcrumbs="[{ title: 'Retirement', href: '/retirement' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Retirement</h1>
                <p class="text-sm text-muted-foreground">
                    Will your savings last? An estimate in today's dollars, using your own numbers from the app. It's a guide, not financial advice.
                </p>
            </div>

            <Card v-if="!plan">
                <CardHeader>
                    <CardTitle class="text-base">First, the year you were born</CardTitle>
                    <CardDescription>Everything else starts from what the app already knows, and you can change any of it.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="save">
                        <div class="grid gap-1.5">
                            <Label for="r-birth">Year you were born</Label>
                            <Input id="r-birth" v-model.number="form.birth_year" type="number" inputmode="numeric" class="w-36" required />
                        </div>
                        <Button type="submit" :disabled="form.processing">Show my projection</Button>
                    </form>
                    <InputError class="mt-2" :message="form.errors.birth_year" />
                </CardContent>
            </Card>

            <template v-else>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>Your plan</CardDescription>
                            <CardTitle class="text-2xl" :class="tone">{{ headline }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            {{
                                plan.status === 'on_track'
                                    ? 'Your money lasts even if investments grow less than you set.'
                                    : plan.status === 'close'
                                      ? 'It lasts with the growth you set, but not if growth is lower.'
                                      : `Your money runs out at ${plan.expected.runs_out_at} with the growth you set.`
                            }}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>Saved at {{ inputs.retire_age }}</CardDescription>
                            <CardTitle class="text-2xl tabular-nums">{{ dollars(plan.expected.at_retirement) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            in today's dollars. At 4% a year that's about {{ dollars(plan.four_percent_monthly) }} a month.
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>Money lasts</CardDescription>
                            <CardTitle class="text-2xl tabular-nums">{{
                                plan.expected.runs_out_at === null ? `Past ${inputs.plan_to_age}` : `To ${plan.expected.runs_out_at}`
                            }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            {{ plan.cautious.runs_out_at === null ? `Still past ${inputs.plan_to_age}` : `To ${plan.cautious.runs_out_at}` }} if
                            growth is 2 points lower
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Your savings by age</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Projected savings by age">
                            <g v-for="t in scale.ticks" :key="t">
                                <line :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" class="stroke-border" />
                                <text :x="pad.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-muted-foreground text-[11px]">
                                    {{ axisDollars(t) }}
                                </text>
                            </g>
                            <line
                                :x1="x(retireAge)"
                                :x2="x(retireAge)"
                                :y1="pad.t"
                                :y2="H - pad.b"
                                class="stroke-muted-foreground"
                                stroke-dasharray="3 3"
                            />
                            <text :x="x(retireAge) + 4" :y="pad.t + 10" class="fill-muted-foreground text-[11px]">Retire at {{ retireAge }}</text>
                            <polyline :points="line(plan.cautious)" fill="none" class="stroke-amber-500" stroke-width="2" stroke-dasharray="5 4" />
                            <polyline :points="line(plan.expected)" fill="none" class="stroke-sky-700" stroke-width="2.5" />
                            <text v-for="a in ageTicks" :key="a" :x="x(a)" :y="H - 6" text-anchor="middle" class="fill-muted-foreground text-[11px]">
                                {{ a }}
                            </text>
                        </svg>
                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"
                                ><span class="h-0.5 w-4 bg-sky-700" />With the growth you set (about {{ plan.return_expected }}% a year once
                                retired)</span
                            >
                            <span class="inline-flex items-center gap-1.5"
                                ><span class="h-0.5 w-4 border-t-2 border-dashed border-amber-500" />2 points a year lower</span
                            >
                        </div>
                    </CardContent>
                </Card>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 [&>*]:min-w-0">
                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-base">The short version</CardTitle></CardHeader>
                        <CardContent>
                            <ul class="list-disc space-y-1.5 pl-5 text-sm">
                                <li v-for="n in notes" :key="n">{{ n }}</li>
                            </ul>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader class="pb-2">
                            <CardTitle class="text-base">{{ plan.status === 'on_track' ? 'Room to spare' : 'What would get you there' }}</CardTitle>
                            <CardDescription v-if="plan.status !== 'on_track'"
                                >Each one on its own, even if growth is 2 points lower.</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <ul v-if="plan.status !== 'on_track'" class="list-disc space-y-1.5 pl-5 text-sm">
                                <li v-for="f in fixes" :key="f">{{ f }}</li>
                            </ul>
                            <p v-else class="text-sm">
                                <template v-if="plan.could_retire_at !== null"
                                    >With these numbers you could retire as early as {{ plan.could_retire_at }} and still be on track if growth is
                                    lower.</template
                                >
                                <template v-else
                                    >You're on track at {{ inputs.retire_age }}. Retiring earlier would not leave the same cushion.</template
                                >
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </template>

            <Card v-if="plan">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Your numbers</CardTitle>
                    <CardDescription>Change anything and the projection updates. Amounts are in today's dollars.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-6">
                    <form class="grid gap-6" @submit.prevent="save">
                        <fieldset v-for="g in groups" :key="g.title" class="grid gap-3">
                            <legend class="mb-1 text-sm font-medium">{{ g.title }}</legend>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div v-for="f in g.fields" :key="f.key" class="grid content-start gap-1.5">
                                    <Label :for="`r-${f.key}`">{{ f.label }}</Label>
                                    <div class="relative">
                                        <span
                                            v-if="f.unit === '$'"
                                            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"
                                            >$</span
                                        >
                                        <Input
                                            :id="`r-${f.key}`"
                                            v-model.number="form[f.key]"
                                            type="number"
                                            :step="f.step ?? '1'"
                                            inputmode="decimal"
                                            :class="[f.unit === '$' ? 'pl-6' : '', f.unit === '%' ? 'pr-7' : '']"
                                            @change="save"
                                        />
                                        <span
                                            v-if="f.unit === '%'"
                                            class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"
                                            >%</span
                                        >
                                    </div>
                                    <span v-if="f.hint" class="text-xs text-muted-foreground">{{ f.hint }}</span>
                                    <InputError :message="form.errors[f.key]" />
                                </div>
                            </div>
                            <p v-if="g.note" class="text-xs text-muted-foreground">{{ g.note }}</p>
                        </fieldset>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button type="submit" :disabled="form.processing">Update</Button>
                            <Button type="button" variant="outline" @click="reset">Use the app's numbers again</Button>
                            <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">What the projection starts from</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-3 text-sm">
                    <p v-if="!facts.accounts.length" class="text-muted-foreground">
                        No investment accounts yet. Add your 401(k) on the
                        <Link :href="route('investments.index')" class="underline">Investments page</Link> so it counts.
                    </p>
                    <ul v-else class="grid grid-cols-1 divide-y">
                        <li v-for="a in facts.accounts" :key="a.name" class="flex justify-between gap-3 py-2">
                            <span class="min-w-0 truncate"
                                >{{ a.name }} <span class="text-muted-foreground">· {{ a.kind }}{{ a.tax_free ? ', tax-free' : '' }}</span></span
                            >
                            <span class="tabular-nums">{{ dollars(a.value) }}</span>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Your bank accounts ({{ dollars(facts.cash) }}) aren't counted: they're your emergency fund. A 401(k) loan still owed when you
                        leave your job is usually treated as a withdrawal, so it's worth paying off before you retire. Growth is shown after
                        inflation, so every amount is in today's dollars.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
