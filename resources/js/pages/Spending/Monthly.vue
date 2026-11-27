<script setup lang="ts">
import SpendingTabs from '@/components/SpendingTabs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { money } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Row {
    label: string;
    filter: string;
    values: number[];
    total: number;
    average: number;
    high: string[];
}
interface Group extends Row {
    children: Row[];
}
interface Totals {
    label: string;
    values: number[];
    total: number;
    average: number;
}
interface Section {
    kind: 'income' | 'expense';
    title: string;
    groups: Group[];
    totals: Totals;
}
interface Month {
    key: string;
    label: string;
    year: string;
    from: string;
    to: string;
    future: boolean;
}

const props = defineProps<{
    from: string;
    to: string;
    months: Month[];
    range: { from: string; to: string };
    sections: Section[];
    net: { values: number[]; total: number; average: number };
    counted: number;
    latest_year: number;
}>();

const pick = reactive({ from: props.from, to: props.to });
function load(from: string, to: string) {
    router.get(route('spending.monthly'), { from, to }, { preserveScroll: true });
}
function apply() {
    if (pick.from && pick.to) load(pick.from, pick.to);
}
const ym = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
function last12() {
    const end = new Date();
    const start = new Date(end.getFullYear(), end.getMonth() - 11, 1);
    load(ym(start), ym(end));
}
const thisYear = new Date().getFullYear();

const open = ref<Record<string, boolean>>({});
const key = (s: Section, g: Group) => s.kind + ':' + g.filter;
const allOpen = computed(() => props.sections.every((s) => s.groups.every((g) => !g.children.length || open.value[key(s, g)])));
function toggleAll() {
    const next = !allOpen.value;
    const o: Record<string, boolean> = {};
    for (const s of props.sections) for (const g of s.groups) if (g.children.length) o[key(s, g)] = next;
    open.value = o;
}
const multiYear = computed(() => new Set(props.months.map((m) => m.year)).size > 1);

/** Link to the transactions behind one amount (a month, or the whole range when no month is given). */
function txLink(filter: string, m?: Month) {
    return route('transactions.index', { category: filter, from: m ? m.from : props.range.from, to: m ? m.to : props.range.to });
}
const cell = (v: number) => (v === 0 ? '–' : money(v));
</script>

