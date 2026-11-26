<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { centsToInput, money } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Wand2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row {
    category_id: number;
    name: string;
    is_main: boolean;
    budget: number | null;
    spent: number;
    average: number;
}
interface Group {
    id: number;
    name: string;
    has_children: boolean;
    budget: number;
    spent: number;
    average: number;
    rows: Row[];
}

const props = defineProps<{
    month: string;
    monthStart: string;
    monthEnd: string;
    isCurrent: boolean;
    daysLeft: number | null;
    groups: Group[];
    totals: { budget: number; spent: number; unbudgeted: number; uncategorized: number };
}>();

const showAll = ref(false);
const label = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});
function shift(n: number) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + n, 1);
    router.get(route('budgets.index'), { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }, { preserveScroll: true });
}

const used = (r: Row) => r.budget !== null || r.spent !== 0 || r.average > 0;
const visible = computed(() =>
    props.groups.map((g) => ({ ...g, rows: showAll.value ? g.rows : g.rows.filter(used) })).filter((g) => showAll.value || g.rows.length),
);
const hasEmptyWithAverage = computed(() => props.groups.some((g) => g.rows.some((r) => r.budget === null && r.average > 0)));

const saving = ref<number | null>(null);
function save(r: Row, e: Event) {
    const value = (e.target as HTMLInputElement).value.trim();
    const current = r.budget === null ? '' : centsToInput(r.budget);
    if (value === current) return;
    saving.value = r.category_id;
    router.put(
        route('budgets.update'),
        { category_id: r.category_id, amount: value },
        { preserveScroll: true, preserveState: true, onFinish: () => (saving.value = null) },
    );
}
function fill() {
    router.post(route('budgets.fill'), { month: props.month }, { preserveScroll: true });
}

const pct = (spent: number, budget: number) => (budget > 0 ? Math.min(100, Math.round((spent / budget) * 100)) : spent > 0 ? 100 : 0);
const barColor = (spent: number, budget: number) =>
    spent > budget ? 'bg-red-500' : budget > 0 && spent / budget > 0.85 ? 'bg-amber-500' : 'bg-emerald-600';
const left = (r: { budget: number | null; spent: number }) => (r.budget ?? 0) - r.spent;
const txLink = (categoryId: number) => route('transactions.index', { category: categoryId, from: props.monthStart, to: props.monthEnd });
</script>

