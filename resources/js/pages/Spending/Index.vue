<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, shortDate } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

interface Row {
    category: string;
    name: string;
    a: number;
    b: number;
}
interface Group {
    id: number;
    name: string;
    a: number;
    b: number;
    rows: Row[];
}
interface Range {
    label: string;
    from: string;
    to: string;
    hasData?: boolean;
}

const props = defineProps<{
    type: 'month' | 'quarter' | 'year';
    period: string;
    cmp: 'prev' | 'ly';
    periods: { key: string; label: string }[];
    a: Range;
    b: Range;
    partial: string | null;
    groups: Group[];
    totals: { a: number; b: number };
}>();

const closed = reactive<Record<number, boolean>>({});
function go(params: Record<string, string>) {
    router.get(route('spending'), { type: props.type, key: props.period, cmp: props.cmp, ...params }, { preserveScroll: true, preserveState: true });
}
function setType(t: string) {
    router.get(route('spending'), { type: t, cmp: t === 'year' ? 'ly' : props.cmp }, { preserveScroll: true });
}
const tx = (category: string | number, r: Range) => route('transactions.index', { category: String(category), from: r.from, to: r.to });
const diff = computed(() => props.totals.a - props.totals.b);
function change(a: number, b: number): { text: string; pct: string; cls: string } | null {
    if (!props.b.hasData || !b) return null;
    const d = a - b;
    return {
        text: (d > 0 ? '+' : d < 0 ? '−' : '') + money(Math.abs(d)),
        pct: `${d > 0 ? '+' : ''}${Math.round((d / b) * 100)}%`,
        cls: d > 0 ? 'text-red-600 dark:text-red-400' : d < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground',
    };
}
</script>

