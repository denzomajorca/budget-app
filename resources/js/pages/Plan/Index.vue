<script setup lang="ts">
import PlanItemDialog, { type CategoryOption, type Group, type PlanItem } from '@/components/PlanItemDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, shortDate } from '@/lib/money';
import { Head } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Month {
    month: string;
    bills: number;
    flex: number;
    goals: number;
}
const props = defineProps<{
    bills: PlanItem[];
    flex: PlanItem[];
    goals: PlanItem[];
    months: Month[];
    upcoming: { date: string; name: string; amount: number; is_estimate: boolean }[];
    income: number;
    averagesThrough: string | null;
    categories: CategoryOption[];
    frequencies: Record<string, string>;
}>();

const dialog = ref(false);
const dGroup = ref<Group>('bill');
const dItem = ref<PlanItem | null>(null);
function add(g: Group) {
    dGroup.value = g;
    dItem.value = null;
    dialog.value = true;
}
function edit(i: PlanItem) {
    dGroup.value = i.group;
    dItem.value = i;
    dialog.value = true;
}

const sum = (list: PlanItem[]) => list.reduce((s, i) => s + i.per_month, 0);
const perMonth = computed(() => sum(props.bills) + sum(props.flex) + sum(props.goals));
const totalOf = (m: Month) => m.bills + m.flex + m.goals;
const biggest = computed(() => [...props.months].sort((a, b) => totalOf(b) - totalOf(a))[0]);
const due60 = computed(() => props.upcoming.reduce((s, u) => s + u.amount, 0));
const monthName = (m: string, long = false, letter = false) => {
    const [y, mo] = m.split('-').map(Number);
    return new Date(y, mo - 1, 1).toLocaleDateString('en-US', long ? { month: 'long', year: 'numeric' } : { month: letter ? 'narrow' : 'short' });
};
const empty = computed(() => !props.bills.length && !props.flex.length && !props.goals.length);

// Chart
const narrow = useMediaQuery('(max-width: 640px)');
const W = computed(() => (narrow.value ? 360 : 640));
const H = computed(() => (narrow.value ? 260 : 230));
const pad = computed(() => ({ l: narrow.value ? 40 : 48, r: 6, t: 14, b: 24 }));
const hi = computed(() => {
    const max = Math.max(props.income, ...props.months.map(totalOf), 1);
    const step = max > 8000_00 ? 2000_00 : max > 2000_00 ? 1000_00 : 200_00;
    return Math.ceil(max / step) * step;
});
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(hi.value * f)));
const y = (v: number) => pad.value.t + ((hi.value - v) / hi.value) * (H.value - pad.value.t - pad.value.b);
const bw = computed(() => (W.value - pad.value.l - pad.value.r) / 12);
const k = (v: number) => (v >= 100000 ? `$${Math.round(v / 100000)}k` : `$${Math.round(v / 100)}`);
const segs = [
    { key: 'bills' as const, cls: 'fill-sky-600', label: 'Bills' },
    { key: 'flex' as const, cls: 'fill-amber-500', label: 'Everyday spending' },
    { key: 'goals' as const, cls: 'fill-violet-500', label: 'Setting aside for planned expenses' },
];
function stack(m: Month) {
    let base = 0;
    return segs.map((s) => {
        const v = m[s.key];
        const r = { ...s, y: y(base + v), h: y(base) - y(base + v), v };
        base += v;
        return r;
    });
}
</script>

