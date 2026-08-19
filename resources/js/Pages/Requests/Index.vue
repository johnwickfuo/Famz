<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import OfferStatusBadge from '@/Components/Offers/OfferStatusBadge.vue';

const props = defineProps({
    requests: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
});

const category = ref(props.filters.category ?? '');
const state = ref(props.filters.state ?? '');
const openOnly = ref(props.filters.open_only ?? true);

function apply() {
    router.get(
        route('requests.index'),
        {
            category: category.value || undefined,
            state: state.value || undefined,
            open_only: openOnly.value ? undefined : 0,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([category, state, openOnly], apply);

function clear() {
    category.value = '';
    state.value = '';
    openOnly.value = true;
}
</script>

<template>
    <PublicLayout title="What people are looking for">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl sm:text-3xl">What people are looking for</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted">
                    Buyers post what they need and sellers come to them. If you stock it, say your price.
                </p>
            </div>

            <Button :href="route('requests.create')" size="sm">Post what you need</Button>
        </div>

        <hr class="seam my-5" />

        <div class="lg:flex lg:gap-6">
            <aside class="lg:w-64 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">Narrow it down</h2>
                    </template>

                    <div class="space-y-4">
                        <Select
                            v-model="category"
                            label="Category"
                            :options="[{ value: '', label: 'Everything' }, ...categories]"
                        />
                        <Select
                            v-model="state"
                            label="Delivered to"
                            :options="[{ value: '', label: 'Anywhere' }, ...states.map((s) => ({ value: s, label: s }))]"
                        />

                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="openOnly" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                            <span>Only ones still taking offers</span>
                        </label>

                        <button
                            v-if="category || state || !openOnly"
                            type="button"
                            class="stencil text-cockscomb underline underline-offset-4"
                            @click="clear"
                        >
                            Clear filters
                        </button>
                    </div>
                </Card>
            </aside>

            <div class="mt-6 min-w-0 flex-1 lg:mt-0">
                <p v-if="pagination.total" class="figures mb-4 text-sm text-muted">
                    {{ pagination.total }} request{{ pagination.total === 1 ? '' : 's' }}
                </p>

                <EmptyState
                    v-if="!requests.length"
                    title="Nothing here right now"
                    description="Nobody is asking for anything in this corner of the market yet. Try a wider filter, or post what you are looking for."
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
                                        :href="route('requests.show', item.slug)"
                                        class="underline-offset-4 hover:underline"
                                    >
                                        {{ item.title }}
                                    </Link>
                                </h2>

                                <p class="mt-1 text-sm text-muted">
                                    <span class="figures">{{ item.quantity }}</span> {{ item.unit }} ·
                                    {{ item.location }}
                                    <span v-if="item.category"> · {{ item.category }}</span>
                                </p>

                                <p class="mt-1 text-xs text-muted">Posted {{ item.posted_at }}</p>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <span v-if="item.budget" class="figures text-sm font-bold">{{ item.budget }}</span>
                                <OfferStatusBadge :status="item.status" :label="item.status_label" />
                                <!--
                                    How many, never how much. A board showing the
                                    best price so far is a board where everybody
                                    shaves a naira off it and nobody bids their
                                    real number.
                                -->
                                <span class="figures text-xs text-muted">
                                    {{ item.offer_count }} offer{{ item.offer_count === 1 ? '' : 's' }}
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
            </div>
        </div>
    </PublicLayout>
</template>
