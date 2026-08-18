<script setup>
import { computed } from 'vue';
import { useId } from '@/Composables/useId';

/**
 * A real fieldset with real checkboxes. Options may be flat or grouped —
 * grouped is what the category picker uses.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** [{ id, name, children? }] */
    groups: { type: Array, default: () => [] },
    legend: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    max: { type: Number, default: null },
});

const emit = defineEmits(['update:modelValue']);

const groupId = useId('checkbox-group');
const selected = computed(() => props.modelValue ?? []);

function toggle(id) {
    const next = selected.value.includes(id)
        ? selected.value.filter((item) => item !== id)
        : [...selected.value, id];

    if (props.max && next.length > props.max) return;

    emit('update:modelValue', next);
}
</script>

<template>
    <fieldset class="min-w-0">
        <legend v-if="legend" class="stencil mb-1.5 text-ink dark:text-wash">{{ legend }}</legend>
        <p v-if="hint" :id="`${groupId}-hint`" class="mb-3 text-xs text-muted">{{ hint }}</p>

        <div class="space-y-4">
            <div v-for="group in groups" :key="group.id">
                <p class="stencil mb-2 text-enamel dark:text-chrome">{{ group.name }}</p>

                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="option in group.children.length ? group.children : [group]"
                        :key="option.id"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-sm border-2 px-2.5 py-1.5 text-sm"
                        :class="
                            selected.includes(option.id)
                                ? 'border-ink bg-chrome-100 font-semibold text-ink dark:bg-grain-800 dark:text-wash dark:border-wash'
                                : 'border-grain-300 text-ink dark:border-grain-600 dark:text-wash'
                        "
                    >
                        <input
                            type="checkbox"
                            class="size-4 rounded-sm border-2 border-ink"
                            :value="option.id"
                            :checked="selected.includes(option.id)"
                            :aria-describedby="hint ? `${groupId}-hint` : undefined"
                            @change="toggle(option.id)"
                        />
                        {{ option.name }}
                    </label>
                </div>
            </div>
        </div>

        <p v-if="max" class="figures mt-3 text-2xs text-muted" aria-live="polite">
            {{ selected.length }} / {{ max }} selected
        </p>

        <p v-if="error" class="mt-2 text-xs font-semibold text-cockscomb dark:text-cockscomb-300">
            {{ error }}
        </p>
    </fieldset>
</template>
