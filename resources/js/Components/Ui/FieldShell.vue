<script setup>
/**
 * Shared label / hint / error furniture. Every input in this system wires the
 * same way, so a screen reader hears the same shape everywhere.
 */
defineProps({
    id: { type: String, required: true },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    hintId: { type: String, required: true },
    errorId: { type: String, required: true },
});
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="id" class="stencil mb-1.5 block text-ink dark:text-wash">
            {{ label }}
            <span v-if="required" class="text-cockscomb" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(required)</span>
        </label>

        <slot />

        <p v-if="hint && !error" :id="hintId" class="mt-1.5 text-xs text-muted">
            {{ hint }}
        </p>

        <p
            v-if="error"
            :id="errorId"
            class="mt-1.5 flex items-start gap-1.5 text-xs font-semibold text-cockscomb dark:text-cockscomb-300"
        >
            <svg class="mt-0.5 size-3.5 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path d="M8 1 15 14H1L8 1Zm0 4v5h1V5H8Zm0 6v1.5h1V11H8Z" />
            </svg>
            <span>{{ error }}</span>
        </p>
    </div>
</template>
