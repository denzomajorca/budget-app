<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { centsToInput, money } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, GripVertical, ListChecks, Wand2 } from 'lucide-vue-next';
import Sortable from 'sortablejs';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

interface Row {
    category_id: number;
    name: string;
    is_main: boolean;
    budget: number | null;
    spent: number;
    average: number;
}
interface Line extends Row {
    parent: string | null;
    hidden: boolean | null;
    position: number | null;
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
    lines: Line[];
    totals: { budget: number; spent: number; unbudgeted: number; uncategorized: number };
}>();

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
/** Shown unless you hid it; lines you never chose show when they have a budget or spending. */
const isShown = (l: Line) => (l.hidden === null ? used(l) : !l.hidden);

// Sorting: your own order, or by a column. Remembered on this device.
type SortKey = 'mine' | 'name' | 'budget' | 'spent' | 'left';
const readSort = (): [SortKey, 'asc' | 'desc'] => {
    try {
        const v = JSON.parse(localStorage.getItem('budgets.sort') ?? 'null');
        if (Array.isArray(v)) return v as [SortKey, 'asc' | 'desc'];
    } catch {
        /* private window */
    }
    return ['mine', 'asc'];
};
const [k0, d0] = readSort();
const sortKey = ref<SortKey>(k0);
const sortDir = ref<'asc' | 'desc'>(d0);
watch([sortKey, sortDir], () => {
    try {
        localStorage.setItem('budgets.sort', JSON.stringify([sortKey.value, sortDir.value]));
    } catch {
        /* private window */
    }
});
function sortBy(k: SortKey) {
    if (k === 'mine') {
        sortKey.value = 'mine';
        sortDir.value = 'asc';
        return;
    }
    // Names start A–Z; amounts start with the biggest. Clicking again flips it.
    const first = k === 'name' || k === 'left' ? 'asc' : 'desc';
    sortDir.value = sortKey.value === k ? (sortDir.value === 'asc' ? 'desc' : 'asc') : first;
    sortKey.value = k;
}
const lineLabel = (l: Line) => (l.parent ? `${l.name} (${l.parent})` : l.name);
const sortValue = (l: Line, k: SortKey): string | number =>
    k === 'name' ? lineLabel(l).toLowerCase() : k === 'budget' ? (l.budget ?? -1) : k === 'spent' ? l.spent : l.budget === null ? -Infinity : left(l);

// The order you set (kept locally while you edit, saved to the server).
const order = ref<number[]>(props.lines.map((l) => l.category_id));
watch(
    () => props.lines,
    (ls) => {
        const ids = ls.map((l) => l.category_id);
        // Keep your current order; add any new lines at the end.
        order.value = [...order.value.filter((id) => ids.includes(id)), ...ids.filter((id) => !order.value.includes(id))];
    },
);
const byId = computed(() => new Map(props.lines.map((l) => [l.category_id, l])));
const ordered = computed(() => {
    const list = order.value.map((id) => byId.value.get(id)).filter((l): l is Line => !!l);
    if (sortKey.value === 'mine') return list;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => {
        const x = sortValue(a, sortKey.value);
        const y = sortValue(b, sortKey.value);
        return x < y ? -dir : x > y ? dir : 0;
    });
});
const editing = ref(false);
// While arranging: shown lines first (in your order), then the hidden ones.
const shownLines = computed(() =>
    editing.value ? [...ordered.value.filter(isShown), ...ordered.value.filter((l) => !isShown(l))] : ordered.value.filter(isShown),
);
const firstHidden = computed(() => (editing.value ? shownLines.value.findIndex((l) => !isShown(l)) : -1));
const shownTotals = computed(() => {
    const ls = ordered.value.filter(isShown);
    return { budget: ls.reduce((s, l) => s + (l.budget ?? 0), 0), spent: ls.reduce((s, l) => s + l.spent, 0), count: ls.length };
});

function saveLayout(payload: { order?: number[]; hidden?: Record<number, boolean> }) {
    router.post(route('budgets.layout'), payload, { preserveScroll: true, preserveState: true });
}
function toggleShown(l: Line) {
    const hide = isShown(l);
    // The line goes to the boundary: last of the shown lines, or first of the hidden ones.
    const others = shownLines.value.filter((x) => x.category_id !== l.category_id);
    const ids = [...others.filter(isShown), l, ...others.filter((x) => !isShown(x))].map((x) => x.category_id);
    order.value = ids;
    saveLayout({ hidden: { [l.category_id]: hide }, order: ids });
}
/** Move a shown line among the shown lines. */
function move(l: Line, by: number) {
    const ids = shownLines.value.map((x) => x.category_id);
    const i = ids.indexOf(l.category_id);
    const j = i + by;
    const lastShown = firstHidden.value === -1 ? ids.length - 1 : firstHidden.value - 1;
    if (j < 0 || j > lastShown) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    order.value = ids;
    saveLayout({ order: ids });
}
function startEditing() {
    sortKey.value = 'mine';
    sortDir.value = 'asc';
    editing.value = true;
}

