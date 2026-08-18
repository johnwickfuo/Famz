<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Reads the `flash` prop shared from the server. Announced politely so a
 * screen-reader user hears the result of what they just did.
 */
const page = usePage();
const dismissed = ref(null);

const message = computed(() => {
    const flash = page.props.flash ?? {};

    for (const tone of ['success', 'error', 'info']) {
        if (flash[tone] && flash[tone] !== dismissed.value) {
            return { tone, text: flash[tone] };
        }
    }

    return null;
});

const tones = {
    success: 'border-ink bg-enamel text-wash',
    error: 'border-ink bg-cockscomb text-wash',
    info: 'border-ink bg-chrome text-ink',
};

let timer = null;

watch(message, (current) => {
    if (timer) clearTimeout(timer);
    if (!current) return;

    timer = setTimeout(() => {
        dismissed.value = current.text;
    }, 6000);
});
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center p-3 sm:bottom-auto sm:top-0 sm:justify-end"
        role="status"
        aria-live="polite"
    >
        <div
            v-if="message"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-sm border-2 px-4 py-3 shadow-offset"
            :class="tones[message.tone]"
        >
            <p class="min-w-0 flex-1 text-sm font-semibold">{{ message.text }}</p>

            <button
                type="button"
                class="shrink-0 opacity-80 hover:opacity-100"
                @click="dismissed = message.text"
            >
                <span class="sr-only">Dismiss</span>
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M5.3 4.3 10 9l4.7-4.7 1 1L11 10l4.7 4.7-1 1L10 11l-4.7 4.7-1-1L9 10 4.3 5.3l1-1Z" />
                </svg>
            </button>
        </div>
    </div>
</template>
