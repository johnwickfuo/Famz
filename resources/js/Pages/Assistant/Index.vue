<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Disclaimer from '@/Components/Assistant/Disclaimer.vue';
import { csrfToken } from '@/Support/http';

/**
 * The free assistant.
 *
 * Built for a phone held in one hand in a poultry house: one column, big tap
 * targets, and the input pinned where a thumb is. The transcript scrolls, the
 * disclaimer and the input do not.
 *
 * Answers arrive as JSON rather than as a re-rendered page, because reloading
 * the whole transcript to add one message costs a rural connection real time
 * and loses the scroll position every turn.
 */
const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    disclaimer: { type: Object, required: true },
    suggestions: { type: Array, default: () => [] },
    allowance: { type: Object, default: () => ({ limit: 0, remaining: 0, scope: 'none' }) },
    consultationUrl: { type: String, required: true },
});

const transcript = ref([...props.messages]);
const conversationId = ref(props.conversation.id);
const allowance = ref({ ...props.allowance });
const draft = ref('');
const sending = ref(false);
const notice = ref(null);
const degraded = ref(false);
const scroller = ref(null);
const input = ref(null);

const hasTranscript = computed(() => transcript.value.length > 0);
const canSend = computed(() => draft.value.trim().length >= 2 && !sending.value);
const lowAllowance = computed(
    () => allowance.value.limit > 0 && allowance.value.remaining <= 3 && allowance.value.remaining > 0,
);

watch(transcript, () => nextTick(scrollToEnd), { deep: true });

function scrollToEnd() {
    const el = scroller.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

function useSuggestion(text) {
    draft.value = text;
    input.value?.focus();
}

async function send() {
    if (!canSend.value) {
        return;
    }

    const message = draft.value.trim();

    // Shown immediately with a temporary id. Waiting for the server to echo it
    // back makes the interface feel broken on a slow connection, when the
    // person has plainly just typed the thing.
    const pending = { id: `pending-${Date.now()}`, role: 'user', content: message, sources: [] };

    transcript.value = [...transcript.value, pending];
    draft.value = '';
    sending.value = true;
    notice.value = null;

    try {
        const response = await fetch(route('assistant.ask'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ message, conversation_id: conversationId.value }),
        });

        // Read the body whatever the status: a 429 carries the explanation of
        // why, and throwing it away would leave the person with nothing but a
        // spinner that stopped.
        const data = await response.json().catch(() => null);

        if (!response.ok || !data?.ok) {
            transcript.value = transcript.value.filter((m) => m.id !== pending.id);
            draft.value = message;
            notice.value = data?.message ?? 'Something went wrong. Please try again.';

            return;
        }

        conversationId.value = data.conversation_id;
        allowance.value = data.allowance ?? allowance.value;
        degraded.value = Boolean(data.degraded);

        transcript.value = [
            ...transcript.value.filter((m) => m.id !== pending.id),
            data.question,
            data.answer,
        ];
    } catch {
        transcript.value = transcript.value.filter((m) => m.id !== pending.id);
        draft.value = message;
        notice.value = 'Could not reach the assistant. Check your connection and try again.';
    } finally {
        sending.value = false;
        nextTick(() => input.value?.focus());
    }
}

