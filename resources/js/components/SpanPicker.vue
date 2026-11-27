<script setup lang="ts">
defineProps<{ value: number | string; options: { value: number | string; label: string }[]; label: string }>();
const emit = defineEmits<{ pick: [value: number | string] }>();
</script>

<template>
    <div class="inline-flex w-fit max-w-full flex-wrap rounded-md border p-0.5 text-sm print:hidden" role="group" :aria-label="label">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            class="rounded px-3 py-1.5"
            :class="value === o.value ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            :aria-pressed="value === o.value"
            @click="emit('pick', o.value)"
        >
            {{ o.label }}
        </button>
    </div>
</template>
