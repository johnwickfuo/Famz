<script setup>
import { computed } from 'vue';

/**
 * A printed label, not a floating panel: flat fill, 2px ink border, and a hard
 * offset instead of a blur. There are no soft shadows in this system.
 */
const props = defineProps({
    /** 'plain' | 'raised' — raised carries the print offset. */
    variant: { type: String, default: 'raised' },
    /** Draws the chain-stitch seam under the header. */
    seam: { type: Boolean, default: true },
    padded: { type: Boolean, default: true },
    as: { type: String, default: 'section' },
});

const classes = computed(() => [
    'rounded-sm border-2 border-ink bg-surface-raised text-ink dark:border-wash dark:bg-grain-900 dark:text-wash',
    props.variant === 'raised' ? 'shadow-offset' : '',
]);
</script>

<template>
    <component :is="as" :class="classes">
        <header v-if="$slots.header" :class="padded ? 'px-4 pt-4 sm:px-5 sm:pt-5' : ''">
            <slot name="header" />
        </header>

        <hr v-if="seam && $slots.header" class="seam mx-4 my-3 sm:mx-5" />

        <div :class="padded ? ['px-4 sm:px-5', $slots.header ? 'pb-4 sm:pb-5' : 'py-4 sm:py-5'] : ''">
            <slot />
        </div>

        <template v-if="$slots.footer">
            <hr class="seam mx-4 sm:mx-5" />
            <footer :class="padded ? 'px-4 py-3 sm:px-5' : ''">
                <slot name="footer" />
            </footer>
        </template>
    </component>
</template>
