<script setup lang="ts">
import MonthBars from '@/components/MonthBars.vue';
import ReportsTabs from '@/components/ReportsTabs.vue';
import SpanPicker from '@/components/SpanPicker.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dollars, signedDollars } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    category_id: number;
    name: string;
    monthly: number;
    hidden: boolean;
    spent: number[];
    budget: number;
    total: number;
    over: number;
}
const props = defineProps<{
    year: number;
    years: number[];
    months: { month: string; label: string; budget: number; spent: number; spent_all: number }[];
    lines: Line[];
    has_budgets: boolean;
    totals: { budget: number; spent: number; spent_all: number };
}>();

const pick = (y: number | string) => router.get(route('reports.budget'), { year: y }, { preserveScroll: true, preserveState: true });
const showHidden = ref(false);
const shown = computed(() => props.lines.filter((l) => showHidden.value || !l.hidden));
const left = computed(() => props.totals.budget - props.totals.spent);
const tone = (v: number) => (v >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600');
const monthBudget = (l: Line, i: number) =>
    l.spent[i] > l.monthly ? 'bg-red-500' : l.spent[i] > l.monthly * 0.9 ? 'bg-amber-400' : 'bg-emerald-500';
</script>

<template>
    <Head title="Budget vs actual" />
    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Reports</h1>
            <ReportsTabs current="budget" />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">How each budget line did, month by month. Only lines with a budget are counted.</p>
                <SpanPicker label="Year" :value="year" :options="years.map((y) => ({ value: y, label: String(y) }))" @pick="pick" />
            </div>

            <Card v-if="!has_budgets">
                <CardContent class="p-6 text-sm text-muted-foreground">
                    You haven’t set any budgets yet. <Link :href="route('budgets.index')" class="underline">Set them on the Budgets page</Link> and
                    this report fills in.
                </CardContent>
            </Card>
            <template v-else-if="months.length">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>Budgeted, {{ months.length }} months</CardDescription>
                            <CardTitle class="text-2xl tabular-nums">{{ dollars(totals.budget) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">{{ lines.length }} budget lines</CardContent>
                    </Card>
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>Spent on those lines</CardDescription>
                            <CardTitle class="text-2xl tabular-nums">{{ dollars(totals.spent) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">{{ dollars(totals.spent_all) }} spent in all, budgeted or not</CardContent>
                    </Card>
                    <Card>
                        <CardHeader class="pb-2">
                            <CardDescription>{{ left >= 0 ? 'Under budget by' : 'Over budget by' }}</CardDescription>
                            <CardTitle class="text-2xl tabular-nums" :class="tone(left)">{{ dollars(Math.abs(left)) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            {{ lines.filter((l) => l.total > l.budget).length }} of {{ lines.length }} lines went over for the year
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Each month</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <MonthBars
                            aria-label="Budget and spending by month"
                            :labels="months.map((m) => m.label)"
                            :bars="[
                                {
                                    label: 'Budgeted',
                                    fill: 'fill-slate-300 dark:fill-slate-600',
                                    swatch: 'bg-slate-300',
                                    values: months.map((m) => m.budget),
                                },
                                { label: 'Spent on budgeted lines', fill: 'fill-sky-600', swatch: 'bg-sky-600', values: months.map((m) => m.spent) },
                            ]"
                        />
                    </CardContent>
                </Card>

                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-medium">
                        By budget line <span class="text-sm font-normal text-muted-foreground">(most over budget first)</span>
                    </h2>
                    <Button v-if="lines.some((l) => l.hidden)" variant="ghost" size="sm" @click="showHidden = !showHidden">
                        {{ showHidden ? 'Hide' : 'Show' }} lines hidden on Budgets
                    </Button>
                </div>
                <div class="w-0 min-w-full overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Line</th>
                                <th class="hidden px-3 py-2 font-medium md:table-cell">Each month</th>
                                <th class="px-3 py-2 text-right font-medium">Budget</th>
                                <th class="px-3 py-2 text-right font-medium">Spent</th>
                                <th class="px-3 py-2 text-right font-medium">Left</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in shown" :key="l.category_id" class="border-t align-top">
                                <td class="px-3 py-2">
                                    {{ l.name }}
                                    <div class="text-xs text-muted-foreground">
                                        {{ dollars(l.monthly) }} a month<template v-if="l.over">
                                            · over in {{ l.over }} of {{ months.length }}</template
                                        >
                                    </div>
                                </td>
                                <td class="hidden px-3 py-2 md:table-cell">
                                    <span class="flex gap-0.5 pt-1">
                                        <span
                                            v-for="(s, i) in l.spent"
                                            :key="i"
                                            class="size-3 rounded-sm"
                                            :class="monthBudget(l, i)"
                                            :title="`${months[i]?.label}: ${dollars(s)} of ${dollars(l.monthly)}`"
                                        />
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dollars(l.budget) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dollars(l.total) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="tone(l.budget - l.total)">
                                    {{ signedDollars(l.budget - l.total) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    Squares: green under 90% of the month’s budget, yellow close to it, red over. Budgets use today’s amounts for every month.
                </p>
            </template>
            <Card v-else>
                <CardContent class="p-6 text-sm text-muted-foreground">No months to show for {{ year }} yet.</CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
