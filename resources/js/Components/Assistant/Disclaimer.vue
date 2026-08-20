<script setup>
/**
 * What this assistant is, and what it will not do.
 *
 * Sits directly above the input, not in the footer, and has no dismiss control
 * — no close button, no "don't show again", no prop to hide it. That is a
 * deliberate choice rather than an oversight: a notice you can dismiss is a
 * notice that is gone on the one day somebody was about to act on a wrong
 * answer, and the whole point of it is to be in the way at the moment of
 * asking.
 *
 * The text comes from the server so that the company name is whatever the
 * administrator has set, and so that a redesign of this page cannot lose the
 * wording by editing a component's default.
 */
defineProps({
    disclaimer: { type: Object, required: true },
    consultationUrl: { type: String, required: true },
});
</script>

<template>
    <div
        class="rounded-sm border-2 border-ink bg-chrome-50 p-3 dark:border-wash dark:bg-grain-800"
        role="note"
        aria-label="How to use this assistant"
    >
        <p class="stencil text-ink dark:text-wash">{{ disclaimer.heading }}</p>

        <ul class="mt-2 space-y-1.5">
            <li
                v-for="(point, index) in disclaimer.points"
                :key="index"
                class="flex gap-2 text-xs leading-relaxed sm:text-sm"
            >
                <span class="mt-1 size-1.5 shrink-0 rounded-full bg-enamel dark:bg-chrome" aria-hidden="true" />
                <span>{{ point }}</span>
            </li>
        </ul>

        <a
            :href="consultationUrl"
            class="mt-2.5 inline-block text-xs font-bold uppercase tracking-wider text-enamel underline underline-offset-4 dark:text-chrome sm:text-sm"
        >
            Book a consultation
        </a>
    </div>
</template>
