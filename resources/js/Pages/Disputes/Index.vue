<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

defineProps({
    disputes: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
});

const tone = {
    open: 'pending',
    under_review: 'pending',
    resolved_buyer: 'active',
    resolved_seller: 'active',
    resolved_partial: 'active',
    closed: 'neutral',
};
</script>

<template>
    <PublicLayout title="Problems you have reported">
        <h1 class="text-2xl sm:text-3xl">Problems you have reported</h1>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!disputes.length"
            title="Nothing reported"
            description="If something arrives wrong, short or not at all, report it from the order and we will hold the seller's money until it is sorted."
        >
            <template #action>
                <Button :href="route('orders.index')">Your orders</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="dispute in disputes" :key="dispute.id" :seam="false">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold">
                            <Link :href="route('disputes.show', dispute.id)" class="underline-offset-4 hover:underline">
                                {{ dispute.reason }}
                            </Link>
                        </h2>
                        <p class="mt-1 text-sm text-muted">
                            {{ dispute.seller }} · <span class="figures">{{ dispute.reference }}</span> ·
                            {{ dispute.raised_at }}
                        </p>
                        <p v-if="dispute.refunded" class="figures mt-1 text-sm font-semibold text-enamel dark:text-chrome">
                            {{ dispute.refunded }} refunded
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span class="figures text-sm font-bold">{{ dispute.amount }}</span>
                        <Badge :variant="tone[dispute.status] ?? 'neutral'" dot>{{ dispute.status_label }}</Badge>
                    </div>
                </div>
            </Card>

            <Pagination
                :links="pagination.links"
                :from="pagination.from"
                :to="pagination.to"
                :total="pagination.total"
            />
        </div>
    </PublicLayout>
</template>