<template>
    <Head title="Budgets" />
    <AppLayout :breadcrumbs="[{ title: 'Budgets', href: '/budgets' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Budgets</h1>
                    <p class="text-sm text-muted-foreground">A monthly amount for each subcategory. Main categories add up their subcategories.</p>
                </div>
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="icon" aria-label="Previous month" @click="shift(-1)"><ChevronLeft class="size-4" /></Button>
                    <span class="min-w-36 text-center font-medium">{{ label }}</span>
                    <Button variant="outline" size="icon" aria-label="Next month" @click="shift(1)"><ChevronRight class="size-4" /></Button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription>Budgeted</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{ money(totals.budget) }}</CardTitle></CardHeader
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription>Spent</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{ money(totals.spent) }}</CardTitle></CardHeader
                    >
                    <CardContent v-if="totals.unbudgeted" class="text-sm text-muted-foreground"
                        >{{ money(totals.unbudgeted) }} of it has no budget</CardContent
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>{{ totals.budget - totals.spent >= 0 ? 'Left' : 'Over' }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums" :class="totals.budget - totals.spent < 0 ? 'text-red-600 dark:text-red-400' : ''">
                            {{ money(Math.abs(totals.budget - totals.spent)) }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent v-if="daysLeft" class="text-sm text-muted-foreground"
                        >{{ daysLeft }} {{ daysLeft === 1 ? 'day' : 'days' }} left this month</CardContent
                    >
                </Card>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button v-if="hasEmptyWithAverage" variant="outline" @click="fill"
                    ><Wand2 class="size-4" />Fill empty budgets from my averages</Button
                >
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="showAll" type="checkbox" class="size-4" />
                    Show every category
                </label>
                <span v-if="totals.uncategorized" class="text-sm text-muted-foreground">
                    <Link :href="route('transactions.index', { category: 'none', from: monthStart, to: monthEnd })" class="underline"
                        >{{ money(totals.uncategorized) }} uncategorized</Link
                    >
                    isn't counted here.
                </span>
            </div>

            <Card v-if="visible.length === 0">
                <CardContent class="p-6 text-sm text-muted-foreground"
                    >No spending or budgets for {{ label }} yet. Tick "Show every category" to set budgets.</CardContent
                >
            </Card>

            <Card v-for="g in visible" :key="g.id" class="overflow-hidden">
                <div class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 bg-muted/40 px-4 py-3 sm:grid-cols-[1fr_7rem_7rem_7rem]">
                    <div class="font-semibold">{{ g.name }}</div>
                    <div class="hidden text-right text-sm tabular-nums text-muted-foreground sm:block">{{ g.budget ? money(g.budget) : '' }}</div>
                    <div class="text-right text-sm font-medium tabular-nums">
                        <Link :href="txLink(g.id)" class="hover:underline">{{ money(g.spent) }}</Link>
                        <span v-if="g.budget" class="text-muted-foreground sm:hidden"> of {{ money(g.budget) }}</span>
                    </div>
                    <div
                        class="hidden text-right text-sm tabular-nums sm:block"
                        :class="g.budget && left(g) < 0 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'"
                    >
                        {{ g.budget ? (left(g) < 0 ? money(-left(g)) + ' over' : money(left(g)) + ' left') : '' }}
                    </div>
                    <div v-if="g.budget" class="col-span-full h-1.5 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full" :class="barColor(g.spent, g.budget)" :style="{ width: pct(g.spent, g.budget) + '%' }" />
                    </div>
                </div>
                <div class="hidden grid-cols-[1fr_7rem_7rem_7rem] gap-x-4 px-4 pt-2 text-xs text-muted-foreground sm:grid">
                    <span>{{ g.has_children ? 'Subcategory' : '' }}</span
                    ><span class="text-right">Budget</span><span class="text-right">Spent</span><span class="text-right">Left</span>
                </div>
                <ul class="divide-y">
                    <li
                        v-for="r in g.rows"
                        :key="r.category_id"
                        class="grid grid-cols-[1fr_6.5rem] items-center gap-x-4 gap-y-1 px-4 py-2 sm:grid-cols-[1fr_7rem_7rem_7rem]"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm" :class="r.is_main && g.has_children ? 'italic' : ''">{{ r.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                <template v-if="r.average">avg {{ money(r.average) }}/mo</template>
                                <span class="sm:hidden">
                                    <template v-if="r.average"> · </template>
                                    spent <Link :href="txLink(r.category_id)" class="underline">{{ money(r.spent) }}</Link>
                                    <template v-if="r.budget !== null">
                                        ·
                                        <span :class="left(r) < 0 ? 'text-red-600 dark:text-red-400' : ''">{{
                                            left(r) < 0 ? money(-left(r)) + ' over' : money(left(r)) + ' left'
                                        }}</span>
                                    </template>
                                </span>
                            </div>
                        </div>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                            <input
                                :value="r.budget === null ? '' : centsToInput(r.budget)"
                                inputmode="decimal"
                                placeholder="—"
                                :aria-label="`Monthly budget for ${g.name} ${r.name}`"
                                class="h-9 w-full rounded-md border border-input bg-background pl-6 pr-2 text-right text-sm tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :class="saving === r.category_id ? 'opacity-50' : ''"
                                @change="save(r, $event)"
                                @keydown.enter="($event.target as HTMLInputElement).blur()"
                            />
                        </div>
                        <div class="hidden text-right text-sm tabular-nums sm:block">
                            <Link :href="txLink(r.category_id)" class="hover:underline">{{ money(r.spent) }}</Link>
                        </div>
                        <div
                            class="hidden text-right text-sm tabular-nums sm:block"
                            :class="r.budget !== null && left(r) < 0 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'"
                        >
                            {{ r.budget === null ? '' : left(r) < 0 ? money(-left(r)) + ' over' : money(left(r)) }}
                        </div>
                        <div v-if="r.budget" class="col-span-full h-1 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full" :class="barColor(r.spent, r.budget)" :style="{ width: pct(r.spent, r.budget) + '%' }" />
                        </div>
                    </li>
                </ul>
            </Card>

            <p class="text-sm text-muted-foreground">
                Type an amount and press Enter to save it; clear it to remove the budget. Each budget repeats every month. Averages are from the three
                months before
                {{ label }}. Refunds count against spending. Income and transfers are left out.
            </p>
        </div>
    </AppLayout>
</template>
