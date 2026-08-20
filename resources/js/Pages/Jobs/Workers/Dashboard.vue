<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

defineProps({
    profile: { type: Object, required: true },
    isOpenToWork: { type: Boolean, default: true },
    applications: { type: Array, default: () => [] },
    notice: { type: Object, required: true },
});

const ratingFor = ref(null);
const ratingForm = useForm({ rating: 5, comment: '' });

function submitRating(applicationId) {
    ratingForm.post(route('jobs.applications.rate', applicationId), {
        preserveScroll: true,
        onSuccess: () => {
            ratingForm.reset();
            ratingFor.value = null;
        },
    });
}

function withdraw(applicationId) {
    router.post(route('jobs.applications.withdraw', applicationId), {}, { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="My applications">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <p class="stencil text-enamel dark:text-chrome">Looking for work</p>
                <h1 class="mt-1 text-2xl sm:text-3xl">My applications</h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button :href="route('jobs.index')">Find work</Button>
                <Button variant="secondary" :href="route('jobs.worker.edit')">Edit my profile</Button>
            </div>
        </div>

        <div class="mt-5">
            <BoardNotice :notice="notice" compact />
        </div>

        <p v-if="!isOpenToWork" class="mt-3 rounded-sm border-2 border-chrome bg-chrome-50 p-3 text-sm dark:bg-grain-800">
            Your profile is set to <strong>not looking for work</strong>, so farms will not find you in searches. Edit
            your profile to turn it back on.
        </p>

        <hr class="seam seam-chrome my-6" />

        <EmptyState
            v-if="applications.length === 0"
            heading="You have not applied for anything yet"
            description="The board is free and you can apply to as many as you like."
        >
            <Button :href="route('jobs.index')">See the jobs</Button>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="application in applications" :key="application.id">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <Link
                            v-if="application.listing"
                            class="font-bold underline-offset-4 hover:underline"
                            :href="application.listing.url"
                        >
                            {{ application.listing.title }}
                        </Link>
                        <p class="mt-0.5 text-sm text-muted">
                            {{ application.employer?.business_name }}
                            <span v-if="application.listing?.where"> · {{ application.listing.where }}</span>
                            · applied {{ application.applied_at }}
                        </p>
                    </div>

                    <Badge :variant="application.status_tone" dot>{{ application.status_label }}</Badge>
                </div>

                <!-- Hired: the employer's details, and the right to rate. -->
                <template v-if="application.is_hired && application.employer">
                    <hr class="seam my-3" />
                    <p class="text-sm">
                        <strong>{{ application.employer.business_name }}</strong>
                        <span v-if="application.employer.phone" class="figures"> · {{ application.employer.phone }}</span>
                    </p>
                </template>

                <template v-if="application.can_rate">
                    <hr class="seam my-3" />

                    <template v-if="ratingFor === application.id">
                        <p class="stencil text-muted">How was it?</p>
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
                            :error="ratingForm.errors.comment"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button :disabled="ratingForm.processing" @click="submitRating(application.id)">
                                Send
                            </Button>
                            <Button variant="ghost" @click="ratingFor = null">Cancel</Button>
                        </div>
                    </template>

                    <Button v-else variant="secondary" size="sm" @click="ratingFor = application.id">
                        Rate this employer
                    </Button>
                </template>

                <p v-else-if="application.has_rated" class="mt-3 text-xs text-muted">
                    You rated this one. It appears once we have read it.
                </p>

                <div v-if="application.can_withdraw" class="mt-3">
                    <button type="button" class="text-xs text-muted underline" @click="withdraw(application.id)">
                        Withdraw this application
                    </button>
                </div>
            </Card>
        </div>
    </PublicLayout>
</template>
