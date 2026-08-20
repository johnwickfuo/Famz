<script setup>
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

const props = defineProps({
    notifications: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
    unread: { type: Number, default: 0 },
    categories: { type: Array, default: () => [] },
    filter: { type: String, default: null },
});

/**
 * Filtering reloads rather than hiding rows client-side: the list is paginated,
 * so a client-side filter would only ever filter the twenty rows on screen and
 * quietly lie about the rest.
 */
function filterBy(category) {
    router.get(
        route('notifications.index'),
        category ? { category } : {},
        { preserveScroll: true, preserveState: true },
    );
}

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

        <!--
            Only the categories this person actually has something in. A bar
            offering eleven filters where nine come back empty is worse than
            no bar at all.
        -->
        <div v-if="categories.length > 1" class="mb-5 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-sm border-2 px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                :class="!filter ? 'border-ink bg-chrome text-ink' : 'border-chrome text-muted hover:border-ink dark:border-grain-700'"
                @click="filterBy(null)"
            >
                Everything
            </button>
            <button
                v-for="category in categories"
                :key="category.value"
                type="button"
                class="rounded-sm border-2 px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                :class="filter === category.value ? 'border-ink bg-chrome text-ink' : 'border-chrome text-muted hover:border-ink dark:border-grain-700'"
                @click="filterBy(category.value)"
            >
                {{ category.label }}
                <span v-if="category.unread" class="figures ml-1">({{ category.unread }})</span>
            </button>
        </div>

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
                        <!--
                            Shown only in the unfiltered list. Inside a filtered
                            view every row carries the same label, which is
                            noise repeating what the active filter already says.
                        -->
                        <p v-if="item.categoryLabel && !filter" class="mt-1 text-2xs uppercase tracking-wider text-muted">
                            {{ item.categoryLabel }}
                        </p>
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
