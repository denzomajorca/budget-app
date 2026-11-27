<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import ReportsTabs from '@/components/ReportsTabs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dollars, percent } from '@/lib/format';
import { shortDate } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    period: string;
    label: string;
    periods: Record<string, string>;
    from: string;
    to: string;
    rows: { payee: string; count: number; total: number; average: number; last: string; category: string }[];
    count: number;
    total: number;
    top10: number | null;
}>();

const sel = ref(props.period);
const pick = () => router.get(route('reports.payees'), { period: sel.value }, { preserveScroll: true, preserveState: true });
const limit = ref(25);
const shown = computed(() => props.rows.slice(0, limit.value));
const max = computed(() => props.rows[0]?.total ?? 1);
const often = computed(() => [...props.rows].sort((a, b) => b.count - a.count).slice(0, 5));
</script>

<template>
    <Head title="Payees" />
    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Reports</h1>
            <ReportsTabs current="payees" />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">
                    Who your money went to, {{ shortDate(from) }} to {{ shortDate(to) }}. Refunds count against a payee.
                </p>
                <NativeSelect v-model="sel" class="w-auto print:hidden" aria-label="Period" @change="pick">
                    <option v-for="(l, k) in periods" :key="k" :value="k">{{ l }}</option>
                </NativeSelect>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Spent</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ dollars(total) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">with {{ count }} payees</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Your top 10 payees</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ percent(top10) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">of everything you spent</CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Paid most often</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-1 text-sm">
                        <div v-for="p in often" :key="p.payee" class="flex justify-between gap-3">
                            <span class="min-w-0 truncate">{{ p.payee }}</span
                            ><span class="shrink-0 text-muted-foreground">{{ p.count }} times</span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="w-0 min-w-full overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Payee</th>
                            <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Times</th>
                            <th class="hidden px-3 py-2 text-right font-medium md:table-cell">Average</th>
                            <th class="px-3 py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in shown" :key="r.payee" class="border-t align-top">
                            <td class="px-3 py-2">
                                <Link :href="route('transactions.index', { q: r.payee, from, to })" class="underline-offset-4 hover:underline">{{
                                    r.payee
                                }}</Link>
                                <div class="text-xs text-muted-foreground">{{ r.category }} · last {{ shortDate(r.last) }}</div>
                                <div class="mt-1 h-1.5 w-full max-w-64 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full bg-sky-600" :style="{ width: `${(r.total / max) * 100}%` }" />
                                </div>
                            </td>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ r.count }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums md:table-cell">{{ dollars(r.average) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ dollars(r.total) }}
                                <div class="text-xs text-muted-foreground">{{ percent(total ? r.total / total : null) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Button v-if="rows.length > limit" variant="outline" class="w-fit" @click="limit += 50">Show more payees</Button>
        </div>
    </AppLayout>
</template>
