<script setup>
import { computed } from 'vue';
import { useBranding } from '@/Composables/useBranding';

/**
 * The stitched sack label — the one place the platform's identity is drawn.
 *
 * A feed sack is closed with a chain stitch; this is that seam, wrapped around
 * whatever the company turns out to be called. With a logo, the logo sits in
 * the panel. Without one, the company name is set in the display face and the
 * panel grows to fit it. Neither the name nor the logo is ever written here.
 */
const props = defineProps({
    /** 'light' draws on a dark ground, 'dark' draws on a light ground. */
    tone: { type: String, default: 'dark' },
    size: { type: String, default: 'md' },
    /** Hide the wordmark and show only the logo/initials, for tight spaces. */
    compact: { type: Boolean, default: false },
});

const { name, shortName, logoUrl, logoDarkUrl, hasLogo, initials, wordmarkClass } = useBranding();

const sizes = {
    sm: { label: 'px-2 py-1 gap-1.5', word: 'text-xs', logo: 'h-5', mark: 'text-xs' },
    md: { label: 'px-3 py-2 gap-2', word: 'text-base', logo: 'h-7', mark: 'text-sm' },
    lg: { label: 'px-4 py-3 gap-3', word: 'text-xl', logo: 'h-10', mark: 'text-lg' },
};

const dimensions = computed(() => sizes[props.size] ?? sizes.md);

/** On a dark ground the dark-mode logo is the right file to reach for. */
const source = computed(() => (props.tone === 'light' ? logoDarkUrl.value : logoUrl.value));

/*
 * The lockup shows the short name, falling back to the full one.
 *
 * A registered cooperative name runs to ninety characters, and at 360px that
 * wraps to eight lines and takes half the screen before anybody has seen a
 * product. `short_name` is the setting that exists for exactly this, and it was
 * being collected and never read. The full legal name still appears in the
 * footer, next to the RC number, which is where it belongs.
 */
const wordmark = computed(() =>
    // Visible, it is the short name. Read aloud behind a logo, it is the full
    // one — a screen reader should hear who this is, not the abbreviation.
    hasLogo.value && source.value ? name.value : shortName.value,
);
</script>

<template>
    <span
        class="sack-label max-w-full"
        :class="[dimensions.label, tone === 'light' ? 'text-wash' : 'text-ink']"
    >
        <img
            v-if="hasLogo && source"
            :src="source"
            :alt="name"
            class="w-auto shrink-0 object-contain"
            :class="dimensions.logo"
            loading="lazy"
            decoding="async"
        />

        <span
            v-else-if="compact"
            class="wordmark shrink-0"
            :class="dimensions.mark"
            aria-hidden="true"
        >{{ initials }}</span>

        <span
            v-if="!compact"
            :class="[
                wordmarkClass,
                dimensions.word,
                // Clamped in both directions. Three lines is the most a
                // wordmark may take on a phone before it pushes the page below
                // the fold; the width cap stops a long name on a wide screen
                // from squeezing the search field down to nothing.
                hasLogo && source ? 'sr-only' : 'line-clamp-3 max-w-[14rem] sm:max-w-[20rem]',
            ]"
        >{{ wordmark }}</span>

        <span v-if="compact && (!hasLogo || !source)" class="sr-only">{{ name }}</span>
    </span>
</template>
