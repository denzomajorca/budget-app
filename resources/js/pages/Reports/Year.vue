<script setup lang="ts">
import MonthBars from '@/components/MonthBars.vue';
import ReportsTabs from '@/components/ReportsTabs.vue';
import SpanPicker from '@/components/SpanPicker.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dollars, percent, signedDollars } from '@/lib/format';
import { shortDate } from '@/lib/money';
import { Head, router } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed } from 'vue';

interface Totals {
    income: number;
    spending: number;
    net: number;
    rate: number | null;
}
interface Worth {
    cash: number;
    other: number;
    debts: number;
    investments: number;
    net: number;
}
const props = defineProps<{
    year: number;
    years: number[];
    partial: boolean;
    from: string;
    to: string;
    current: Totals;
    previous: Totals | null;
    months: { month: string; label: string; income: number; spending: number; net: number; future: boolean }[];
    categories: { id: number | null; name: string; total: number; previous: number | null }[];
    payees: { payee: string; count: number; total: number; category: string }[];
    worth: { start: Worth; end: Worth };
    investments: { value: number; start: number | null; put_in: number | null; gain: number | null; return: number | null } | null;
    budget: { budgeted: number; spent: number; over: { name: string; amount: number }[]; under: { name: string; amount: number }[] } | null;
    emergency: { cash: number; monthly_spending: number; months: number | null };
}>();

