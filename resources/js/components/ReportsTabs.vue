<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ current: 'cashflow' | 'networth' | 'budget' | 'payees' | 'year' }>();
const tabs = [
    { key: 'cashflow', label: 'Cash flow', route: 'reports.cashflow' },
    { key: 'networth', label: 'Net worth', route: 'reports.networth' },
    { key: 'budget', label: 'Budget vs actual', route: 'reports.budget' },
    { key: 'payees', label: 'Payees', route: 'reports.payees' },
    { key: 'year', label: 'Year in review', route: 'reports.year' },
] as const;
</script>

<template>
    <nav class="inline-flex w-fit max-w-full flex-wrap rounded-md border p-0.5 text-sm print:hidden" aria-label="Reports">
        <Link
            v-for="t in tabs"
            :key="t.key"
            :href="route(t.route)"
            :aria-current="current === t.key ? 'page' : undefined"
            class="rounded px-3 py-1.5"
            :class="current === t.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            >{{ t.label }}</Link
        >
    </nav>
</template>