// Drag to reorder while customizing (works with touch too).
const listEl = ref<HTMLElement | null>(null);
let sortable: Sortable | null = null;
watch([editing, listEl], async () => {
    sortable?.destroy();
    sortable = null;
    await nextTick();
    if (!editing.value || !listEl.value) return;
    sortable = Sortable.create(listEl.value, {
        handle: '[data-drag]',
        animation: 150,
        delay: 0,
        ghostClass: 'opacity-40',
        onEnd: (e) => {
            if (e.oldIndex === undefined || e.newIndex === undefined || e.oldIndex === e.newIndex) return;
            const ids = shownLines.value.map((x) => x.category_id);
            const [moved] = ids.splice(e.oldIndex, 1);
            ids.splice(e.newIndex, 0, moved);
            // Let Vue redraw the list from the new order rather than keep Sortable's DOM move.
            const el = e.item;
            el.parentNode?.removeChild(el);
            e.from.insertBefore(el, e.from.children[e.oldIndex] ?? null);
            order.value = ids;
            saveLayout({ order: ids });
        },
    });
});
onBeforeUnmount(() => sortable?.destroy());
const hasEmptyWithAverage = computed(() => props.lines.some((r) => r.budget === null && r.average > 0));

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
                    <p class="text-sm text-muted-foreground">
                        A monthly amount for each subcategory. Choose which lines to show and put them in your own order.
                    </p>
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
                <Button v-if="hasEmptyWithAverage && !editing" variant="outline" @click="fill"
                    ><Wand2 class="size-4" />Fill empty budgets from my averages</Button
                >
                <Button :variant="editing ? 'default' : 'outline'" @click="editing ? (editing = false) : startEditing()">
                    <ListChecks class="size-4" />{{ editing ? 'Done' : 'Choose and arrange lines' }}
                </Button>
                <NativeSelect
                    v-if="!editing"
                    class="h-9 w-auto sm:hidden"
                    aria-label="Sort by"
                    :model-value="sortKey + ':' + sortDir"
                    @change="
                        (e: Event) => {
                            const [k, d] = (e.target as HTMLSelectElement).value.split(':');
                            sortKey = k as SortKey;
                            sortDir = d as 'asc' | 'desc';
                        }
                    "
                >
                    <option value="mine:asc">My order</option>
                    <option value="name:asc">Name A–Z</option>
                    <option value="budget:desc">Budget, high to low</option>
                    <option value="spent:desc">Spent, high to low</option>
                    <option value="left:asc">Left, least first</option>
                </NativeSelect>
                <span v-if="totals.uncategorized && !editing" class="text-sm text-muted-foreground">
                    <Link :href="route('transactions.index', { category: 'none', from: monthStart, to: monthEnd })" class="underline"
                        >{{ money(totals.uncategorized) }} uncategorized</Link
                    >
                    isn't counted here.
                </span>
            </div>

            <p v-if="editing" class="rounded-md border bg-muted/40 p-3 text-sm">
                Tick the lines you want to see; hidden ones wait at the bottom. Drag
                <GripVertical class="inline size-4 align-text-bottom" /> to move a line, or use the arrows. Changes save as you go. Hidden lines still
                count in the totals at the top.
            </p>

            <Card v-if="shownLines.length === 0">
                <CardContent class="p-6 text-sm text-muted-foreground"
                    >No lines to show for {{ label }}. Press "Choose and arrange lines" to pick the ones you want.</CardContent
                >
            </Card>

            <Card v-else class="overflow-hidden">
                <div
                    class="hidden items-center gap-x-4 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground sm:grid"
                    :class="editing ? 'grid-cols-[4.5rem_1fr_7rem_7rem_7rem]' : 'grid-cols-[1fr_7rem_7rem_7rem]'"
                >
                    <span v-if="editing">Show</span>
                    <template
                        v-for="[k, l, right] in [
                            ['name', 'Category', false],
                            ['budget', 'Budget', true],
                            ['spent', 'Spent', true],
                            ['left', 'Left', true],
                        ] as [SortKey, string, boolean][]"
                        :key="k"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground disabled:pointer-events-none"
                            :class="[right ? 'justify-end' : '', sortKey === k ? 'text-foreground' : '']"
                            :disabled="editing"
                            @click="sortBy(k)"
                        >
                            {{ l }}
                            <template v-if="!editing">
                                <ArrowUp v-if="sortKey === k && sortDir === 'asc'" class="size-3.5" />
                                <ArrowDown v-else-if="sortKey === k" class="size-3.5" />
                                <ArrowUpDown v-else class="size-3.5 opacity-40" />
                            </template>
                        </button>
                    </template>
                </div>
                <div v-if="sortKey !== 'mine' && !editing" class="border-b px-4 py-1.5 text-xs text-muted-foreground">
                    Sorted by {{ sortKey }}.
                    <button type="button" class="underline" @click="sortBy('mine')">Back to my order</button>
                </div>
                <ul ref="listEl" class="divide-y" data-budget-lines>
                    <li
                        v-for="(r, i) in shownLines"
                        :key="r.category_id"
                        class="grid items-center gap-x-4 gap-y-1 px-4 py-2"
                        :class="[
                            editing
                                ? 'grid-cols-[4.5rem_1fr] sm:grid-cols-[4.5rem_1fr_7rem_7rem_7rem]'
                                : 'grid-cols-[1fr_6.5rem] sm:grid-cols-[1fr_7rem_7rem_7rem]',
                            editing && !isShown(r) ? 'opacity-50' : '',
                            i === firstHidden && i > 0 ? 'border-t-4 border-t-muted' : '',
                        ]"
                    >
                        <div v-if="editing" class="flex items-center gap-1">
                            <button type="button" data-drag class="cursor-grab touch-none p-1 text-muted-foreground" :aria-label="'Drag ' + r.name">
                                <GripVertical class="size-4" />
                            </button>
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="isShown(r)"
                                :aria-label="'Show ' + lineLabel(r)"
                                @change="toggleShown(r)"
                            />
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <div class="truncate text-sm">
                                    {{ r.name }}<span v-if="r.parent" class="text-muted-foreground"> · {{ r.parent }}</span>
                                </div>
                                <div v-if="editing" class="ml-auto flex shrink-0">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-7"
                                        :disabled="!isShown(r) || i === 0"
                                        :aria-label="'Move ' + r.name + ' up'"
                                        @click="move(r, -1)"
                                        ><ChevronUp class="size-4"
                                    /></Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-7"
                                        :disabled="!isShown(r) || i === shownLines.length - 1 || i + 1 === firstHidden"
                                        :aria-label="'Move ' + r.name + ' down'"
                                        @click="move(r, 1)"
                                        ><ChevronDown class="size-4"
                                    /></Button>
                                </div>
                            </div>
                            <div v-if="!editing" class="text-xs text-muted-foreground">
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
                        <div :class="editing ? 'hidden sm:block' : 'relative'" class="relative">
                            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                            <input
                                :value="r.budget === null ? '' : centsToInput(r.budget)"
                                inputmode="decimal"
                                placeholder="—"
                                :aria-label="`Monthly budget for ${lineLabel(r)}`"
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
                        <div v-if="r.budget && !editing" class="col-span-full h-1 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full" :class="barColor(r.spent, r.budget)" :style="{ width: pct(r.spent, r.budget) + '%' }" />
                        </div>
                    </li>
                </ul>
                <div
                    v-if="!editing && shownTotals.count < lines.length"
                    class="grid grid-cols-[1fr_auto] gap-x-4 border-t bg-muted/40 px-4 py-2 text-sm sm:grid-cols-[1fr_7rem_7rem_7rem]"
                >
                    <span class="text-muted-foreground">Lines shown</span>
                    <span class="text-right tabular-nums sm:hidden">{{ money(shownTotals.spent) }} of {{ money(shownTotals.budget) }}</span>
                    <span class="hidden text-right tabular-nums sm:block">{{ money(shownTotals.budget) }}</span>
                    <span class="hidden text-right tabular-nums sm:block">{{ money(shownTotals.spent) }}</span>
                    <span class="hidden sm:block" />
                </div>
            </Card>

            <p class="text-sm text-muted-foreground">
                Type an amount and press Enter to save it; clear it to remove the budget. Each budget repeats every month. Averages are from the three
                months before
                {{ label }}. Refunds count against spending. Income and transfers are left out.
            </p>
        </div>
    </AppLayout>
</template>
