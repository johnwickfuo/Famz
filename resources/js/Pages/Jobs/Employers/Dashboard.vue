<script setup>
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

defineProps({
    employer: { type: Object, required: true },
    listings: { type: Array, default: () => [] },
    notice: { type: Object, required: true },
});

function close(slug, outcome) {
    router.post(route('jobs.listings.close', slug), { outcome }, { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="Hiring">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <p class="stencil text-enamel dark:text-chrome">Hiring</p>
                <h1 class="mt-1 text-2xl sm:text-3xl">{{ employer.business_name }}</h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button :href="route('jobs.listings.create')">Post a job</Button>
                <Button variant="secondary" :href="route('jobs.workers.index')">Find workers</Button>
            </div>
        </div>

        <div class="mt-5">
            <BoardNotice :notice="notice" compact />
        </div>

        <hr class="seam seam-chrome my-6" />

        <EmptyState
            v-if="listings.length === 0"
            heading="You have not posted anything yet"
            description="Posting is free. Workers see it as soon as you publish."
        >
            <Button :href="route('jobs.listings.create')">Post a job</Button>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="listing in listings" :key="listing.slug">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <Link class="font-bold underline-offset-4 hover:underline" :href="listing.url">
                            {{ listing.title }}
                        </Link>
                        <p class="mt-0.5 text-sm text-muted">
                            {{ listing.where }} · {{ listing.job_type }}
                            <span v-if="listing.deadline"> · apply by {{ listing.deadline }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!--
                            New applicants first: it is the only number on this
                            row that somebody has to do something about.
                        -->
                        <Badge v-if="listing.new_applications_count" variant="warning">
                            {{ listing.new_applications_count }} new
                        </Badge>
                        <Badge :variant="listing.status_tone" dot>{{ listing.status_label }}</Badge>
                    </div>
                </div>

                <hr class="seam my-3" />

                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <p class="text-sm text-muted">
                        <span class="figures">{{ listing.applications_count }}</span>
                        {{ listing.applications_count === 1 ? 'applicant' : 'applicants' }} ·
                        <span class="figures">{{ listing.views }}</span> views
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <Button :href="listing.applicants_url" variant="secondary" size="sm">See applicants</Button>
                        <Button :href="listing.edit_url" variant="ghost" size="sm">Edit</Button>
                    </div>
                </div>

                <div v-if="listing.is_open" class="mt-3 flex flex-wrap gap-3 text-xs">
                    <button type="button" class="text-muted underline" @click="close(listing.slug, 'filled')">
                        Mark as filled
                    </button>
                    <button type="button" class="text-muted underline" @click="close(listing.slug, 'closed')">
                        Close without hiring
                    </button>
                </div>
            </Card>
        </div>
    </PublicLayout>
</template>
