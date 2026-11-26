<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<{ flash: { status?: string | null } }>();
const message = computed(() => page.props.flash?.status ?? null);
const visible = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => [message.value, page.props],
    () => {
        if (!message.value) return;
        visible.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => (visible.value = false), 3000);
    },
    { immediate: true },
);
</script>

<template>
    <div
        v-if="visible && message"
        role="status"
        class="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-2 rounded-lg bg-foreground px-4 py-2.5 text-sm text-background shadow-lg"
    >
        <CheckCircle2 class="size-4" />{{ message }}
    </div>
</template>
