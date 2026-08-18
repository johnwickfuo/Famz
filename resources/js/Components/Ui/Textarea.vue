<script setup>
import { computed } from 'vue';
import { useId } from '@/Composables/useId';
import FieldShell from './FieldShell.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    rows: { type: Number, default: 4 },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    maxlength: { type: Number, default: null },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const areaId = props.id ?? useId('textarea');
const hintId = `${areaId}-hint`;
const errorId = `${areaId}-error`;

const describedBy = computed(() => {
    if (props.error) return errorId;
    if (props.hint) return hintId;
    return undefined;
});

const remaining = computed(() =>
    props.maxlength ? props.maxlength - (props.modelValue?.length ?? 0) : null,
);
</script>

<template>
    <FieldShell
        :id="areaId"
        :label="label"
        :hint="hint"
        :error="error"
        :required="required"
        :hint-id="hintId"
        :error-id="errorId"
    >
        <textarea
            :id="areaId"
            :value="modelValue"
            :rows="rows"
            :required="required"
            :disabled="disabled"
            :maxlength="maxlength ?? undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-sm border-2 border-ink bg-surface-raised px-3 py-2.5 text-base text-ink placeholder:text-grain-400 disabled:bg-grain-100 dark:border-wash dark:bg-grain-900 dark:text-wash"
            :class="error ? 'border-cockscomb dark:border-cockscomb-300' : ''"
            v-bind="$attrs"
            @input="$emit('update:modelValue', $event.target.value)"
        />

        <p v-if="remaining !== null" class="figures mt-1 text-right text-2xs text-muted" aria-live="polite">
            {{ remaining }}
        </p>
    </FieldShell>
</template>
