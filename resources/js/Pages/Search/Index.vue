<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * Results across the whole platform, grouped by what kind of thing they are.
 *
 * Grouped rather than interleaved by relevance. A mixed list of feed bags,
 * courses and job adverts sorted by a score nobody can see is harder to scan
 * than five short labelled lists, and the groups are what tell somebody the
 * platform has a training section at all.
 */
const props = defineProps({
    term: { type: String, default: '' },
    tooShort: { type: Boolean, default: false },
    groups: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    type: { type: String, default: null },
    types: { type: Array, default: () => [] },
    minLength: { type: Number, default: 2 },
});

const search = ref(props.term);

function submit() {
    router.get(route('search'), pruned({ q: search.value, type: props.type }));
}

function filterType(value) {
    router.get(route('search'), pruned({ q: props.term, type: value }), { preserveScroll: true });
}

function pruned(values) {
    return Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null));
}
</script>

<template>
    <PublicLayout :title="term ? `Search: ${term}` : 'Search'">
        <div class="mx-auto w-full max-w-3xl">
            <h1 class="text-2xl sm:text-3xl">Search</h1>

            <form class="mt-4 flex gap-2" @submit.prevent="submit">
                <label for="search-term" class="sr-only">What are you looking for?</label>
                <input
                    id="search-term"
                    v-model="search"
                    type="search"
                    placeholder="Feed, cages, a course, a mentor, a job…"
                    class="w-full rounded-sm border-2 border-ink bg-surface-raised px-3 py-2.5 text-base dark:border-wash dark:bg-grain-800"
                />
                <Button type="submit" class="shrink-0">Search</Button>
            </form>

            <!-- Type filters, shown once there is something to filter. -->
            <div v-if="term && !tooShort" class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-sm border-2 px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                    :class="!type ? 'border-ink bg-chrome text-ink' : 'border-chrome text-muted hover:border-ink dark:border-grain-700'"
                    @click="filterType(null)"
                >
                    Everything
                </button>
                <button
                    v-for="option in types"
                    :key="option.value"
                    type="button"
                    class="rounded-sm border-2 px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                    :class="type === option.value ? 'border-ink bg-chrome text-ink' : 'border-chrome text-muted hover:border-ink dark:border-grain-700'"
                    @click="filterType(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>

            <hr class="seam seam-chrome my-6" />

            <p v-if="tooShort && term" class="text-sm text-muted">
                Type at least {{ minLength }} characters.
            </p>

            <EmptyState
                v-else-if="term && !groups.length"
                title="Nothing found"
                :description="`We could not find anything matching “${term}”. Try a shorter or more general word.`"
            >
                <template #action>
                    <Button :href="route('catalogue.home')">Browse the market</Button>
                </template>
            </EmptyState>

            <div v-else-if="!term" class="text-sm text-muted">
                Search across the market, training, mentors, farm jobs and buying requests.
            </div>

            <div v-else class="space-y-8">
                <p class="figures text-sm text-muted">
                    {{ total }} result{{ total === 1 ? '' : 's' }} for “{{ term }}”
                </p>

                <section v-for="group in groups" :key="group.key">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-xl">
                            {{ group.label }}
                            <span class="figures text-sm font-normal text-muted">({{ group.total }})</span>
                        </h2>
                        <Link
                            v-if="group.total > group.rows.length"
                            :href="group.allHref"
                            class="text-xs font-bold uppercase tracking-wider underline underline-offset-4 text-muted hover:text-ink dark:hover:text-wash"
                        >
                            See all
                        </Link>
                    </div>

                    <div class="mt-3 space-y-2">
                        <Card v-for="(row, index) in group.rows" :key="`${group.key}-${index}`" :seam="false">
                            <Link :href="row.href" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold">{{ row.title }}</p>
                                    <p v-if="row.meta" class="mt-0.5 line-clamp-2 text-sm text-muted">{{ row.meta }}</p>
                                </div>
                                <span v-if="row.amount" class="figures shrink-0 text-sm font-semibold">
                                    {{ row.amount }}
                                </span>
                            </Link>
                        </Card>
                    </div>
                </section>
            </div>
        </div>
    </PublicLayout>
</template>
