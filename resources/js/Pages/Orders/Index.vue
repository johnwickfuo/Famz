<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import { orderTone } from '@/Support/statusTone';

defineProps({
    orders: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
});
</script>

<template>
    <PublicLayout title="Your orders">
        <h1 class="text-2xl sm:text-3xl">Your orders</h1>
        <p v-if="pagination.total" class="figures mt-1 text-sm text-muted">
            {{ pagination.total }} order{{ pagination.total === 1 ? '' : 's' }}
        </p>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!orders.length"
            title="You have not ordered anything yet"
            description="Anything you buy shows up here, with where each seller has got to."
        >
            <template #action>
                <Button :href="route('catalogue.home')">Browse the market</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="order in orders" :key="order.reference" :seam="false">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 class="figures text-base font-semibold">
                            <Link
                                :href="route('orders.show', order.reference)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ order.reference }}
                            </Link>
                        </h2>
                        <p class="mt-1 text-sm text-muted">
                            Placed {{ order.placed_at }} ·
                            <span class="figures">{{ order.item_count }}</span>
                            item{{ order.item_count === 1 ? '' : 's' }} from
                            <span class="figures">{{ order.seller_count }}</span>
                            seller{{ order.seller_count === 1 ? '' : 's' }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span class="figures text-lg font-bold">{{ order.grand_total }}</span>
                        <Badge :variant="orderTone(order.status)" dot>{{ order.status_label }}</Badge>
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
