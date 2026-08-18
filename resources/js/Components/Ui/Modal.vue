<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    maxWidth: { type: String, default: 'md' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`;

const widths = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-2xl',
};

const widthClass = computed(() => widths[props.maxWidth] ?? widths.md);

function close() {
    if (props.closeable) emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
        return;
    }

    if (event.key !== 'Tab' || !panel.value) return;

    // Keep focus inside the dialog.
    const focusable = panel.value.querySelectorAll(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
    );

    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

let previouslyFocused = null;

watch(
    () => props.show,
    async (open) => {
        if (typeof document === 'undefined') return;

        if (open) {
            previouslyFocused = document.activeElement;
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            panel.value?.focus();
        } else {
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onKeydown);
            previouslyFocused?.focus?.();
        }
    },
);

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
            <div
                class="absolute inset-0 bg-ink/70"
                aria-hidden="true"
                @click="close"
            />

            <div
                ref="panel"
                class="relative w-full rounded-sm border-2 border-ink bg-surface-raised shadow-offset-lg dark:border-wash dark:bg-grain-900"
                :class="widthClass"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="title ? titleId : undefined"
                tabindex="-1"
            >
                <div class="flex items-start justify-between gap-3 px-4 pt-4 sm:px-5">
                    <h2 v-if="title" :id="titleId" class="font-display text-lg font-bold text-ink dark:text-wash">
                        {{ title }}
                    </h2>
                    <slot name="header" />

                    <button
                        v-if="closeable"
                        type="button"
                        class="-mr-1 -mt-1 shrink-0 rounded-sm p-1 text-muted hover:text-ink dark:hover:text-wash"
                        @click="close"
                    >
                        <span class="sr-only">Close</span>
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M5.3 4.3 10 9l4.7-4.7 1 1L11 10l4.7 4.7-1 1L10 11l-4.7 4.7-1-1L9 10 4.3 5.3l1-1Z" />
                        </svg>
                    </button>
                </div>

                <hr class="seam mx-4 my-3 sm:mx-5" />

                <div class="max-h-[70vh] overflow-y-auto px-4 pb-4 sm:px-5 sm:pb-5">
                    <slot />
                </div>

                <template v-if="$slots.footer">
                    <hr class="seam mx-4 sm:mx-5" />
                    <div class="flex flex-wrap justify-end gap-2 px-4 py-3 sm:px-5">
                        <slot name="footer" />
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>