async function startOver() {
    const response = await fetch(route('assistant.reset'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).catch(() => null);

    const data = await response?.json().catch(() => null);

    if (data?.ok) {
        conversationId.value = data.conversation_id;
        transcript.value = [];
        notice.value = null;
    }
}

/**
 * Enter sends, shift+enter makes a new line — except on a touch keyboard,
 * where enter is the only way to get a line break and hijacking it is
 * infuriating.
 */
function onKeydown(event) {
    const touch = window.matchMedia?.('(pointer: coarse)').matches;

    if (event.key === 'Enter' && !event.shiftKey && !touch) {
        event.preventDefault();
        send();
    }
}
</script>

<template>
    <PublicLayout title="Farm assistant">
        <div class="mx-auto flex w-full max-w-3xl flex-col">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="stencil mb-2 text-enamel dark:text-chrome">Free · no account needed</p>
                    <h1 class="text-2xl sm:text-3xl">Farm assistant</h1>
                    <p class="mt-2 max-w-xl text-sm text-muted">
                        Ask about feeding, housing, water, brooding or what things cost.
                        English or Pidgin — whichever you prefer.
                    </p>
                </div>

                <button
                    v-if="hasTranscript"
                    type="button"
                    class="mt-1 shrink-0 text-xs font-bold uppercase tracking-wider underline underline-offset-4 text-muted hover:text-ink dark:hover:text-wash"
                    @click="startOver"
                >
                    Start over
                </button>
            </div>

            <hr class="seam seam-chrome my-5" />

            <!--
                Transcript. A flex column with the content pushed down by
                mt-auto, so a short conversation sits just above the input
                rather than stranding an empty half-screen between the last
                answer and the notice — messages grow upward, the way every
                chat anybody has used behaves.
            -->
            <div
                ref="scroller"
                class="flex min-h-[38vh] max-h-[52vh] flex-col overflow-y-auto overscroll-contain pr-1"
                role="log"
                aria-live="polite"
                aria-label="Conversation"
            >
                <div v-if="!hasTranscript" class="mt-auto py-2">
                    <p class="text-sm text-muted">Try one of these:</p>
                    <ul class="mt-3 space-y-2">
                        <li v-for="(suggestion, index) in suggestions" :key="index">
                            <button
                                type="button"
                                class="w-full rounded-sm border-2 border-chrome bg-surface-raised px-3 py-2.5 text-left text-sm hover:border-ink dark:border-grain-700 dark:bg-grain-800 dark:hover:border-wash"
                                @click="useSuggestion(suggestion)"
                            >
                                {{ suggestion }}
                            </button>
                        </li>
                    </ul>
                </div>

                <ul v-else class="mt-auto space-y-3 py-1">
                    <li
                        v-for="message in transcript"
                        :key="message.id"
                        :class="message.role === 'user' ? 'flex justify-end' : 'flex justify-start'"
                    >
                        <div
                            class="max-w-[85%] rounded-sm border-2 px-3 py-2.5"
                            :class="
                                message.role === 'user'
                                    ? 'border-ink bg-chrome text-ink'
                                    : 'border-ink bg-surface-raised dark:border-wash dark:bg-grain-800'
                            "
                        >
                            <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ message.content }}</p>

                            <!--
                                Where the figures came from. Shown under the answer
                                rather than woven into it, so a farmer can see at a
                                glance whether a number has a source behind it.
                            -->
                            <p
                                v-if="message.sources?.length"
                                class="mt-2 border-t-2 border-dotted border-chrome pt-1.5 text-2xs leading-relaxed text-muted dark:border-grain-700"
                            >
                                Figures from: {{ message.sources.join(' · ') }}
                            </p>
                        </div>
                    </li>

                    <li v-if="sending" class="flex justify-start">
                        <div class="rounded-sm border-2 border-dashed border-chrome px-3 py-2.5 dark:border-grain-700">
                            <p class="text-sm text-muted">Thinking…</p>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- The notice, directly above the input and never dismissable. -->
            <div class="mt-4">
                <Disclaimer :disclaimer="disclaimer" :consultation-url="consultationUrl" />
            </div>

            <p
                v-if="degraded"
                class="mt-3 rounded-sm border-2 border-dashed border-enamel px-3 py-2 text-xs dark:border-chrome"
                role="status"
            >
                The assistant is very busy today and is answering common questions only.
                It will be back to normal tomorrow.
            </p>

            <p
                v-if="notice"
                class="mt-3 rounded-sm border-2 border-cockscomb bg-cockscomb-50 px-3 py-2 text-sm dark:bg-grain-800"
                role="alert"
            >
                {{ notice }}
            </p>

            <form class="mt-3" @submit.prevent="send">
                <label for="assistant-input" class="sr-only">Your question</label>
                <div class="flex items-end gap-2">
                    <textarea
                        id="assistant-input"
                        ref="input"
                        v-model="draft"
                        rows="2"
                        maxlength="1000"
                        placeholder="How much feed for 500 broilers?"
                        class="min-h-[3.25rem] w-full resize-none rounded-sm border-2 border-ink bg-surface-raised px-3 py-2.5 text-base dark:border-wash dark:bg-grain-800"
                        @keydown="onKeydown"
                    />
                    <Button type="submit" :disabled="!canSend" :loading="sending" class="shrink-0">
                        Ask
                    </Button>
                </div>

                <p v-if="lowAllowance" class="mt-2 text-2xs text-muted">
                    {{ allowance.remaining }} question{{ allowance.remaining === 1 ? '' : 's' }} left today.
                </p>
            </form>
        </div>
    </PublicLayout>
</template>
