<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import OfferStatusBadge from '@/Components/Offers/OfferStatusBadge.vue';

defineProps({
    requests: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
});
</script>

<template>
    <PublicLayout title="What I am looking for">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-2xl sm:text-3xl">What I am looking for</h1>
            <Button :href="route('requests.create')" size="sm">Post another</Button>
        </div>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!requests.length"
            title="You have not asked for anything yet"
            description="Rather than hunting through listings, say what you need and let sellers come to you with prices."
        >
            <template #action>
                <Button :href="route('requests.create')">Post what you need</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="item in requests" :key="item.slug" :seam="false">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-semibold leading-snug">
                            <Link
                                :href="route('requests.manage', item.slug)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ item.title }}
                            </Link>
                        </h2>
                        <p class="mt-1 text-sm text-muted">
                            <span class="figures">{{ item.quantity }}</span> {{ item.unit }} · {{ item.location }}
                        </p>
                        <p
                            v-if="item.rejection_reason"
                            class="mt-1 text-sm font-semibold text-cockscomb dark:text-cockscomb-300"
                        >
                            {{ item.rejection_reason }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <OfferStatusBadge :status="item.status" :label="item.status_label" />
                        <span
                            class="figures text-sm"
                            :class="item.live_offer_count ? 'font-bold text-enamel dark:text-chrome' : 'text-muted'"
                        >
                            {{ item.live_offer_count }} waiting
                        </span>
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