<template>
    <Head title="Expense plan" />
    <AppLayout :breadcrumbs="[{ title: 'Expense plan', href: '/plan' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Expense plan</h1>
                <p class="text-sm text-muted-foreground">
                    Bills on a schedule, everyday spending, and bigger expenses you save toward, projected over the next 12 months.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Needed per month</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ money(perMonth) }}</CardTitle>
                    </CardHeader>
                    <CardContent v-if="income" class="text-sm text-muted-foreground">
                        Your income averages {{ money(income) }} a month,
                        <span :class="income - perMonth < 0 ? 'text-red-600 dark:text-red-400' : ''"
                            >{{ money(Math.abs(income - perMonth)) }} {{ income - perMonth < 0 ? 'short' : 'left over' }}</span
                        >.
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Biggest month ahead</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ biggest && totalOf(biggest) ? money(totalOf(biggest)) : '—' }}</CardTitle>
                    </CardHeader>
                    <CardContent v-if="biggest && totalOf(biggest)" class="text-sm text-muted-foreground">{{
                        monthName(biggest.month, true)
                    }}</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Bills due in the next 60 days</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ money(due60) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground"
                        >{{ upcoming.length }} {{ upcoming.length === 1 ? 'bill' : 'bills' }}</CardContent
                    >
                </Card>
            </div>

            <Card v-if="!empty">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Next 12 months</CardTitle>
                    <CardDescription
                        >Each bar adds up the bills due that month, everyday spending, and what to set aside for planned expenses.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <svg
                        :viewBox="`0 0 ${W} ${H}`"
                        width="100%"
                        role="img"
                        aria-label="Projected expenses for the next 12 months"
                        :class="narrow ? 'text-[13px]' : 'text-[11px]'"
                    >
                        <g v-for="t in ticks" :key="t">
                            <line :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" class="stroke-border" />
                            <text :x="pad.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-muted-foreground">{{ k(t) }}</text>
                        </g>
                        <g v-for="(m, i) in months" :key="m.month">
                            <rect
                                v-for="s in stack(m)"
                                :key="s.key"
                                :x="pad.l + i * bw + bw * 0.18"
                                :width="bw * 0.64"
                                :y="s.y"
                                :height="Math.max(0, s.h)"
                                :class="s.cls"
                            >
                                <title>{{ monthName(m.month, true) }}, {{ s.label }}: {{ money(s.v) }}</title>
                            </rect>
                            <text :x="pad.l + i * bw + bw / 2" :y="H - 6" text-anchor="middle" class="fill-muted-foreground">
                                {{ monthName(m.month) }}
                            </text>
                        </g>
                        <template v-if="income">
                            <line
                                :x1="pad.l"
                                :x2="W - pad.r"
                                :y1="y(income)"
                                :y2="y(income)"
                                class="stroke-emerald-600"
                                stroke-width="2"
                                stroke-dasharray="6 4"
                            />
                            <text :x="pad.l + 6" :y="y(income) - 6" class="fill-emerald-700 dark:fill-emerald-400">Income {{ money(income) }}</text>
                        </template>
                    </svg>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span v-for="s in segs" :key="s.key" class="inline-flex items-center gap-1.5"
                            ><svg width="10" height="10"><rect width="10" height="10" rx="2" :class="s.cls" /></svg>{{ s.label }}</span
                        >
                    </div>
                </CardContent>
            </Card>

            <!-- 1. Bills -->
            <Card>
                <CardHeader class="flex-row items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle class="text-base">1. Bills</CardTitle>
                        <CardDescription>Due on a schedule: mortgage, utilities, insurance, taxes.</CardDescription>
                    </div>
                    <Button variant="outline" size="sm" @click="add('bill')"><Plus class="size-4" />Add</Button>
                </CardHeader>
                <CardContent class="p-0">
                    <p v-if="!bills.length" class="px-6 pb-6 text-sm text-muted-foreground">No bills yet.</p>
                    <ul v-else class="divide-y border-t">
                        <li v-for="b in bills" :key="b.id">
                            <button type="button" class="flex w-full min-w-0 items-center gap-3 px-6 py-3 text-left hover:bg-muted/40" @click="edit(b)">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">
                                        {{ b.name }}
                                        <span
                                            v-if="b.is_estimate"
                                            class="ml-1 rounded bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground"
                                            >estimate</span
                                        >
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ b.frequency }}<template v-if="b.next_due"> · next {{ shortDate(b.next_due) }}</template
                                        ><template v-if="b.category"> · {{ b.category }}</template>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-medium tabular-nums">{{ money(b.amount) }}</div>
                                    <div v-if="b.every_months !== 1" class="text-xs tabular-nums text-muted-foreground">
                                        {{ money(b.per_month) }}/mo
                                    </div>
                                </div>
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- 2. Everyday -->
            <Card>
                <CardHeader class="flex-row items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle class="text-base">2. Everyday spending</CardTitle>
                        <CardDescription>
                            Tracked but no fixed amount: food, charity, entertainment. "Lately" is your average over the 90 days<template
                                v-if="averagesThrough"
                            >
                                to {{ shortDate(averagesThrough) }}</template
                            >.
                        </CardDescription>
                    </div>
                    <Button variant="outline" size="sm" @click="add('flex')"><Plus class="size-4" />Add</Button>
                </CardHeader>
                <CardContent class="p-0">
                    <p v-if="!flex.length" class="px-6 pb-6 text-sm text-muted-foreground">Nothing here yet.</p>
                    <ul v-else class="divide-y border-t">
                        <li v-for="f in flex" :key="f.id">
                            <button type="button" class="flex w-full min-w-0 items-center gap-3 px-6 py-3 text-left hover:bg-muted/40" @click="edit(f)">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ f.name }}</div>
                                    <div v-if="f.category" class="truncate text-sm text-muted-foreground">{{ f.category }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-medium tabular-nums">{{ money(f.amount) }}/mo</div>
                                    <div
                                        v-if="f.average !== null"
                                        class="text-xs tabular-nums"
                                        :class="f.average > f.amount * 1.05 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'"
                                    >
                                        lately {{ money(f.average) }}
                                    </div>
                                </div>
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- 3. Planned -->
            <Card>
                <CardHeader class="flex-row items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle class="text-base">3. Planned expenses</CardTitle>
                        <CardDescription>Bigger one-time costs you save toward: vacations, home improvement.</CardDescription>
                    </div>
                    <Button variant="outline" size="sm" @click="add('goal')"><Plus class="size-4" />Add</Button>
                </CardHeader>
                <CardContent class="p-0">
                    <p v-if="!goals.length" class="px-6 pb-6 text-sm text-muted-foreground">Nothing planned yet.</p>
                    <ul v-else class="divide-y border-t">
                        <li v-for="g in goals" :key="g.id">
                            <button type="button" class="grid w-full gap-2 px-6 py-3 text-left hover:bg-muted/40" @click="edit(g)">
                                <div class="flex items-center gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">{{ g.name }}</div>
                                        <div class="text-sm text-muted-foreground">
                                            {{ money(g.saved) }} of {{ money(g.amount) }} saved<template v-if="g.due_by">
                                                · needed by {{ shortDate(g.due_by) }}</template
                                            >
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-medium tabular-nums">{{ money(g.per_month) }}/mo</div>
                                        <div class="text-xs text-muted-foreground">to set aside</div>
                                    </div>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-violet-500"
                                        :style="{ width: Math.min(100, g.amount ? (g.saved / g.amount) * 100 : 0) + '%' }"
                                    />
                                </div>
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card v-if="upcoming.length">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Coming up</CardTitle>
                    <CardDescription>Bills due in the next 60 days</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y border-t">
                        <li v-for="(u, i) in upcoming" :key="i" class="flex items-center justify-between gap-3 px-6 py-2.5 text-sm">
                            <div>
                                <div class="font-medium">{{ u.name }}</div>
                                <div class="text-xs text-muted-foreground">{{ shortDate(u.date) }}</div>
                            </div>
                            <span class="tabular-nums">{{ u.is_estimate ? 'about ' : '' }}{{ money(u.amount) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <PlanItemDialog v-model:open="dialog" :group="dGroup" :item="dItem" :categories="categories" :frequencies="frequencies" />
    </AppLayout>
</template>
