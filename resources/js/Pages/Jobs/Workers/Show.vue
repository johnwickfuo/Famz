<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

/**
 * One worker.
 *
 * `contact` is null unless the server released it. There is no branch in this
 * component that could reconstruct a number, because what it is given does not
 * contain one — the omission happened before the payload was built.
 */
defineProps({
    worker: { type: Object, required: true },
    contact: { type: Object, default: null },
    contactReason: { type: String, default: null },
    contactMessage: { type: String, default: null },
    remaining: { type: Number, default: null },
    ratings: { type: Array, default: () => [] },
    isSelf: { type: Boolean, default: false },
    notice: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="worker.name">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-start gap-4">
                <img
                    v-if="worker.photo_url"
                    :src="worker.photo_url"
                    alt=""
                    class="size-16 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                />
                <div class="min-w-0">
                    <p class="stencil text-enamel dark:text-chrome">Farm worker</p>
                    <h1 class="mt-1 text-2xl sm:text-3xl">{{ worker.name }}</h1>
                    <p class="mt-1 text-sm text-muted">
                        {{ worker.where }} · {{ worker.experience_label }} · {{ worker.work_type }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="worker.availability_tone" dot>{{ worker.availability }}</Badge>
                <Badge v-if="worker.willing_to_relocate" variant="info">Will relocate</Badge>
                <Badge v-if="!worker.is_open_to_work" variant="muted">Not looking right now</Badge>
            </div>
        </div>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <hr class="seam seam-chrome my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <Card v-if="worker.about">
                    <template #header>
                        <h2 class="text-base">About</h2>
                    </template>
                    <p class="whitespace-pre-line text-sm">{{ worker.about }}</p>
                </Card>

                <Card v-if="worker.skills.length">
                    <template #header>
                        <h2 class="text-base">What they can do</h2>
                    </template>
                    <div class="flex flex-wrap gap-2">
                        <Badge v-for="skill in worker.skills" :key="skill" variant="muted">{{ skill }}</Badge>
                    </div>
                </Card>

                <Card v-if="ratings.length">
                    <template #header>
                        <h2 class="text-base">What farms said</h2>
                    </template>

                    <ul class="space-y-4">
                        <li v-for="(rating, index) in ratings" :key="index" class="text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-semibold">
                                    <span class="figures">{{ rating.rating }}</span> / 5
                                    <span class="font-normal text-muted"> · {{ rating.by }}</span>
                                </p>
                                <p class="text-xs text-muted">{{ rating.when }}</p>
                            </div>
                            <p v-if="rating.comment" class="mt-1">{{ rating.comment }}</p>
                            <p v-if="rating.job" class="mt-0.5 text-xs text-muted">{{ rating.job }}</p>
                        </li>
                    </ul>
                </Card>
            </div>

            <div class="mt-5 w-full lg:mt-0 lg:w-80 lg:shrink-0">
                <div class="space-y-5 lg:sticky lg:top-4">
                    <!-- The number, or the reason there is no number. -->
                    <Card>
                        <template #header>
                            <h2 class="text-base">{{ isSelf ? 'Your contact details' : 'Getting in touch' }}</h2>
                        </template>

                        <template v-if="contact">
                            <ul class="space-y-2 text-sm">
                                <li>
                                    <a class="figures text-lg font-bold underline" :href="`tel:${contact.phone}`">
                                        {{ contact.phone }}
                                    </a>
                                </li>
                                <li v-if="contact.whatsapp" class="figures">
                                    {{ contact.whatsapp }} <span class="text-muted">(WhatsApp)</span>
                                </li>
                            </ul>

                            <p v-if="!isSelf" class="mt-3 text-xs text-muted">
                                Agree the pay and the hours before they travel. We are not part of the arrangement.
                            </p>
                            <p v-if="remaining !== null" class="mt-2 text-xs text-muted">
                                {{ remaining }} more today.
                            </p>
                        </template>

                        <template v-else>
                            <p class="text-sm">{{ contactMessage }}</p>

                            <Button
                                v-if="contactReason === 'anonymous'"
                                class="mt-3"
                                :href="route('register')"
                                block
                            >
                                Register as an employer
                            </Button>
                            <Button
                                v-else-if="contactReason === 'not_an_employer'"
                                class="mt-3"
                                :href="route('jobs.employer.edit')"
                                block
                            >
                                Set up an employer profile
                            </Button>
                        </template>
                    </Card>

                    <Card>
                        <template #header>
                            <h2 class="text-base">What they are after</h2>
                        </template>

                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="stencil text-muted">Kind of work</dt>
                                <dd class="mt-0.5">{{ worker.work_type }}</dd>
                            </div>
                            <div v-if="worker.pay_expectation">
                                <dt class="stencil text-muted">Hoping for</dt>
                                <dd class="figures mt-0.5">{{ worker.pay_expectation }}</dd>
                            </div>
                            <div>
                                <dt class="stencil text-muted">Can start</dt>
                                <dd class="mt-0.5">{{ worker.availability }}</dd>
                            </div>
                            <div v-if="worker.rating_count">
                                <dt class="stencil text-muted">Rating</dt>
                                <dd class="figures mt-0.5">
                                    {{ worker.rating_average }} / 5
                                    <span class="text-muted">from {{ worker.rating_count }}</span>
                                </dd>
                            </div>
                        </dl>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
