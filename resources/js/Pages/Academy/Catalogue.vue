<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import CourseCard from '@/Components/Academy/CourseCard.vue';

const props = defineProps({
    courses: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ links: [], total: 0 }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
});

const term = ref(props.filters.q ?? '');
const category = ref(props.filters.category ?? '');
const level = ref(props.filters.level ?? '');
const freeOnly = ref(props.filters.free_only ?? false);
const sort = ref(props.filters.sort ?? 'newest');

function apply() {
    router.get(
        route('academy.catalogue'),
        {
            q: term.value || undefined,
            category: category.value || undefined,
            level: level.value || undefined,
            free_only: freeOnly.value ? 1 : undefined,
            sort: sort.value === 'newest' ? undefined : sort.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// The typed term is left out on purpose: re-querying on every keystroke over a
// patchy connection is a queue of requests nobody asked for. It goes on submit.
watch([category, level, freeOnly, sort], apply);

function clear() {
    term.value = '';
    category.value = '';
    level.value = '';
    freeOnly.value = false;
    sort.value = 'newest';
}
</script>

<template>
    <PublicLayout title="Courses">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="stencil mb-1 text-enamel dark:text-chrome">Training</p>
                <h1 class="text-2xl sm:text-3xl">Courses</h1>
            </div>
            <Button :href="route('academy.mine')" variant="secondary" size="sm">My courses</Button>
        </div>

        <hr class="seam my-5" />

        <div class="lg:flex lg:gap-6">
            <aside class="lg:w-64 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">Narrow it down</h2>
                    </template>

                    <form class="space-y-4" @submit.prevent="apply">
                        <Input v-model="term" label="Search" placeholder="Brooding, feed, records…" />

                        <Select
                            v-model="category"
                            label="Subject"
                            :options="[{ value: '', label: 'Everything' }, ...categories]"
                        />

                        <Select
                            v-model="level"
                            label="Level"
                            :options="[{ value: '', label: 'Any level' }, ...levels]"
                        />

                        <Select
                            v-model="sort"
                            label="Order by"
                            :options="[
                                { value: 'newest', label: 'Newest first' },
                                { value: 'popular', label: 'Most enrolled' },
                            ]"
                        />

                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="freeOnly" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                            <span>Free courses only</span>
                        </label>

                        <Button type="submit" size="sm" block>Search</Button>

                        <button
                            v-if="term || category || level || freeOnly || sort !== 'newest'"
                            type="button"
                            class="stencil text-cockscomb underline underline-offset-4"
                            @click="clear"
                        >
                            Clear filters
                        </button>
                    </form>
                </Card>
            </aside>

            <div class="mt-6 min-w-0 flex-1 lg:mt-0">
                <p v-if="pagination.total" class="figures mb-4 text-sm text-muted">
                    {{ pagination.total }} course{{ pagination.total === 1 ? '' : 's' }}
                </p>

                <EmptyState
                    v-if="!courses.length"
                    title="Nothing matches that"
                    description="Try a wider search, or drop one of the filters."
                >
                    <template #action>
                        <Button @click="clear">Clear filters</Button>
                    </template>
                </EmptyState>

                <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <CourseCard v-for="course in courses" :key="course.slug" :course="course" />
                </div>

                <Pagination
                    v-if="courses.length"
                    class="mt-6"
                    :links="pagination.links"
                    :from="pagination.from"
                    :to="pagination.to"
                    :total="pagination.total"
                />
            </div>
        </div>
    </PublicLayout>
</template>
