<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

/**
 * Who applied.
 *
 * Contact details appear here without spending the directory allowance,
 * because these workers chose to give this farm their number. That is a
 * different act from browsing for numbers nobody offered.
 */
defineProps({
    listing: { type: Object, required: true },
    applications: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    notice: { type: Object, required: true },
});

const ratingFor = ref(null);
const ratingForm = useForm({ rating: 5, comment: '' });

function setStatus(applicationId, status) {
    router.post(route('jobs.applications.status', applicationId), { status }, { preserveScroll: true });
}

function submitRating(applicationId) {
    ratingForm.post(route('jobs.applications.rate', applicationId), {
        preserveScroll: true,
        onSuccess: () => {
            ratingForm.reset();
            ratingFor.value = null;
        },
    });
}
</script>

<template>
    <PublicLayout :title="`Applicants — ${listing.title}`">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Hiring</p>
        <h1 class="text-2xl sm:text-3xl">{{ listing.title }}</h1>
        <p class="mt-1 text-sm text-muted">
            {{ applications.length }} {{ applications.length === 1 ? 'applicant' : 'applicants' }}
            <span v-if="listing.where"> · {{ listing.where }}</span>
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" compact />
        </div>

        <hr class="seam seam-chrome my-6" />

        <EmptyState
            v-if="applications.length === 0"
            heading="Nobody has applied yet"
            description="Newly posted jobs usually take a few days. You can also search the directory directly."
        >
            <Button :href="route('jobs.workers.index')">Find workers</Button>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="application in applications" :key="application.id">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="flex min-w-0 items-start gap-3">
                        <img
                            v-if="application.worker?.photo_url"
                            :src="application.worker.photo_url"
                            alt=""
                            class="size-12 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                        />
                        <div class="min-w-0">
                            <Link
                                v-if="application.worker"
                                class="font-bold underline-offset-4 hover:underline"
                                :href="application.worker.url"
                            >
                                {{ application.worker.name }}
                            </Link>
                            <p class="mt-0.5 text-sm text-muted">
                                {{ application.worker?.where }} · {{ application.worker?.experience_label }} · applied
                                {{ application.applied_at }}
                            </p>
                        </div>
                    </div>

                    <Badge :variant="application.status_tone" dot>{{ application.status_label }}</Badge>
                </div>

                <!-- The number, because they offered it to this farm. -->
                <div v-if="application.contact" class="mt-3 rounded-sm border-2 border-grain-300 p-3 dark:border-grain-600">
                    <a class="figures text-lg font-bold underline" :href="`tel:${application.contact.phone}`">
                        {{ application.contact.phone }}
                    </a>
                    <span v-if="application.contact.whatsapp" class="figures ml-3 text-sm text-muted">
                        {{ application.contact.whatsapp }} (WhatsApp)
                    </span>
                </div>

                <p v-if="application.cover_message" class="mt-3 whitespace-pre-line text-sm">
                    {{ application.cover_message }}
                </p>

                <p v-if="application.worker?.skills?.length" class="mt-3 text-xs text-muted">
                    {{ application.worker.skills.join(' · ') }}
                </p>

                <hr class="seam my-3" />

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <Select
                        :model-value="application.status"
                        label="Where they stand"
                        class="max-w-52"
                        @update:model-value="(v) => setStatus(application.id, v)"
                    >
                        <option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option>
                    </Select>

                    <Button
                        v-if="application.can_rate"
                        variant="secondary"
                        size="sm"
                        @click="ratingFor = application.id"
                    >
                        Rate this worker
                    </Button>
                    <p v-else-if="application.has_rated" class="text-xs text-muted">
                        Rated. It appears once we have read it.
                    </p>
                </div>

                <template v-if="ratingFor === application.id">
                    <hr class="seam my-3" />
                    <p class="stencil text-muted">How did they do?</p>
                    <div class="mt-2 flex gap-2">
                        <button
                            v-for="n in 5"
                            :key="n"
                            type="button"
                            class="size-9 rounded-sm border-2 text-sm font-bold transition-colors"
                            :class="
                                ratingForm.rating === n
                                    ? 'border-ink bg-chrome dark:border-wash'
                                    : 'border-grain-300 dark:border-grain-600'
                            "
                            @click="ratingForm.rating = n"
                        >
                            {{ n }}
                        </button>
                    </div>

                    <Textarea
                        v-model="ratingForm.comment"
                        class="mt-3"
                        label="Anything to add"
                        :rows="3"
                        hint="We read every rating before it goes up."
                    />

                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button :disabled="ratingForm.processing" @click="submitRating(application.id)">Send</Button>
                        <Button variant="ghost" @click="ratingFor = null">Cancel</Button>
                    </div>
                </template>
            </Card>
        </div>
    </PublicLayout>
</template>
