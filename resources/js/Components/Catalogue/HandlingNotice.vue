<script setup>
import { computed } from 'vue';

/**
 * Live birds and perishable goods are the two things on this platform that go
 * wrong between the sale and the buyer, so the seller's handling arrangement is
 * given its own block rather than being buried in the description.
 */
const props = defineProps({
    isLiveAnimal: { type: Boolean, default: false },
    isPerishable: { type: Boolean, default: false },
    note: { type: String, default: null },
});

const shown = computed(() => props.isLiveAnimal || props.isPerishable);

const heading = computed(() => {
    if (props.isLiveAnimal && props.isPerishable) return 'Live animal · perishable — read before you buy';
    if (props.isLiveAnimal) return 'Live animal — read before you buy';
    return 'Perishable — read before you buy';
});
</script>

<template>
    <aside
        v-if="shown"
        class="rounded-sm border-2 border-cockscomb bg-cockscomb-50 p-4 dark:bg-grain-800"
        aria-labelledby="handling-heading"
    >
        <p id="handling-heading" class="stencil mb-2 flex items-center gap-2 text-cockscomb dark:text-cockscomb-300">
            <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path d="M8 1 15 14H1L8 1Zm0 4v5h1V5H8Zm0 6v1.5h1V11H8Z" />
            </svg>
            {{ heading }}
        </p>

        <p v-if="note" class="prose-farm text-sm">{{ note }}</p>
        <p v-else class="text-sm text-muted">
            Ask the seller how this will reach you before you pay.
        </p>
    </aside>
</template>
