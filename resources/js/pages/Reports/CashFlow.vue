<script setup lang="ts">
import MonthBars from '@/components/MonthBars.vue';
import ReportsTabs from '@/components/ReportsTabs.vue';
import SpanPicker from '@/components/SpanPicker.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dollars, percent, signedDollars } from '@/lib/format';
import { shortDate } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Row {
    month: string;
    label: string;
    income: number;
    spending: number;
    net: number;
    rate: number | null;
}
const props = defineProps<{
    months: number;
    spans: number[];
    rows: Row[];
    totals: {
        income: number;
        spending: number;
        net: number;
        rate: number | null;
        avg_income: number;
        avg_spending: number;
        months_counted: number;
        months_saved: number;
    };
    best: Row | null;
    worst: Row | null;
    through: string;
}>();

const pick = (m: number | string) => router.get(route('reports.cashflow'), { months: m }, { preserveScroll: true, preserveState: true });
const short = computed(() => props.rows.map((r) => (props.rows.length > 12 ? r.label.replace(/ (\d\d)(\d\d)$/, " '$2") : r.label.split(' ')[0])));
const monthRange = (m: string) => {
    const [y, mo] = m.split('-').map(Number);
    const last = new Date(y, mo, 0).getDate();
    return { from: `${m}-01`, to: `${m}-${String(last).padStart(2, '0')}` };
};
const tone = (v: number) => (v >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600');
</script>

<template>
    <Head title="Cash flow" />
    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Reports</h1>
            <ReportsTabs current="cashflow" />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">
                    Money in against money out, through {{ shortDate(through) }}. Transfers between your accounts are left out.
                </p>
                <SpanPicker label="How many months" :value="months" :options="spans.map((s) => ({ value: s, label: `${s} months` }))" @pick="pick" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Income</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ dollars(totals.income) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">about {{ dollars(totals.avg_income) }} a month</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Spending</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ dollars(totals.spending) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">about {{ dollars(totals.avg_spending) }} a month</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>{{ totals.net >= 0 ? 'You kept' : 'You spent more than you made' }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums" :class="tone(totals.net)">{{ signedDollars(totals.net) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground"
                        >{{ totals.months_saved }} of {{ totals.months_counted }} months ended ahead</CardContent
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Savings rate</CardDescription>
                        <CardTitle class="text-2xl tabular-nums" :class="totals.rate === null ? '' : tone(totals.rate)">{{
                            percent(totals.rate)
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">of income not spent. Many planners aim for 15% or more.</CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Month by month</CardTitle>
                    <CardDescription v-if="best && worst && best.month !== worst.month">
                        Best month {{ best.label }} ({{ signedDollars(best.net) }}); toughest {{ worst.label }} ({{ signedDollars(worst.net) }}).
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <MonthBars
                        aria-label="Income and spending by month"
                        :labels="short"
                        :bars="[
                            { label: 'Income', fill: 'fill-emerald-600', swatch: 'bg-emerald-600', values: rows.map((r) => r.income) },
                            { label: 'Spending', fill: 'fill-amber-500', swatch: 'bg-amber-500', values: rows.map((r) => r.spending) },
                        ]"
                        :line="{
                            label: 'Kept (income minus spending)',
                            stroke: 'stroke-sky-700',
                            dot: 'fill-sky-700',
                            swatch: 'bg-sky-700',
                            values: rows.map((r) => r.net),
                        }"
                    />
                </CardContent>
            </Card>

            <div class="w-0 min-w-full overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Month</th>
                            <th class="px-3 py-2 text-right font-medium">Income</th>
                            <th class="px-3 py-2 text-right font-medium">Spending</th>
                            <th class="px-3 py-2 text-right font-medium">Kept</th>
                            <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Savings rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in [...rows].reverse()" :key="r.month" class="border-t">
                            <td class="px-3 py-2">{{ r.label }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ dollars(r.income) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <Link :href="route('transactions.index', monthRange(r.month))" class="underline-offset-4 hover:underline">{{
                                    dollars(r.spending)
                                }}</Link>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="tone(r.net)">{{ signedDollars(r.net) }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ percent(r.rate) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-medium">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ dollars(totals.income) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ dollars(totals.spending) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="tone(totals.net)">{{ signedDollars(totals.net) }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ percent(totals.rate) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
