<script setup>
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

const props = defineProps({
    dispute: { type: Object, required: true },
    /** The thing being argued about: an order part, or a mentorship engagement. */
    subject: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    canReply: { type: Boolean, default: false },
});

const tone = {
    open: 'pending',
    under_review: 'pending',
    resolved_buyer: 'active',
    resolved_seller: 'active',
    resolved_partial: 'active',
    closed: 'neutral',
};

const form = useForm({ body: '' });

function send() {
    form.post(route('disputes.reply', props.dispute.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}
</script>

<template>
    <PublicLayout :title="`Dispute — ${subject.reference}`">
        <div class="mx-auto max-w-3xl">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div class="min-w-0">
                    <h1 class="text-2xl sm:text-3xl">{{ dispute.reason }}</h1>
                    <p class="mt-1 text-sm text-muted">
                        {{ subject.other_party }} · <span class="figures">{{ subject.reference }}</span> · raised
                        {{ dispute.raised_at }}
                    </p>
                </div>
                <Badge :variant="tone[dispute.status] ?? 'neutral'" dot>{{ dispute.status_label }}</Badge>
            </div>

            <hr class="seam my-5" />

            <div
                v-if="dispute.is_live"
                class="mb-5 rounded-sm border-2 border-chrome-700 bg-chrome-100 p-4 dark:bg-grain-800"
            >
                <p class="text-sm">
                    <span class="figures font-semibold">{{ dispute.amount }}</span> is on hold while we look at this.
                    Add anything that helps below — the seller sees it too.
                </p>
            </div>

            <div v-else class="mb-5 rounded-sm border-2 border-ink p-4 dark:border-wash">
                <p class="font-display text-sm font-bold uppercase tracking-wider">{{ dispute.status_label }}</p>
                <p v-if="dispute.refunded" class="figures mt-1 text-sm">
                    {{ dispute.refunded }} has gone back to you. It reaches your bank on your bank's own schedule.
                </p>
                <p v-if="dispute.resolution_note" class="mt-2 text-sm text-muted">{{ dispute.resolution_note }}</p>
            </div>

            <Card v-if="dispute.evidence.length" class="mb-5">
                <template #header>
                    <h2 class="text-base">Photographs</h2>
                </template>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="(url, index) in dispute.evidence" :key="index">
                        <a :href="url" target="_blank" rel="noopener">
                            <img
                                :src="url"
                                :alt="`Evidence ${index + 1}`"
                                class="size-24 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                            />
                        </a>
                    </li>
                </ul>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-lg">The conversation</h2>
                    <p class="mt-1 text-xs text-muted">
                        Everyone involved reads the same thread — you, the seller, and us.
                    </p>
                </template>

                <ol class="space-y-4">
                    <li v-for="message in messages" :key="message.id" class="flex flex-col">
                        <div
                            class="max-w-[85%] rounded-sm border-2 p-3"
                            :class="
                                message.is_mine
                                    ? 'self-end border-ink bg-chrome-100 dark:border-wash dark:bg-grain-800'
                                    : 'self-start border-grain-300 dark:border-grain-600'
                            "
                        >
                            <p class="text-xs font-semibold text-muted">
                                {{ message.is_mine ? 'You' : message.author }} · {{ message.at }}
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ message.body }}</p>
                        </div>
                    </li>
                </ol>

                <template v-if="canReply" #footer>
                    <form class="space-y-3" @submit.prevent="send">
                        <Textarea
                            v-model="form.body"
                            label="Add to the conversation"
                            :rows="3"
                            :maxlength="2000"
                            :error="form.errors.body"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :loading="form.processing"
                            :disabled="form.processing || !form.body.trim()"
                        >
                            Send
                        </Button>
                    </form>
                </template>
            </Card>
        </div>
    </PublicLayout>
</template>
