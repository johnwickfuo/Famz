<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    as: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});

const component = computed(() => (props.href ? Link : 'button'));

const base = [
    'inline-flex items-center justify-center gap-2 rounded-sm border-2 font-display font-bold',
    'uppercase tracking-wider transition-[transform,box-shadow,background-color] duration-100',
    'disabled:cursor-not-allowed disabled:opacity-55 disabled:shadow-none',
    // The offset is the design's "pressed print" — it collapses on press
    // rather than animating a shadow blur.
    'active:translate-x-[2px] active:translate-y-[2px] active:shadow-none',
    'motion-reduce:active:translate-x-0 motion-reduce:active:translate-y-0',
];

const variants = {
    primary: 'border-ink bg-chrome text-ink shadow-offset hover:bg-chrome-400',
    secondary: 'border-ink bg-surface-raised text-ink shadow-offset hover:bg-grain-100 dark:bg-grain-800 dark:text-wash dark:border-wash dark:hover:bg-grain-700',
    enamel: 'border-ink bg-enamel text-wash shadow-offset hover:bg-enamel-600',
    danger: 'border-ink bg-cockscomb text-wash shadow-offset hover:bg-cockscomb-600',
    ghost: 'border-transparent bg-transparent text-ink underline-offset-4 hover:underline dark:text-wash',
};

const sizes = {
    sm: 'px-3 py-1.5 text-2xs min-h-9',
    md: 'px-4 py-2.5 text-xs min-h-11',
    lg: 'px-6 py-3.5 text-sm min-h-12',
};

const classes = computed(() => [
    ...base,
    variants[props.variant] ?? variants.primary,
    sizes[props.size] ?? sizes.md,
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <component
        :is="as ?? component"
        :class="classes"
        :type="href ? undefined : type"
        :href="href ?? undefined"
        :method="href ? method : undefined"
        :disabled="href ? undefined : disabled || loading"
        :aria-busy="loading ? 'true' : undefined"
        :aria-disabled="href && (disabled || loading) ? 'true' : undefined"
    >
        <svg
            v-if="loading"
            class="size-4 shrink-0 animate-spin motion-reduce:animate-none"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="square" />
        </svg>
        <slot />
    </component>
</template>