const pick = (y: number | string) => router.get(route('reports.year'), { year: y }, { preserveScroll: true });
const change = (a: number, b: number | null | undefined) => (b ? (a - b) / Math.abs(b) : null);
const tone = (v: number | null, good = 1) => (v === null || v === 0 ? '' : v * good > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600');
const shown = computed(() => props.months.filter((m) => !m.future));
const biggest = computed(() => props.categories[0]?.total ?? 1);
const vsLabel = computed(() => (props.partial ? `same months of ${props.year - 1}` : String(props.year - 1)));
const print = () => window.print();

// A few plain-language takeaways.
const notes = computed(() => {
    const out: string[] = [];
    const c = props.current;
    if (c.rate !== null) {
        out.push(
            c.rate >= 0.15
                ? `You kept ${percent(c.rate)} of your income, at or above the 15% many planners suggest.`
                : c.rate >= 0
                  ? `You kept ${percent(c.rate)} of your income. Planners often suggest 15% or more, so there is room to grow.`
                  : `You spent ${dollars(-c.net)} more than you made.`,
        );
    }
    if (props.previous) {
        const d = change(c.spending, props.previous.spending);
        if (d !== null) out.push(`Spending was ${percent(Math.abs(d))} ${d >= 0 ? 'higher' : 'lower'} than the ${vsLabel.value}.`);
    }
    const up = props.categories
        .filter((x) => x.previous !== null && x.total - (x.previous ?? 0) > 50000)
        .sort((a, b) => b.total - (b.previous ?? 0) - (a.total - (a.previous ?? 0)))[0];
    if (up) out.push(`${up.name} grew the most: ${signedDollars(up.total - (up.previous ?? 0))} compared with the ${vsLabel.value}.`);
    const m = props.emergency.months;
    if (m !== null)
        out.push(
            m >= 3
                ? `Your bank accounts cover ${m.toFixed(1)} months of spending.`
                : `Your bank accounts cover ${m.toFixed(1)} months of spending; 3 to 6 is a common goal.`,
        );
    if (props.investments?.gain)
        out.push(
            `Investments ${props.investments.gain >= 0 ? 'grew' : 'lost'} ${dollars(Math.abs(props.investments.gain))} on top of what you put in.`,
        );
    return out;
});
</script>

<template>
    <Head :title="`${year} in review`" />
    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-4xl flex-1 flex-col gap-5 p-4 print:max-w-none print:p-0">
            <h1 class="text-xl font-semibold print:hidden">Reports</h1>
            <ReportsTabs current="year" />
            <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
                <SpanPicker label="Year" :value="year" :options="years.map((y) => ({ value: y, label: String(y) }))" @pick="pick" />
                <Button variant="outline" @click="print"><Printer class="size-4" /> Print or save as PDF</Button>
            </div>

            <header>
                <h2 class="text-2xl font-semibold">{{ year }} in review</h2>
                <p class="text-sm text-muted-foreground">
                    {{ shortDate(from) }} to {{ shortDate(to) }}<template v-if="partial"> (the year so far)</template>
                </p>
            </header>

            <section class="grid grid-cols-2 gap-3 md:grid-cols-4 print:grid-cols-4">
                <div
                    v-for="s in [
                        { k: 'Income', v: dollars(current.income), d: change(current.income, previous?.income), good: 1 },
                        { k: 'Spending', v: dollars(current.spending), d: change(current.spending, previous?.spending), good: -1 },
                        { k: current.net >= 0 ? 'Kept' : 'Overspent', v: dollars(Math.abs(current.net)), d: null, good: 1 },
                        { k: 'Savings rate', v: percent(current.rate), d: null, good: 1 },
                    ]"
                    :key="s.k"
                    class="break-inside-avoid rounded-lg border p-3"
                >
                    <div class="text-xs text-muted-foreground">{{ s.k }}</div>
                    <div class="text-xl font-semibold tabular-nums">{{ s.v }}</div>
                    <div v-if="s.d !== null" class="text-xs" :class="tone(s.d, s.good)">{{ percent(s.d, true) }} vs {{ vsLabel }}</div>
                </div>
            </section>

            <section v-if="notes.length" class="break-inside-avoid rounded-lg border bg-muted/30 p-4">
                <h3 class="mb-2 font-medium">The short version</h3>
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    <li v-for="n in notes" :key="n">{{ n }}</li>
                </ul>
            </section>

            <section class="break-inside-avoid">
                <h3 class="mb-2 font-medium">Month by month</h3>
                <MonthBars
                    aria-label="Income and spending by month"
                    :labels="shown.map((m) => m.label)"
                    :bars="[
                        { label: 'Income', fill: 'fill-emerald-600', swatch: 'bg-emerald-600', values: shown.map((m) => m.income) },
                        { label: 'Spending', fill: 'fill-amber-500', swatch: 'bg-amber-500', values: shown.map((m) => m.spending) },
                    ]"
                />
            </section>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 print:grid-cols-2 [&>*]:min-w-0">
                <section class="break-inside-avoid">
                    <h3 class="mb-2 font-medium">Where the money went</h3>
                    <ul class="grid grid-cols-1 gap-2 text-sm">
                        <li v-for="c in categories" :key="c.name">
                            <div class="flex justify-between gap-3">
                                <span class="min-w-0 truncate">{{ c.name }}</span>
                                <span class="shrink-0 tabular-nums"
                                    >{{ dollars(c.total)
                                    }}<span v-if="c.previous" class="ml-1 text-xs" :class="tone(change(c.total, c.previous), -1)">{{
                                        percent(change(c.total, c.previous), true)
                                    }}</span></span
                                >
                            </div>
                            <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-amber-500" :style="{ width: `${(c.total / biggest) * 100}%` }" />
                            </div>
                        </li>
                    </ul>
                </section>
                <section class="break-inside-avoid">
                    <h3 class="mb-2 font-medium">Top payees</h3>
                    <ol class="grid grid-cols-1 gap-1 text-sm">
                        <li v-for="(p, i) in payees" :key="p.payee" class="flex justify-between gap-3">
                            <span class="min-w-0 truncate"
                                ><span class="text-muted-foreground">{{ i + 1 }}.</span> {{ p.payee }}
                                <span class="text-xs text-muted-foreground">· {{ p.count }}×</span></span
                            >
                            <span class="shrink-0 tabular-nums">{{ dollars(p.total) }}</span>
                        </li>
                    </ol>
                </section>
            </div>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 print:grid-cols-2 [&>*]:min-w-0">
                <section class="break-inside-avoid rounded-lg border p-4">
                    <h3 class="mb-2 font-medium">Net worth</h3>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-xl font-semibold tabular-nums">{{ dollars(worth.end.net) }}</span>
                        <span class="text-sm tabular-nums" :class="tone(worth.end.net - worth.start.net)"
                            >{{ signedDollars(worth.end.net - worth.start.net) }} this year</span
                        >
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Investments</dt>
                        <dd class="text-right tabular-nums">{{ dollars(worth.end.investments) }}</dd>
                        <dt class="text-muted-foreground">Bank accounts</dt>
                        <dd class="text-right tabular-nums">{{ dollars(worth.end.cash + worth.end.other) }}</dd>
                        <dt class="text-muted-foreground">Owed</dt>
                        <dd class="text-right tabular-nums">{{ dollars(-worth.end.debts) }}</dd>
                        <dt class="text-muted-foreground">Emergency fund</dt>
                        <dd class="text-right tabular-nums">{{ emergency.months === null ? '—' : `${emergency.months.toFixed(1)} months` }}</dd>
                    </dl>
                </section>
                <section v-if="investments" class="break-inside-avoid rounded-lg border p-4">
                    <h3 class="mb-2 font-medium">Investments</h3>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-xl font-semibold tabular-nums">{{ dollars(investments.value) }}</span>
                        <span v-if="investments.return !== null" class="text-sm tabular-nums" :class="tone(investments.return)"
                            >{{ percent(investments.return, true) }} return</span
                        >
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                        <template v-if="investments.start !== null">
                            <dt class="text-muted-foreground">At the start</dt>
                            <dd class="text-right tabular-nums">{{ dollars(investments.start) }}</dd>
                        </template>
                        <template v-if="investments.put_in !== null">
                            <dt class="text-muted-foreground">You put in</dt>
                            <dd class="text-right tabular-nums">{{ dollars(investments.put_in) }}</dd>
                        </template>
                        <template v-if="investments.gain !== null">
                            <dt class="text-muted-foreground">Growth</dt>
                            <dd class="text-right tabular-nums" :class="tone(investments.gain)">{{ signedDollars(investments.gain) }}</dd>
                        </template>
                    </dl>
                </section>
            </div>

            <section v-if="budget" class="break-inside-avoid rounded-lg border p-4">
                <h3 class="mb-2 font-medium">Budget</h3>
                <p class="text-sm">
                    Budgeted {{ dollars(budget.budgeted) }} and spent {{ dollars(budget.spent) }} on those lines:
                    <b :class="tone(budget.budgeted - budget.spent)">{{
                        budget.budgeted >= budget.spent
                            ? `${dollars(budget.budgeted - budget.spent)} under`
                            : `${dollars(budget.spent - budget.budgeted)} over`
                    }}</b
                    >.
                </p>
                <div class="mt-2 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 print:grid-cols-2">
                    <div v-if="budget.over.length">
                        <div class="mb-1 text-xs text-muted-foreground">Most over budget</div>
                        <div v-for="o in budget.over" :key="o.name" class="flex justify-between gap-3">
                            <span class="min-w-0 truncate">{{ o.name }}</span
                            ><span class="shrink-0 tabular-nums text-red-600">+{{ dollars(o.amount) }}</span>
                        </div>
                    </div>
                    <div v-if="budget.under.length">
                        <div class="mb-1 text-xs text-muted-foreground">Most under budget</div>
                        <div v-for="o in budget.under" :key="o.name" class="flex justify-between gap-3">
                            <span class="min-w-0 truncate">{{ o.name }}</span
                            ><span class="shrink-0 tabular-nums text-emerald-700 dark:text-emerald-400">{{ dollars(-o.amount) }} left</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
