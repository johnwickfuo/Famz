<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

/**
 * The job board.
 *
 * Free and open. A signed-in worker sees the same jobs in a better order —
 * never a smaller set, because filtering somebody out of work they might want
 * is not the platform's decision to make.
 */
const props = defineProps({
    listings: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
    jobTypes: { type: Object, default: () => ({}) },
    skills: { type: Array, default: () => [] },
    isRanked: { type: Boolean, default: false },
    hasWorkerProfile: { type: Boolean, default: false },
    notice: { type: Object, required: true },
});

const form = ref({
    q: props.filters.q ?? '',
    state: props.filters.state ?? '',
    job_type: props.filters.job_type ?? '',
    skill: props.filters.skill ?? '',
    pay_min: props.filters.pay_min ?? '',
});

const hasFilters = computed(() => Object.values(form.value).some((value) => value !== '' && value !== null));

function applyFilters() {
    router.get(route('jobs.index'), pruned(form.value), { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    form.value = { q: '', state: '', job_type: '', skill: '', pay_min: '' };
    router.get(route('jobs.index'));
}

function pruned(values) {
    return Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null));
}
</script>

<template>
    <PublicLayout title="Farm jobs">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Farm jobs</p>
        <h1 class="text-2xl sm:text-3xl">Work on farms near you</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Free to look, free to apply. You deal with the farm directly.
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <hr class="seam seam-chrome my-6" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <!-- Filters -->
            <aside class="mb-5 w-full lg:mb-0 lg:w-64 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">Narrow it down</h2>
                    </template>

                    <div class="space-y-4">
                        <Input v-model="form.q" label="Search" placeholder="Poultry, security…" />

                        <Select v-model="form.state" label="State">
                            <option value="">Anywhere</option>
                            <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                        </Select>

                        <Select v-model="form.job_type" label="Kind of work">
                            <option value="">Any</option>
                            <option v-for="(label, value) in jobTypes" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </Select>

                        <Select v-model="form.skill" label="Skill">
                            <option value="">Any</option>
                            <optgroup v-for="group in skills" :key="group.sector" :label="group.sector">
                                <option v-for="skill in group.skills" :key="skill.id" :value="skill.id">
                                    {{ skill.name }}
                                </option>
                            </optgroup>
                        </Select>

                        <Input
                            v-model="form.pay_min"
                            label="Pays at least"
                            type="number"
                            min="0"
                            figures
                            prefix="₦"
                            hint="A month. Daily jobs are compared fairly."
                        />

                        <Button block @click="applyFilters">Show jobs</Button>
                        <Button v-if="hasFilters" variant="ghost" block @click="clearFilters">Clear</Button>
                    </div>
                </Card>
            </aside>

            <div class="min-w-0 flex-1">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-muted">
                        {{ listings.length }} {{ listings.length === 1 ? 'job' : 'jobs' }}
                        <span v-if="isRanked"> · sorted for you</span>
                    </p>

                    <Link v-if="!hasWorkerProfile" class="text-sm underline" :href="route('jobs.worker.edit')">
                        Set up a worker profile
                    </Link>
                </div>

                <EmptyState
                    v-if="listings.length === 0"
                    heading="No jobs match that"
                    description="Try a wider search, or check back — the board changes as farms post work."
                />

                <div v-else class="space-y-4">
                    <Card v-for="listing in listings" :key="listing.slug">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                            <div class="min-w-0">
                                <Link class="text-lg font-bold underline-offset-4 hover:underline" :href="listing.url">
                                    {{ listing.title }}
                                </Link>
                                <p class="mt-0.5 text-sm text-muted">
                                    {{ listing.employer_name }}<span v-if="listing.where"> · {{ listing.where }}</span>
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="listing.job_type_tone">{{ listing.job_type }}</Badge>
                                <Badge v-if="listing.deadline_countdown" variant="muted">
                                    {{ listing.deadline_countdown }}
                                </Badge>
                            </div>
                        </div>

                        <p v-if="listing.match_reason" class="mt-2 text-xs text-enamel dark:text-chrome">
                            {{ listing.match_reason }}
                        </p>

                        <hr class="seam my-3" />

                        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                            <div class="min-w-0">
                                <p v-if="listing.pay" class="figures font-semibold">{{ listing.pay }}</p>
                                <p v-else class="text-sm text-muted">Pay not stated</p>

                                <p v-if="listing.perks.length" class="mt-0.5 text-xs text-muted">
                                    {{ listing.perks.join(' · ') }}
                                </p>
                            </div>

                            <Button :href="listing.url" variant="secondary">See the job</Button>
                        </div>

                        <p v-if="listing.skills.length" class="mt-3 text-xs text-muted">
                            {{ listing.skills.join(' · ') }}
                        </p>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
