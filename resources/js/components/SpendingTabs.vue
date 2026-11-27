<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ current: 'compare' | 'monthly' }>();
</script>

<template>
    <div class="inline-flex rounded-md border p-0.5 text-sm" role="tablist" aria-label="Spending view">
        <Link
            :href="route('spending')"
            role="tab"
            :aria-selected="current === 'compare'"
            class="rounded px-3 py-1.5"
            :class="current === 'compare' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            >Compare periods</Link
        >
        <Link
            :href="route('spending.monthly')"
            role="tab"
            :aria-selected="current === 'monthly'"
            class="rounded px-3 py-1.5"
            :class="current === 'monthly' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            >By month</Link
        >
    </div>
</template>
