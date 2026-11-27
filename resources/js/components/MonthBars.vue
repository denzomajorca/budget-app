<script setup lang="ts">
import { axisDollars, niceScale } from '@/lib/format';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';

/**
 * Grouped bars per month (e.g. income beside spending), with an optional
 * line drawn on top (e.g. what was kept). Values are cents.
 */
const props = defineProps<{
    labels: string[];
    bars: { label: string; fill: string; swatch: string; values: number[] }[];
    line?: { label: string; stroke: string; dot: string; swatch: string; values: number[] };
    ariaLabel: string;
}>();

const narrow = useMediaQuery('(max-width: 640px)');
const W = computed(() => (narrow.value ? 360 : 720));
const H = computed(() => (narrow.value ? 220 : 240));
const pad = computed(() => ({ l: narrow.value ? 44 : 52, r: 6, t: 10, b: 22 }));
const all = computed(() => props.bars.flatMap((b) => b.values).concat(props.line?.values ?? []));
const scale = computed(() => niceScale(Math.min(...all.value, 0), Math.max(...all.value, 1)));
const y = (v: number) => pad.value.t + ((scale.value.max - v) / (scale.value.max - scale.value.min || 1)) * (H.value - pad.value.t - pad.value.b);
const slot = computed(() => (W.value - pad.value.l - pad.value.r) / Math.max(1, props.labels.length));
const bw = computed(() => Math.max(2, (slot.value * 0.72) / Math.max(1, props.bars.length)));
const bx = (i: number, k: number) => pad.value.l + i * slot.value + slot.value * 0.14 + k * bw.value;
const cx = (i: number) => pad.value.l + i * slot.value + slot.value / 2;
const every = computed(() => Math.ceil(props.labels.length / (narrow.value ? 6 : 12)));
const linePts = computed(() => (props.line ? props.line.values.map((v, i) => `${cx(i)},${y(v)}`).join(' ') : ''));
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" :aria-label="ariaLabel">
        <g v-for="t in scale.ticks" :key="t">
            <line :x1="pad.l" :x2="W - pad.r" :y1="y(t)" :y2="y(t)" class="stroke-border" :stroke-width="t === 0 ? 1.5 : 1" />
            <text :x="pad.l - 6" :y="y(t) + 4" text-anchor="end" class="fill-muted-foreground text-[11px]">{{ axisDollars(t) }}</text>
        </g>
        <template v-for="(lab, i) in labels" :key="i">
            <rect
                v-for="(b, k) in bars"
                :key="k"
                :x="bx(i, k)"
                :y="Math.min(y(b.values[i]), y(0))"
                :width="bw - 1"
                :height="Math.max(0.5, Math.abs(y(b.values[i]) - y(0)))"
                :class="b.fill"
                rx="1.5"
            >
                <title>{{ lab }} · {{ b.label }}: {{ axisDollars(b.values[i]) }}</title>
            </rect>
            <text v-if="i % every === 0" :x="cx(i)" :y="H - 6" text-anchor="middle" class="fill-muted-foreground text-[11px]">{{ lab }}</text>
        </template>
        <template v-if="line">
            <polyline :points="linePts" fill="none" :class="line.stroke" stroke-width="2" stroke-linejoin="round" />
            <circle v-for="(v, i) in line.values" :key="i" :cx="cx(i)" :cy="y(v)" r="2.5" :class="line.dot" />
        </template>
    </svg>
    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
        <span v-for="b in bars" :key="b.label" class="inline-flex items-center gap-1.5"
            ><span class="size-2.5 rounded-sm" :class="b.swatch" />{{ b.label }}</span
        >
        <span v-if="line" class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4" :class="line.swatch" />{{ line.label }}</span>
    </div>
</template>
