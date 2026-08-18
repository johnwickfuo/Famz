<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="rounded-sm border-2 border-dashed border-grain-400 px-6 py-12 text-center dark:border-grain-600">
        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-sm border-2 border-ink bg-chrome-100 text-ink dark:border-wash dark:bg-grain-800 dark:text-wash">
            <slot name="icon">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 7h16v12H4z" />
                    <path d="M4 11h16M9 7V4h6v3" />
                </svg>
            </slot>
        </div>

        <h2 class="font-display text-lg font-bold text-ink dark:text-wash">{{ title }}</h2>

        <p v-if="description" class="mx-auto mt-2 max-w-prose text-sm text-muted">
            {{ description }}
        </p>

        <div v-if="$slots.action" class="mt-5 flex justify-center">
            <slot name="action" />
        </div>
    </div>
</template>
