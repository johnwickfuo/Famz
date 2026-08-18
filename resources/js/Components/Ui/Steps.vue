<script setup>
/**
 * The progress rail for a multi-step form. Rendered as a real ordered list so
 * a screen reader hears "step 2 of 4", and the current step is announced.
 */
defineProps({
    steps: { type: Array, required: true },
    current: { type: Number, required: true },
});

defineEmits(['go']);
</script>

<template>
    <nav :aria-label="`Step ${current + 1} of ${steps.length}`">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-2">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="flex items-center gap-2"
            >
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-sm border-2 px-2.5 py-1.5"
                    :class="
                        index === current
                            ? 'border-ink bg-chrome text-ink'
                            : index < current
                              ? 'border-ink bg-enamel text-wash'
                              : 'border-grain-300 text-muted dark:border-grain-600'
                    "
                    :aria-current="index === current ? 'step' : undefined"
                    :disabled="index > current"
                    @click="$emit('go', index)"
                >
                    <span class="figures stencil">{{ index + 1 }}</span>
                    <span class="stencil hidden sm:inline">{{ step }}</span>
                    <span class="sr-only sm:hidden">{{ step }}</span>
                </button>

                <span v-if="index < steps.length - 1" class="text-grain-400" aria-hidden="true">—</span>
            </li>
        </ol>
    </nav>
</template>
