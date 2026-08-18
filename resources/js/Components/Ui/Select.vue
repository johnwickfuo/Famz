<script setup>
import { computed } from 'vue';
import { useId } from '@/Composables/useId';
import FieldShell from './FieldShell.vue';

/**
 * A native select on purpose. On a mid-range Android the platform picker is
 * faster, works offline and handles long option lists better than anything a
 * custom listbox would give us.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    /** [{ value, label, disabled? }] or plain strings. */
    options: { type: Array, default: () => [] },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const selectId = props.id ?? useId('select');
const hintId = `${selectId}-hint`;
const errorId = `${selectId}-error`;

const normalised = computed(() =>
    props.options.map((option) =>
        typeof option === 'object' && option !== null
            ? option
            : { value: option, label: String(option) },
    ),
);

const describedBy = computed(() => {
    if (props.error) return errorId;
    if (props.hint) return hintId;
    return undefined;
});
</script>

<template>
    <FieldShell
        :id="selectId"
        :label="label"
        :hint="hint"
        :error="error"
        :required="required"
        :hint-id="hintId"
        :error-id="errorId"
    >
        <div class="relative">
            <select
                :id="selectId"
                :value="modelValue"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="block w-full appearance-none rounded-sm border-2 border-ink bg-surface-raised py-2.5 pl-3 pr-10 text-base text-ink disabled:bg-grain-100 disabled:text-muted dark:border-wash dark:bg-grain-900 dark:text-wash"
                :class="error ? 'border-cockscomb dark:border-cockscomb-300' : ''"
                v-bind="$attrs"
                @change="$emit('update:modelValue', $event.target.value)"
            >
                <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
                <option
                    v-for="option in normalised"
                    :key="option.value"
                    :value="option.value"
                    :disabled="option.disabled"
                >
                    {{ option.label }}
                </option>
            </select>

            <svg
                class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink dark:text-wash"
                viewBox="0 0 16 16"
                fill="currentColor"
                aria-hidden="true"
            >
                <path d="M3 6h10l-5 6-5-6Z" />
            </svg>
        </div>
    </FieldShell>
</template>
