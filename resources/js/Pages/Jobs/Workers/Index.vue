<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

/**
 * The worker directory, for employers.
 *
 * No phone numbers on this page at all — not hidden ones, not blurred ones.
 * The payload does not contain them. A number is released one worker at a time,
 * on their own page, and every release is counted and recorded.
 */
const props = defineProps({
    workers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
    skills: { type: Array, default: () => [] },
    workTypes: { type: Object, default: () => ({}) },
    availabilities: { type: Object, default: () => ({}) },
    myListings: { type: Array, default: () => [] },
    matchedAgainst: { type: String, default: null },
    allowance: { type: Object, default: () => ({}) },
    notice: { type: Object, required: true },
});

const form = ref({
    state: props.filters.state ?? '',
    skill: props.filters.skill ?? '',
    work_type: props.filters.work_type ?? '',
    availability: props.filters.availability ?? '',
    relocating: props.filters.relocating ?? '',
    listing: props.filters.listing ?? '',
});

function applyFilters() {
    const pruned = Object.fromEntries(
        Object.entries(form.value).filter(([, v]) => v !== '' && v !== null && v !== false),
    );

    router.get(route('jobs.workers.index'), pruned, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="Find workers">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Hiring</p>
        <h1 class="text-2xl sm:text-3xl">Find workers</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            People looking for farm work. Open a profile to see their phone number.
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <!--
            Said plainly rather than sprung on somebody at the limit. An
            employer filling one job will never reach this; somebody working
            through a state to build a call list will hit it by lunchtime.
        -->
        <p v-if="allowance.remaining !== null" class="mt-3 text-sm text-muted">
            You can see
            <strong class="figures">{{ allowance.remaining }}</strong>
            more {{ allowance.remaining === 1 ? "worker's number" : "workers' numbers" }} today, out of
            {{ allowance.limit }}. Workers you have already opened today do not count again.
        </p>

        <hr class="seam seam-chrome my-6" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <aside class="mb-5 w-full lg:mb-0 lg:w-64 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-base">Narrow it down</h2>
                    </template>

                    <div class="space-y-4">
                        <Select
                            v-if="myListings.length"
                            v-model="form.listing"
                            label="Rank for one of my jobs"
                            hint="Sorts by who fits that job best."
                        >
                            <option value="">Do not rank</option>
                            <option v-for="listing in myListings" :key="listing.slug" :value="listing.slug">
                                {{ listing.title }}
                            </option>
                        </Select>

                        <Select v-model="form.state" label="State">
                            <option value="">Anywhere</option>
                            <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                        </Select>

                        <Select v-model="form.skill" label="Skill">
                            <option value="">Any</option>
                            <optgroup v-for="group in skills" :key="group.sector" :label="group.sector">
                                <option v-for="skill in group.skills" :key="skill.id" :value="skill.id">
                                    {{ skill.name }}
                                </option>
                            </optgroup>
                        </Select>

                        <Select v-model="form.work_type" label="Wants">
                            <option value="">Any</option>
                            <option v-for="(label, value) in workTypes" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </Select>

                        <Select v-model="form.availability" label="Can start">
                            <option value="">Any time</option>
                            <option v-for="(label, value) in availabilities" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </Select>

                        <label class="flex cursor-pointer items-start gap-3 text-sm">
                            <input v-model="form.relocating" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                            <span>Willing to relocate only</span>
                        </label>

                        <Button block @click="applyFilters">Show workers</Button>
                    </div>
                </Card>
            </aside>

            <div class="min-w-0 flex-1">
                <p class="mb-4 text-sm text-muted">
                    {{ workers.length }} {{ workers.length === 1 ? 'worker' : 'workers' }}
                    <span v-if="matchedAgainst"> · ranked for {{ matchedAgainst }}</span>
                </p>

                <EmptyState
                    v-if="workers.length === 0"
                    heading="Nobody matches that yet"
                    description="Try a wider search. The directory grows as workers register."
                />

                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <Card v-for="worker in workers" :key="worker.slug">
                        <div class="flex items-start gap-3">
                            <img
                                v-if="worker.photo_url"
                                :src="worker.photo_url"
                                alt=""
                                class="size-12 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                            />
                            <div class="min-w-0">
                                <Link
                                    class="font-bold underline-offset-4 hover:underline"
                                    :href="worker.url"
                                >
                                    {{ worker.name }}
                                </Link>
                                <p class="mt-0.5 text-sm text-muted">
                                    {{ worker.where }} · {{ worker.experience_label }}
                                </p>
                            </div>
                        </div>

                        <p v-if="worker.match_reason" class="mt-2 text-xs text-enamel dark:text-chrome">
                            {{ worker.match_reason }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <Badge :variant="worker.availability_tone">{{ worker.availability }}</Badge>
                            <Badge v-if="worker.willing_to_relocate" variant="info">Will relocate</Badge>
                        </div>

                        <p v-if="worker.skills.length" class="mt-3 text-xs text-muted">
                            {{ worker.skills.slice(0, 4).join(' · ') }}
                        </p>

                        <hr class="seam my-3" />

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p v-if="worker.pay_expectation" class="figures text-sm font-semibold">
                                {{ worker.pay_expectation }}
                            </p>
                            <p v-else class="text-sm text-muted">Open on pay</p>

                            <Button :href="worker.url" variant="secondary" size="sm">Open</Button>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
