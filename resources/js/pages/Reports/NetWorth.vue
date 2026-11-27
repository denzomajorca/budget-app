<script setup lang="ts">
import ReportsTabs from '@/components/ReportsTabs.vue';
import SpanPicker from '@/components/SpanPicker.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { axisDollars, dollars, niceScale, signedDollars } from '@/lib/format';
import { shortDate } from '@/lib/money';
import { Head, router } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';

interface Point {
    month: string;
    label: string;
    date: string;
    cash: number;
    other: number;
    debts: number;
    investments: number;
    net: number;
}
const props = defineProps<{
    months: number;
    spans: number[];
    points: Point[];
    now: Point;
    change: number;
    from: string;
    accounts: { name: string; type: string; balance: number; is_debt: boolean }[];
    emergency: { cash: number; monthly_spending: number; months: number | null };
}>();

const pick = (m: number | string) => router.get(route('reports.networth'), { months: m }, { preserveScroll: true, preserveState: true });

// Stacked areas: investments, then cash and other savings; debts below zero; net as a line.
const narrow = useMediaQuery('(max-width: 640px)');
const W = computed(() => (narrow.value ? 360 : 720));
const H = computed(() => (narrow.value ? 230 : 250));
const pad = computed(() => ({ l: narrow.value ? 46 : 56, r: 8, t: 10, b: 22 }));
const scale = computed(() =>
    niceScale(
        Math.min(...props.points.map((p) => p.debts), 0),
        Math.max(...props.points.map((p) => p.investments + p.cash + Math.max(0, p.other)), 1),
    ),
);
const x = (i: number) => pad.value.l + (i * (W.value - pad.value.l - pad.value.r)) / Math.max(1, props.points.length - 1);
const y = (v: number) => pad.value.t + ((scale.value.max - v) / (scale.value.max - scale.value.min || 1)) * (H.value - pad.value.t - pad.value.b);
const area = (top: (p: Point) => number, base: (p: Point) => number) => {
    const up = props.points.map((p, i) => `${x(i)},${y(top(p))}`);
    const down = props.points.map((p, i) => `${x(i)},${y(base(p))}`).reverse();
    return [...up, ...down].join(' ');
};
const every = computed(() => Math.ceil(props.points.length / (narrow.value ? 5 : 8)));
const tick = (p: Point) => (props.points.length > 12 ? p.label.replace(/ \d\d(\d\d)$/, " '$1") : p.label.split(' ')[0]);
const assets = computed(() => props.accounts.filter((a) => !a.is_debt && a.balance !== 0));
const debts = computed(() => props.accounts.filter((a) => a.is_debt && a.balance !== 0));
const efTone = computed(() => {
    const m = props.emergency.months;
    if (m === null) return '';
    return m >= 6 ? 'text-emerald-700 dark:text-emerald-400' : m >= 3 ? 'text-amber-600' : 'text-red-600';
});
</script>

<template>
    <Head title="Net worth" />
    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Reports</h1>
            <ReportsTabs current="networth" />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">What you own minus what you owe at the end of each month, investments included.</p>
                <SpanPicker label="How many months" :value="months" :options="spans.map((s) => ({ value: s, label: `${s} months` }))" @pick="pick" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Net worth today</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ dollars(now.net) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        {{ dollars(now.investments) }} invested, {{ dollars(now.cash) }} in the bank, {{ dollars(-now.debts) }} owed
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Change since {{ shortDate(from) }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums" :class="change >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">{{
                            signedDollars(change)
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">over {{ months }} months</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Emergency fund</CardDescription>
                        <CardTitle class="text-2xl tabular-nums" :class="efTone">
                            {{ emergency.months === null ? '—' : `${emergency.months.toFixed(1)} months` }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        {{ dollars(emergency.cash) }} in checking and savings covers this many months of your usual spending ({{
                            dollars(emergency.monthly_spending)
                        }}
                        a month). A common goal is 3 to 6 months.
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Over time</CardTitle>
                </CardHeader>
                <CardContent>
                    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Net worth by month">
                        <g v-for="t in scale.ticks" :key="t">
                            <line :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" class="stroke-border" :stroke-width="t === 0 ? 1.5 : 1" />
                            <text :x="pad.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-muted-foreground text-[11px]">{{ axisDollars(t) }}</text>
                        </g>
                        <polygon
                            :points="
                                area(
                                    (p) => p.investments,
                                    () => 0,
                                )
                            "
                            class="fill-sky-600/70"
                        />
                        <polygon
                            :points="
                                area(
                                    (p) => p.investments + p.cash + Math.max(0, p.other),
                                    (p) => p.investments,
                                )
                            "
                            class="fill-emerald-500/70"
                        />
                        <polygon
                            :points="
                                area(
                                    () => 0,
                                    (p) => p.debts,
                                )
                            "
                            class="fill-red-500/60"
                        />
                        <polyline
                            :points="points.map((p, i) => `${x(i)},${y(p.net)}`).join(' ')"
                            fill="none"
                            class="stroke-foreground"
                            stroke-width="2"
                        />
                        <circle :cx="x(points.length - 1)" :cy="y(now.net)" r="3.5" class="fill-foreground" />
                        <template v-for="(p, i) in points" :key="p.month">
                            <text v-if="i % every === 0" :x="x(i)" :y="H - 6" text-anchor="middle" class="fill-muted-foreground text-[11px]">
                                {{ tick(p) }}
                            </text>
                        </template>
                    </svg>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-sky-600/70" />Investments</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-500/70" />Bank accounts</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-500/60" />Owed (cards and loans)</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 bg-foreground" />Net worth</span>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Bank balances come from your transactions. Investments use your saved statement balances plus money added since.
                    </p>
                </CardContent>
            </Card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 [&>*]:min-w-0">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">What you own</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="grid grid-cols-1 divide-y text-sm">
                            <li v-for="a in assets" :key="a.name" class="flex justify-between gap-3 py-2">
                                <span class="min-w-0 truncate"
                                    >{{ a.name }} <span class="text-muted-foreground">· {{ a.type }}</span></span
                                >
                                <span class="tabular-nums">{{ dollars(a.balance) }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">What you owe</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!debts.length" class="text-sm text-muted-foreground">Nothing owed on your accounts right now.</p>
                        <ul v-else class="grid grid-cols-1 divide-y text-sm">
                            <li v-for="a in debts" :key="a.name" class="flex justify-between gap-3 py-2">
                                <span class="min-w-0 truncate"
                                    >{{ a.name }} <span class="text-muted-foreground">· {{ a.type }}</span></span
                                >
                                <span class="tabular-nums">{{ dollars(-a.balance) }}</span>
                            </li>
                        </ul>
                        <p class="mt-2 text-xs text-muted-foreground">
                            A 401(k) loan isn’t counted here: you owe it to your own account, and the 401(k) balance already leaves it out.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
