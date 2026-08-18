<script setup>
import { computed } from 'vue';
import { useId } from '@/Composables/useId';
import FieldShell from './FieldShell.vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    type: { type: String, default: 'text' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Money, weights, phone numbers and codes get tabular slashed figures. */
    figures: { type: Boolean, default: false },
    prefix: { type: String, default: null },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const inputId = props.id ?? useId('input');
const hintId = `${inputId}-hint`;
const errorId = `${inputId}-error`;

const describedBy = computed(() => {
    if (props.error) return errorId;
    if (props.hint) return hintId;
    return undefined;
});
</script>

<template>
    <FieldShell
        :id="inputId"
        :label="label"
        :hint="hint"
        :error="error"
        :required="required"
        :hint-id="hintId"
        :error-id="errorId"
    >
        <div class="flex items-stretch">
            <span
                v-if="prefix"
                class="figures inline-flex items-center border-2 border-r-0 border-ink bg-grain-100 px-3 text-sm font-semibold text-ink dark:border-wash dark:bg-grain-800 dark:text-wash"
            >{{ prefix }}</span>

            <input
                :id="inputId"
                :value="modelValue"
                :type="type"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="block w-full min-w-0 rounded-sm border-2 border-ink bg-surface-raised px-3 py-2.5 text-base text-ink placeholder:text-grain-400 disabled:bg-grain-100 disabled:text-muted dark:border-wash dark:bg-grain-900 dark:text-wash"
                :class="[
                    figures ? 'figures' : '',
                    error ? 'border-cockscomb dark:border-cockscomb-300' : '',
                    prefix ? 'rounded-l-none' : '',
                ]"
                v-bind="$attrs"
                @input="$emit('update:modelValue', $event.target.value)"
            />
        </div>
    </FieldShell>
</template>
