<script setup>
import { computed, ref } from 'vue';
import { useId } from '@/Composables/useId';
import FieldShell from './FieldShell.vue';

const props = defineProps({
    modelValue: { type: [File, Array, null], default: null },
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    accept: { type: String, default: null },
    multiple: { type: Boolean, default: false },
    /** Existing file already on the server, shown until a new one is picked. */
    currentUrl: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const fileId = props.id ?? useId('file');
const hintId = `${fileId}-hint`;
const errorId = `${fileId}-error`;

const input = ref(null);
const dragging = ref(false);
const previewUrl = ref(null);

const chosen = computed(() => {
    if (!props.modelValue) return [];
    return Array.isArray(props.modelValue) ? props.modelValue : [props.modelValue];
});

const describedBy = computed(() => (props.error ? errorId : props.hint ? hintId : undefined));

function accept(files) {
    if (!files || files.length === 0) return;

    const value = props.multiple ? Array.from(files) : files[0];
    emit('update:modelValue', value);

    const first = props.multiple ? files[0] : value;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = first && first.type?.startsWith('image/') ? URL.createObjectURL(first) : null;
}

function onDrop(event) {
    dragging.value = false;
    if (props.disabled) return;
    accept(event.dataTransfer?.files);
}

function clear() {
    emit('update:modelValue', props.multiple ? [] : null);
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    if (input.value) input.value.value = '';
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <FieldShell
        :id="fileId"
        :label="label"
        :hint="hint"
        :error="error"
        :hint-id="hintId"
        :error-id="errorId"
    >
        <div
            class="rounded-sm border-2 border-dashed border-ink bg-surface-raised p-3 dark:border-wash dark:bg-grain-900"
            :class="[
                dragging ? 'bg-chrome-50 dark:bg-grain-800' : '',
                error ? 'border-cockscomb dark:border-cockscomb-300' : '',
            ]"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <div class="flex flex-wrap items-center gap-3">
                <img
                    v-if="previewUrl || currentUrl"
                    :src="previewUrl ?? currentUrl"
                    alt=""
                    class="size-14 shrink-0 rounded-sm border-2 border-ink object-contain dark:border-wash"
                    loading="lazy"
                    decoding="async"
                />

                <div class="min-w-0 flex-1">
                    <input
                        :id="fileId"
                        ref="input"
                        type="file"
                        :accept="accept ?? undefined"
                        :multiple="multiple"
                        :disabled="disabled"
                        :aria-invalid="error ? 'true' : undefined"
                        :aria-describedby="describedBy"
                        class="block w-full text-sm text-ink file:mr-3 file:rounded-sm file:border-2 file:border-ink file:bg-chrome file:px-3 file:py-1.5 file:font-display file:text-2xs file:font-bold file:uppercase file:tracking-wider file:text-ink dark:text-wash dark:file:border-wash"
                        @change="accept($event.target.files)"
                    />

                    <ul v-if="chosen.length" class="mt-2 space-y-0.5">
                        <li
                            v-for="file in chosen"
                            :key="file.name"
                            class="truncate text-xs text-muted"
                        >
                            {{ file.name }}
                            <span class="figures">({{ formatSize(file.size) }})</span>
                        </li>
                    </ul>
                </div>

                <button
                    v-if="chosen.length"
                    type="button"
                    class="stencil shrink-0 text-cockscomb underline underline-offset-4"
                    @click="clear"
                >
                    Remove
                </button>
            </div>
        </div>
    </FieldShell>
</template>
