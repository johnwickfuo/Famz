<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';

/**
 * Buying a course.
 *
 * The terms are printed on the page in full rather than hidden behind a link,
 * and the box is not pre-ticked. "All sales are final" is a hard rule here —
 * there is no escrow and no dispute window on a course — so the only fair way
 * to run it is to make sure nobody can say they were not told.
 */
const props = defineProps({
    course: { type: Object, required: true },
    terms: { type: String, required: true },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: null },
});

const form = useForm({
    accept_terms: false,
    gateway: props.gateways.some((g) => g.key === props.defaultGateway)
        ? props.defaultGateway
        : (props.gateways[0]?.key ?? null),
});

const options = computed(() => props.gateways.map((gateway) => ({ value: gateway.key, label: gateway.name })));

function submit() {
    form.post(route('academy.checkout.store', props.course.slug), { preserveScroll: true });
}
</script>

<template>
    <PublicLayout :title="`Buy ${course.title}`">
        <p class="stencil text-muted">Training</p>
        <h1 class="mt-1 text-2xl sm:text-3xl">{{ course.is_free ? 'Enrol' : 'Buy this course' }}</h1>

        <hr class="seam my-5" />

        <form class="lg:flex lg:items-start lg:gap-6" @submit.prevent="submit">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-base">What you are buying</h2>
                    </template>

                    <div class="flex gap-4">
                        <img
                            v-if="course.cover"
                            :src="course.cover"
                            alt=""
                            class="hidden size-24 shrink-0 rounded-sm border-2 border-ink object-cover sm:block dark:border-wash"
                        />

                        <div class="min-w-0">
                            <h3 class="text-base leading-snug">{{ course.title }}</h3>
                            <p v-if="course.summary" class="mt-1 text-sm text-muted">{{ course.summary }}</p>
                            <p class="figures mt-2 text-xs text-muted">
                                {{ course.level }} · {{ course.lesson_count }} lesson{{
                                    course.lesson_count === 1 ? '' : 's'
                                }}<span v-if="course.minutes"> · {{ course.minutes }} min</span>
                            </p>
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-base">Before you pay</h2>
                    </template>

                    <p class="rounded-sm border-2 border-chrome-700 bg-chrome-100 p-3 text-sm dark:bg-grain-800">
                        {{ terms }}
                    </p>

                    <ul class="mt-3 space-y-1 text-sm text-muted">
                        <li>The course is read inside the app. There is nothing to download or keep.</li>
                        <li>Your access does not expire and there is no monthly fee.</li>
                        <li>Handouts carry your name and email on every page.</li>
                    </ul>

                    <label class="mt-4 flex items-start gap-3 text-sm">
                        <input
                            v-model="form.accept_terms"
                            type="checkbox"
                            class="mt-0.5 size-5 shrink-0 accent-enamel"
                            :aria-invalid="form.errors.accept_terms ? 'true' : undefined"
                        />
                        <span>I have read the above and understand that all sales are final.</span>
                    </label>

                    <p v-if="form.errors.accept_terms" class="mt-2 text-sm text-cockscomb dark:text-cockscomb-300">
                        {{ form.errors.accept_terms }}
                    </p>
                </Card>

                <Card v-if="!course.is_free">
                    <template #header>
                        <h2 class="text-base">How you are paying</h2>
                    </template>

                    <!-- No gateway has been given keys yet. Saying so beats an
                         empty box and a button that fails validation. -->
                    <p v-if="!gateways.length" class="text-sm font-semibold text-cockscomb dark:text-cockscomb-300">
                        No payment provider has been set up yet, so this course cannot be bought at the moment. Please
                        try again shortly.
                    </p>

                    <template v-else>
                        <Select
                            v-model="form.gateway"
                            label="Payment method"
                            :options="options"
                            :error="form.errors.gateway"
                        />

                        <p class="mt-2 text-xs text-muted">
                            You will be taken to the payment page and brought back here when it is done.
                        </p>
                    </template>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">Total</h2>
                    </template>

                    <p class="figures text-3xl font-bold">
                        <span v-if="course.is_free" class="text-enamel dark:text-enamel-200">Free</span>
                        <span v-else>{{ course.price }}</span>
                    </p>

                    <p class="mt-1 text-xs text-muted">No delivery, no extras. This is the whole price.</p>

                    <Button
                        class="mt-4"
                        type="submit"
                        block
                        :loading="form.processing"
                        :disabled="!form.accept_terms || (!course.is_free && !gateways.length)"
                    >
                        {{ course.is_free ? 'Enrol now' : `Pay ${course.price}` }}
                    </Button>
                </Card>
            </aside>
        </form>
    </PublicLayout>
</template>
