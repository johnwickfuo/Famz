<script setup>
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

defineProps({
    notifications: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
    unread: { type: Number, default: 0 },
});

function readAll() {
    router.post(route('notifications.readAll'), {}, { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="What has happened">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl">What has happened</h1>
                <p v-if="unread" class="figures mt-1 text-sm text-muted">{{ unread }} not yet read</p>
            </div>

            <Button v-if="unread" size="sm" variant="secondary" @click="readAll">Mark all read</Button>
        </div>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!notifications.length"
            title="Nothing yet"
            description="Offers, answers and anything else that needs you will land here — and in your email."
        >
            <template #action>
                <Button :href="route('catalogue.home')">Browse the market</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-3">
            <Card
                v-for="item in notifications"
                :key="item.id"
                :seam="false"
                :variant="item.read ? 'plain' : 'raised'"
            >
                <component
                    :is="item.url ? Link : 'div'"
                    :href="item.url ? route('notifications.read', item.id) : undefined"
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold leading-snug" :class="item.read ? 'text-muted' : ''">
                            <!-- Unread carries a mark, not just a weight: colour
                                 alone is never the only signal here. -->
                            <span v-if="!item.read" class="mr-1.5 text-chrome-700" aria-hidden="true">●</span>
                            <span v-if="!item.read" class="sr-only">Unread —</span>
                            {{ item.title }}
                        </p>
                        <p v-if="item.body" class="mt-0.5 text-sm text-muted">{{ item.body }}</p>
                    </div>

                    <span class="shrink-0 text-xs text-muted">{{ item.at }}</span>
                </component>
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
