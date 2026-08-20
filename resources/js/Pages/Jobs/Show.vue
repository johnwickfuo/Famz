<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

const props = defineProps({
    listing: { type: Object, required: true },
    employer: { type: Object, default: null },
    application: { type: Object, default: null },
    canApply: { type: Boolean, default: false },
    needsWorkerProfile: { type: Boolean, default: false },
    notice: { type: Object, required: true },
});

const showForm = ref(false);
const form = useForm({ cover_message: '' });

function apply() {
    form.post(route('jobs.apply', props.listing.slug), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
}
</script>

<template>
    <PublicLayout :title="listing.title">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="stencil text-enamel dark:text-chrome">Farm job</p>
                <h1 class="mt-1 text-2xl sm:text-3xl">{{ listing.title }}</h1>
                <p class="mt-1 text-sm text-muted">
                    {{ listing.employer_name }}<span v-if="listing.where"> · {{ listing.where }}</span>
                    <span v-if="listing.posted"> · posted {{ listing.posted }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="listing.job_type_tone">{{ listing.job_type }}</Badge>
                <Badge :variant="listing.status_tone" dot>{{ listing.status_label }}</Badge>
            </div>
        </div>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <hr class="seam seam-chrome my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-base">The work</h2>
                    </template>

                    <p class="whitespace-pre-line text-sm">{{ listing.description }}</p>

                    <div v-if="listing.skills.length" class="mt-4">
                        <p class="stencil text-muted">What they need</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Badge v-for="skill in listing.skills" :key="skill" variant="muted">{{ skill }}</Badge>
                        </div>
                    </div>
                </Card>

                <!-- Applying. -->
                <Card v-if="canApply">
                    <template #header>
                        <h2 class="text-base">Apply</h2>
                    </template>

                    <p v-if="application" class="text-sm">
                        You applied on {{ application.applied_at }}.
                        <strong>{{ application.status_label }}.</strong>
                    </p>

                    <template v-else-if="showForm">
                        <Textarea
                            v-model="form.cover_message"
                            label="Anything you want to say"
                            :rows="4"
                            hint="Optional. What you have done before, when you can start."
                            :error="form.errors.cover_message"
                        />
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button :disabled="form.processing" @click="apply">
                                {{ form.processing ? 'Sending…' : 'Send my application' }}
                            </Button>
                            <Button variant="ghost" @click="showForm = false">Cancel</Button>
                        </div>
                    </template>

                    <template v-else>
                        <p class="text-sm">
                            The farm sees your profile and your phone number when you apply. They contact you directly —
                            we are not involved after that.
                        </p>
                        <Button class="mt-3" @click="showForm = true">Apply for this job</Button>
                    </template>
                </Card>

                <Card v-else-if="application">
                    <template #header>
                        <h2 class="text-base">Your application</h2>
                    </template>
                    <p class="text-sm">
                        Sent on {{ application.applied_at }}. <strong>{{ application.status_label }}.</strong>
                    </p>
                </Card>

                <Card v-else-if="needsWorkerProfile">
                    <template #header>
                        <h2 class="text-base">Apply</h2>
                    </template>
                    <p class="text-sm">Set up a worker profile first — it takes a couple of minutes.</p>
                    <Button class="mt-3" :href="route('jobs.worker.edit')">Set up my profile</Button>
                </Card>

                <Card v-else-if="!listing.is_open">
                    <template #header>
                        <h2 class="text-base">This one is closed</h2>
                    </template>
                    <p class="text-sm">It is no longer taking applications. There are others on the board.</p>
                    <Button class="mt-3" variant="secondary" :href="route('jobs.index')">Back to the board</Button>
                </Card>

                <Card v-else>
                    <template #header>
                        <h2 class="text-base">Apply</h2>
                    </template>
                    <p class="text-sm">Sign in as a worker to apply for this job. It is free.</p>
                    <Button class="mt-3" :href="route('login')">Sign in</Button>
                </Card>
            </div>

            <div class="mt-5 w-full lg:mt-0 lg:w-80 lg:shrink-0">
                <div class="space-y-5 lg:sticky lg:top-4">
                    <Card>
                        <template #header>
                            <h2 class="text-base">The offer</h2>
                        </template>

                        <p v-if="listing.pay" class="figures text-xl font-bold">{{ listing.pay }}</p>
                        <p v-else class="text-sm text-muted">Pay not stated — ask them.</p>

                        <ul v-if="listing.perks.length" class="mt-3 space-y-1 text-sm">
                            <li v-for="perk in listing.perks" :key="perk">✓ {{ perk }}</li>
                        </ul>

                        <hr class="seam my-4" />

                        <dl class="space-y-3 text-sm">
                            <div v-if="listing.where">
                                <dt class="stencil text-muted">Where</dt>
                                <dd class="mt-0.5">{{ listing.where }}</dd>
                            </div>
                            <div>
                                <dt class="stencil text-muted">Positions</dt>
                                <dd class="figures mt-0.5">{{ listing.positions }}</dd>
                            </div>
                            <div v-if="listing.start_date">
                                <dt class="stencil text-muted">Starts</dt>
                                <dd class="mt-0.5">{{ listing.start_date }}</dd>
                            </div>
                            <div v-if="listing.deadline">
                                <dt class="stencil text-muted">Apply by</dt>
                                <dd class="mt-0.5">
                                    {{ listing.deadline }}
                                    <span class="text-muted">· {{ listing.deadline_countdown }}</span>
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- The employer's own details are public by design. -->
                    <Card v-if="employer">
                        <template #header>
                            <h2 class="text-base">Who is hiring</h2>
                        </template>

                        <p class="font-semibold">{{ employer.business_name }}</p>
                        <p v-if="employer.business_type" class="text-sm text-muted">{{ employer.business_type }}</p>
                        <p v-if="employer.where" class="text-sm text-muted">{{ employer.where }}</p>

                        <p v-if="employer.about" class="mt-3 text-sm">{{ employer.about }}</p>

                        <div v-if="employer.rating_count" class="mt-3 text-sm">
                            <span class="figures font-semibold">{{ employer.rating_average }}</span>
                            <span class="text-muted"> from {{ employer.rating_count }} workers</span>
                        </div>

                        <hr class="seam my-4" />

                        <ul class="space-y-1 text-sm">
                            <li v-if="employer.contact_person">{{ employer.contact_person }}</li>
                            <li v-if="employer.phone">
                                <a class="figures underline" :href="`tel:${employer.phone}`">{{ employer.phone }}</a>
                            </li>
                            <li v-if="employer.email">
                                <a class="break-all underline" :href="`mailto:${employer.email}`">
                                    {{ employer.email }}
                                </a>
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