<template>
    <Head title="Spending by month" />
    <AppLayout :breadcrumbs="[{ title: 'Spending', href: '/spending' }]">
        <div class="mx-auto flex w-full max-w-[100rem] flex-col gap-4 p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Spending by month</h1>
                    <p class="text-sm text-muted-foreground">
                        Each category month by month.
                        <span class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">Highlighted</span>
                        months are well above that category's average. Tap any amount to see those transactions.
                    </p>
                </div>
                <SpendingTabs current="monthly" />
            </div>

            <Card>
                <CardContent class="flex flex-wrap items-end gap-3 p-4">
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">From</span>
                        <Input v-model="pick.from" type="month" class="w-44" @change="apply" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">To</span>
                        <Input v-model="pick.to" type="month" class="w-44" @change="apply" />
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" @click="load(`${thisYear}-01`, `${thisYear}-12`)">This year</Button>
                        <Button size="sm" variant="outline" @click="load(`${thisYear - 1}-01`, `${thisYear - 1}-12`)">Last year</Button>
                        <Button size="sm" variant="outline" @click="last12">Last 12 months</Button>
                    </div>
                    <Button size="sm" variant="ghost" class="ml-auto" @click="toggleAll">{{ allOpen ? 'Collapse all' : 'Expand all' }}</Button>
                </CardContent>
            </Card>

            <Card class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-separate border-spacing-0 text-sm tabular-nums">
                        <thead class="text-muted-foreground">
                            <tr>
                                <th class="sticky left-0 z-20 min-w-40 border-b bg-card px-3 py-2 text-left font-medium sm:min-w-56">Category</th>
                                <th
                                    v-for="m in months"
                                    :key="m.key"
                                    class="whitespace-nowrap border-b bg-muted/40 px-3 py-2 text-right font-medium"
                                    :class="m.future ? 'opacity-50' : ''"
                                >
                                    {{ m.label }}<span v-if="multiYear" class="block text-xs font-normal">{{ m.year }}</span>
                                </th>
                                <th class="whitespace-nowrap border-b border-l bg-muted/40 px-3 py-2 text-right font-medium">Total</th>
                                <th class="whitespace-nowrap border-b bg-muted/40 px-3 py-2 text-right font-medium" :title="`Over ${counted} months`">
                                    Average
                                </th>
                            </tr>
                        </thead>
                        <tbody v-for="s in sections" :key="s.kind">
                            <tr>
                                <th
                                    :colspan="months.length + 3"
                                    class="border-b bg-muted px-3 pb-1.5 pt-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                >
                                    <span class="sticky left-3">{{ s.title }}</span>
                                </th>
                            </tr>
                            <tr v-if="!s.groups.length">
                                <td :colspan="months.length + 3" class="border-b px-3 py-3 text-muted-foreground">Nothing in these months.</td>
                            </tr>
                            <template v-for="g in s.groups" :key="g.filter">
                                <tr class="hover:bg-muted/30">
                                    <th class="sticky left-0 z-10 border-b bg-card px-3 py-2 text-left font-medium">
                                        <button
                                            v-if="g.children.length"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-left"
                                            :aria-expanded="!!open[key(s, g)]"
                                            @click="open[key(s, g)] = !open[key(s, g)]"
                                        >
                                            <component :is="open[key(s, g)] ? ChevronDown : ChevronRight" class="size-4 shrink-0" />{{ g.label }}
                                        </button>
                                        <span v-else class="pl-5">{{ g.label }}</span>
                                    </th>
                                    <td
                                        v-for="(v, i) in g.values"
                                        :key="i"
                                        class="whitespace-nowrap border-b px-3 py-2 text-right"
                                        :class="
                                            s.kind === 'expense' && g.high.includes(months[i].key)
                                                ? 'bg-amber-100 text-amber-950 dark:bg-amber-900/40 dark:text-amber-100'
                                                : ''
                                        "
                                    >
                                        <Link v-if="v" :href="txLink(g.filter, months[i])" class="hover:underline">{{ cell(v) }}</Link>
                                        <span v-else class="text-muted-foreground">–</span>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-l px-3 py-2 text-right font-medium">
                                        <Link :href="txLink(g.filter)" class="hover:underline">{{ cell(g.total) }}</Link>
                                    </td>
                                    <td class="whitespace-nowrap border-b px-3 py-2 text-right text-muted-foreground">{{ cell(g.average) }}</td>
                                </tr>
                                <template v-if="open[key(s, g)]">
                                    <tr v-for="c in g.children" :key="c.filter" class="text-muted-foreground hover:bg-muted/30">
                                        <th class="sticky left-0 z-10 border-b bg-card py-1.5 pl-9 pr-3 text-left font-normal">{{ c.label }}</th>
                                        <td
                                            v-for="(v, i) in c.values"
                                            :key="i"
                                            class="whitespace-nowrap border-b px-3 py-1.5 text-right"
                                            :class="
                                                s.kind === 'expense' && c.high.includes(months[i].key)
                                                    ? 'bg-amber-100 text-amber-950 dark:bg-amber-900/40 dark:text-amber-100'
                                                    : ''
                                            "
                                        >
                                            <Link v-if="v" :href="txLink(c.filter, months[i])" class="hover:underline">{{ cell(v) }}</Link>
                                            <span v-else>–</span>
                                        </td>
                                        <td class="whitespace-nowrap border-b border-l px-3 py-1.5 text-right">
                                            <Link :href="txLink(c.filter)" class="hover:underline">{{ cell(c.total) }}</Link>
                                        </td>
                                        <td class="whitespace-nowrap border-b px-3 py-1.5 text-right">{{ cell(c.average) }}</td>
                                    </tr>
                                </template>
                            </template>
                            <tr class="font-semibold">
                                <th class="sticky left-0 z-10 border-b-2 bg-card px-3 py-2 text-left">{{ s.totals.label }}</th>
                                <td v-for="(v, i) in s.totals.values" :key="i" class="whitespace-nowrap border-b-2 px-3 py-2 text-right">
                                    {{ cell(v) }}
                                </td>
                                <td class="whitespace-nowrap border-b-2 border-l px-3 py-2 text-right">{{ cell(s.totals.total) }}</td>
                                <td class="whitespace-nowrap border-b-2 px-3 py-2 text-right">{{ cell(s.totals.average) }}</td>
                            </tr>
                        </tbody>
                        <tbody>
                            <tr class="font-semibold">
                                <th class="sticky left-0 z-10 bg-card px-3 py-2.5 text-left">Left over (income − spending)</th>
                                <td
                                    v-for="(v, i) in net.values"
                                    :key="i"
                                    class="whitespace-nowrap px-3 py-2.5 text-right"
                                    :class="v < 0 ? 'text-red-600 dark:text-red-400' : ''"
                                >
                                    {{ cell(v) }}
                                </td>
                                <td
                                    class="whitespace-nowrap border-l px-3 py-2.5 text-right"
                                    :class="net.total < 0 ? 'text-red-600 dark:text-red-400' : ''"
                                >
                                    {{ cell(net.total) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right" :class="net.average < 0 ? 'text-red-600 dark:text-red-400' : ''">
                                    {{ cell(net.average) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
            <p class="text-xs text-muted-foreground">
                Transfers between your accounts are left out. Average is over {{ counted }} {{ counted === 1 ? 'month' : 'months' }}; months that
                haven't happened yet don't count.
            </p>
        </div>
    </AppLayout>
</template>
