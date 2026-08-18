<script setup>
import { computed } from 'vue';

/**
 * Colour is never the only signal: every badge carries its own text, and the
 * dot gives a second, non-colour cue.
 */
const props = defineProps({
    variant: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    dot: { type: Boolean, default: false },
});

const variants = {
    neutral: 'border-grain-400 bg-grain-100 text-grain-700 dark:bg-grain-800 dark:text-grain-100 dark:border-grain-500',
    active: 'border-enamel bg-enamel-100 text-enamel-700 dark:bg-enamel-800 dark:text-enamel-100',
    pending: 'border-chrome-700 bg-chrome-100 text-chrome-800 dark:bg-chrome-800 dark:text-chrome-100',
    danger: 'border-cockscomb bg-cockscomb-100 text-cockscomb-700 dark:bg-cockscomb-800 dark:text-cockscomb-100',
    ink: 'border-ink bg-ink text-wash dark:border-wash',
};

const dots = {
    neutral: 'bg-grain-500',
    active: 'bg-enamel',
    pending: 'bg-chrome-700',
    danger: 'bg-cockscomb',
    ink: 'bg-chrome',
};

const sizes = {
    sm: 'px-1.5 py-0.5 text-2xs',
    md: 'px-2 py-1 text-xs',
};

const classes = computed(() => [
    'inline-flex items-center gap-1.5 rounded-full border font-semibold',
    variants[props.variant] ?? variants.neutral,
    sizes[props.size] ?? sizes.md,
]);
</script>

<template>
    <span :class="classes">
        <span
            v-if="dot"
            class="size-1.5 rounded-full"
            :class="dots[variant] ?? dots.neutral"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
