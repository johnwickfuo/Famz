<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * The signed-in home page.
 *
 * Sections arrive already filtered by the server — a section that is not
 * relevant is not in the array, rather than being rendered and hidden. That
 * matters for more than tidiness: a hidden section still costs the queries that
 * built it, on the page most likely to be opened on mobile data.
 */
defineProps({
    greeting: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
});

/**
 * Where somebody with nothing yet should go first. Shown only when every
 * section came back empty, which is the genuine first-run state.
 */
const startingPoints = [
    { label: 'Browse the market', route: 'catalogue.home' },
    { label: 'Ask the assistant', route: 'assistant.show' },
    { label: 'Find training', route: 'academy.home' },
    { label: 'Find a mentor', route: 'mentors.find' },
];
</script>

<template>
    <AppLayout title="Your dashboard">
        <div class="mx-auto w-full max-w-4xl">
            <p class="stencil mb-2 text-enamel dark:text-chrome">{{ greeting.part }}</p>
            <h1 class="text-2xl sm:text-3xl">{{ greeting.name }}</h1>

            <hr class="seam seam-chrome my-6" />

            <!-- Nothing yet: point somewhere useful rather than showing a void. -->
            <div v-if="!sections.length">
                <h2 class="text-xl">Nothing here yet</h2>
                <p class="mt-2 max-w-xl text-sm text-muted">
                    Once you buy something, enrol on a course, book a consultation or apply for a job,
                    it will show up here.
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <Button
                        v-for="point in startingPoints"
                        :key="point.route"
                        :href="route(point.route)"
                        variant="secondary"
                        size="sm"
                    >
                        {{ point.label }}
                    </Button>
                </div>
            </div>

            <div v-else class="space-y-8">
                <section v-for="section in sections" :key="section.key">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-xl">{{ section.title }}</h2>
                        <Link
                            :href="section.href"
                            class="text-xs font-bold uppercase tracking-wider underline underline-offset-4 text-muted hover:text-ink dark:hover:text-wash"
                        >
                            {{ section.linkLabel }}
                        </Link>
                    </div>

                    <p v-if="!section.rows.length" class="mt-2 text-sm text-muted">
                        {{ section.empty }}
                    </p>

                    <div v-else class="mt-3 space-y-2">
                        <Card
                            v-for="(row, index) in section.rows"
                            :key="`${section.key}-${index}`"
                            :seam="false"
                        >
                            <Link :href="row.href" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold">{{ row.title }}</p>
                                    <p v-if="row.meta" class="mt-0.5 truncate text-sm text-muted">{{ row.meta }}</p>
                                </div>

                                <div class="flex shrink-0 items-center gap-3">
                                    <span v-if="row.amount" class="figures text-sm font-semibold">{{ row.amount }}</span>
                                    <Badge v-if="row.status" variant="chrome">{{ row.status }}</Badge>
                                </div>
                            </Link>
                        </Card>
                    </div>
                </section>
            </div>

            <hr class="seam seam-chrome my-8" />

            <p class="text-sm text-muted">
                Change your details, bank account or what we email you about in
                <Link :href="route('account.edit')" class="underline underline-offset-4">your account</Link>.
            </p>
        </div>
    </AppLayout>
</template>
