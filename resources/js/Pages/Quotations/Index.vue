<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

/**
 * Everything this person has asked us to price.
 *
 * A request list rather than a project list. Once a proposal is accepted the
 * build happens between the client and the company, not in here — so the last
 * thing any of these rows ever says is "going ahead".
 */
defineProps({
    requests: { type: Array, default: () => [] },
    studyFee: { type: String, default: null },
});
</script>

<template>
    <PublicLayout title="My farm setup requests">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <p class="stencil text-enamel dark:text-chrome">Farm setup</p>
                <h1 class="mt-1 text-2xl sm:text-3xl">My requests</h1>
            </div>

            <Button :href="route('quotations.create')">Ask for a new quotation</Button>
        </div>

        <hr class="seam seam-chrome my-6" />

        <EmptyState
            v-if="requests.length === 0"
            heading="Nothing here yet"
            :description="`Tell us what you want to build and we will cost it properly. The study fee is ${studyFee}.`"
        >
            <Button :href="route('quotations.create')">Get a quotation</Button>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="item in requests" :key="item.reference">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <p class="figures text-lg font-bold">{{ item.reference }}</p>
                        <p class="mt-0.5 text-sm text-muted">
                            {{ item.project_type }} · {{ item.farm_type }} · asked {{ item.submitted_at }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!--
                            The call to action, ahead of the status badge:
                            somebody scanning this needs to see what is owed
                            before they see what it is called.
                        -->
                        <Badge v-if="item.awaits_study_fee" variant="warning" dot>Study fee to pay</Badge>
                        <Badge v-else-if="item.has_quotation && item.is_live" variant="success" dot>
                            Proposal ready
                        </Badge>
                        <Badge :variant="item.tone" dot>{{ item.status_label }}</Badge>
                    </div>
                </div>

                <hr class="seam my-4" />

                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <div v-if="item.has_quotation" class="min-w-0">
                        <p class="figures text-xl font-bold">{{ item.quotation_total }}</p>
                        <p class="mt-0.5 text-xs text-muted">
                            <template v-if="item.is_live">
                                Valid until {{ item.valid_until }} · {{ item.validity_countdown }}
                            </template>
                            <template v-else>{{ item.validity_countdown }}</template>
                        </p>
                    </div>
                    <p v-else class="text-sm text-muted">
                        <template v-if="item.awaits_study_fee">
                            We start costing this once the study fee clears.
                        </template>
                        <template v-else>We are costing this now.</template>
                    </p>

                    <Button :href="item.url" variant="secondary">Open</Button>
                </div>
            </Card>
        </div>
    </PublicLayout>
</template>