<template>
    <Head title="Spending" />
    <AppLayout :breadcrumbs="[{ title: 'Spending', href: '/spending' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Spending by category</h1>
                <p class="text-sm text-muted-foreground">Compare a month, quarter or year. Tap any amount to see those transactions.</p>
            </div>

            <Card>
                <CardContent class="flex flex-wrap items-center gap-3 p-4">
                    <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Period length">
                        <button
                            v-for="[t, l] in [
                                ['month', 'Month'],
                                ['quarter', 'Quarter'],
                                ['year', 'Year'],
                            ]"
                            :key="t"
                            type="button"
                            class="rounded px-3 py-1.5 text-sm"
                            :class="type === t ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                            :aria-pressed="type === t"
                            @click="setType(t)"
                        >
                            {{ l }}
                        </button>
                    </div>
                    <NativeSelect
                        :model-value="period"
                        aria-label="Period"
                        class="w-auto min-w-40"
                        @update:model-value="(v) => go({ key: String(v) })"
                    >
                        <option v-for="p in periods" :key="p.key" :value="p.key">{{ p.label }}</option>
                    </NativeSelect>
                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                        compared with
                        <NativeSelect :model-value="cmp" class="w-auto" @update:model-value="(v) => go({ cmp: String(v) })">
                            <option v-if="type !== 'year'" value="prev">the {{ type }} before</option>
                            <option value="ly">{{ type === 'year' ? 'the year before' : `the same ${type} last year` }}</option>
                        </NativeSelect>
                    </label>
                </CardContent>
            </Card>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>{{ a.label }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ money(totals.a) }}</CardTitle>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>{{ b.label }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ b.hasData ? money(totals.b) : '—' }}</CardTitle>
                    </CardHeader>
                    <CardContent v-if="!b.hasData" class="text-sm text-muted-foreground">Your transactions don't go back that far.</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Difference</CardDescription>
                        <CardTitle
                            class="text-2xl tabular-nums"
                            :class="
                                b.hasData && diff > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : b.hasData && diff < 0
                                      ? 'text-emerald-700 dark:text-emerald-400'
                                      : ''
                            "
                        >
                            {{ b.hasData && totals.b ? (diff > 0 ? '+' : diff < 0 ? '−' : '') + money(Math.abs(diff)) : '—' }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent v-if="b.hasData && totals.b" class="text-sm text-muted-foreground">
                        {{ diff > 0 ? 'more' : 'less' }} than {{ b.label }} ({{ change(totals.a, totals.b)?.pct }})
                    </CardContent>
                </Card>
            </div>

            <p v-if="partial" class="rounded-md bg-muted p-3 text-sm">
                Your transactions for {{ a.label }} go up to {{ shortDate(partial) }}, so {{ b.label }} is counted only through
                {{ shortDate(b.to) }} to compare like with like.
            </p>

            <Card v-if="groups.length === 0">
                <CardContent class="p-6 text-sm text-muted-foreground">No spending in {{ a.label }} or {{ b.label }}.</CardContent>
            </Card>

            <Card v-else class="overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Category</th>
                            <th class="px-3 py-2 text-right font-medium">{{ a.label }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ b.label }}</th>
                            <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="g in groups" :key="g.id">
                            <tr class="border-t bg-muted/20 font-medium">
                                <td class="px-4 py-2.5">
                                    <button
                                        v-if="g.rows.length"
                                        type="button"
                                        class="-ml-1 inline-flex items-center gap-1 text-left"
                                        :aria-expanded="!closed[g.id]"
                                        @click="closed[g.id] = !closed[g.id]"
                                    >
                                        <component :is="closed[g.id] ? ChevronRight : ChevronDown" class="size-4 shrink-0 text-muted-foreground" />{{
                                            g.name
                                        }}
                                    </button>
                                    <span v-else class="pl-5">{{ g.name }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">
                                    <Link v-if="g.a" :href="tx(g.id, a)" class="underline-offset-4 hover:underline">{{ money(g.a) }}</Link
                                    ><span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">
                                    <Link v-if="g.b" :href="tx(g.id, b)" class="underline-offset-4 hover:underline">{{ money(g.b) }}</Link
                                    ><span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-2.5 text-right tabular-nums sm:table-cell">
                                    <template v-if="change(g.a, g.b)">
                                        <span :class="change(g.a, g.b)!.cls">{{ change(g.a, g.b)!.text }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">{{ change(g.a, g.b)!.pct }}</span>
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                            <template v-if="!closed[g.id]">
                                <tr v-for="r in g.rows" :key="r.category" class="border-t border-border/50">
                                    <td class="py-2 pl-10 pr-4 text-muted-foreground" :class="r.category.startsWith('only-') ? 'italic' : ''">
                                        {{ r.name }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                        <Link v-if="r.a" :href="tx(r.category, a)" class="underline-offset-4 hover:underline">{{ money(r.a) }}</Link
                                        ><span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                        <Link v-if="r.b" :href="tx(r.category, b)" class="underline-offset-4 hover:underline">{{ money(r.b) }}</Link
                                        ><span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-2 text-right tabular-nums sm:table-cell">
                                        <template v-if="change(r.a, r.b)">
                                            <span :class="change(r.a, r.b)!.cls">{{ change(r.a, r.b)!.text }}</span>
                                            <span class="ml-1 text-xs text-muted-foreground">{{ change(r.a, r.b)!.pct }}</span>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </template>
                        </template>
                        <tr class="border-t-2 font-semibold">
                            <td class="px-4 py-2.5">Total spending</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ money(totals.a) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ b.hasData ? money(totals.b) : '—' }}</td>
                            <td class="hidden whitespace-nowrap px-4 py-2.5 text-right tabular-nums sm:table-cell">
                                <span v-if="change(totals.a, totals.b)" :class="change(totals.a, totals.b)!.cls">{{
                                    change(totals.a, totals.b)!.text
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <p class="text-sm text-muted-foreground">
                Refunds count against spending. Income, transfers and uncategorized transactions are left out.
            </p>
        </div>
    </AppLayout>
</template>
