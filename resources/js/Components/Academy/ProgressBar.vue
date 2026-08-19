<script setup>
import { computed } from 'vue';

/**
 * How far through a course somebody is.
 *
 * The number is written out beside the bar rather than left to the fill alone:
 * a bar on its own is a shape, and "62%" is an answer.
 */
const props = defineProps({
    percent: { type: Number, default: 0 },
    label: { type: String, default: null },
    size: { type: String, default: 'md' },
    showValue: { type: Boolean, default: true },
});

const clamped = computed(() => Math.max(0, Math.min(100, Math.round(props.percent))));
const done = computed(() => clamped.value >= 100);
</script>

<template>
    <div>
        <div v-if="label || showValue" class="mb-1 flex items-baseline justify-between gap-2">
            <span v-if="label" class="stencil text-muted">{{ label }}</span>
            <span v-if="showValue" class="figures text-xs font-semibold">{{ clamped }}%</span>
        </div>

        <div
            class="w-full overflow-hidden rounded-full border-2 border-ink bg-grain-100 dark:border-wash dark:bg-grain-800"
            :class="size === 'sm' ? 'h-2' : 'h-3'"
            role="progressbar"
            :aria-valuenow="clamped"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label ?? 'Course progress'"
        >
            <div
                class="h-full transition-[width] duration-300 motion-reduce:transition-none"
                :class="done ? 'bg-enamel' : 'bg-chrome'"
                :style="{ width: `${clamped}%` }"
            ></div>
        </div>
    </div>
</template>
