<script setup>
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

const props = defineProps({
    listing: { type: Object, default: null },
    skills: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    jobTypes: { type: Array, default: () => [] },
    payPeriods: { type: Object, default: () => ({}) },
    notice: { type: Object, required: true },
});

const form = useForm({
    title: props.listing?.title ?? '',
    description: props.listing?.description ?? '',
    job_type: props.listing?.job_type ?? 'permanent',
    positions_available: props.listing?.positions_available ?? 1,
    state: props.listing?.state ?? '',
    lga: props.listing?.lga ?? '',
    is_accommodation_provided: props.listing?.is_accommodation_provided ?? false,
    is_food_provided: props.listing?.is_food_provided ?? false,
    pay_min: props.listing?.pay_min ?? '',
    pay_max: props.listing?.pay_max ?? '',
    pay_period: props.listing?.pay_period ?? 'monthly',
    start_date: props.listing?.start_date ?? '',
    application_deadline: props.listing?.application_deadline ?? '',
    skill_ids: props.listing?.skill_ids ?? [],
    publish: false,
});

function toggleSkill(id) {
    form.skill_ids = form.skill_ids.includes(id)
        ? form.skill_ids.filter((s) => s !== id)
        : [...form.skill_ids, id];
}

function submit(publish) {
    form.publish = publish;
    form.post(
        props.listing
            ? route('jobs.listings.update', props.listing.slug)
            : route('jobs.listings.store'),
    );
}
</script>

<template>
    <PublicLayout title="Post a job">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Hiring</p>
        <h1 class="text-2xl sm:text-3xl">{{ listing ? 'Edit this job' : 'Post a job' }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Free to post. The more precise you are about the work and the pay, the fewer wasted journeys for everybody.
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" compact />
        </div>

        <hr class="seam seam-chrome my-6" />

        <form class="space-y-5" @submit.prevent="submit(true)">
            <Card>
                <template #header>
                    <h2 class="text-base">The job</h2>
                </template>

                <Input
                    v-model="form.title"
                    label="Job title"
                    placeholder="Poultry attendant"
                    :error="form.errors.title"
                    required
                />

                <Textarea
                    v-model="form.description"
                    class="mt-4"
                    label="What the work is"
                    :rows="7"
                    hint="The hours, the days, what they will actually be doing. Say if it is night work."
                    :error="form.errors.description"
                    required
                />

                <div class="mt-4 space-y-3">
                    <label
                        v-for="type in jobTypes"
                        :key="type.value"
                        class="flex cursor-pointer items-start gap-3 rounded-sm border-2 p-3 transition-colors"
                        :class="
                            form.job_type === type.value
                                ? 'border-ink bg-chrome-50 dark:border-wash dark:bg-grain-800'
                                : 'border-grain-300 hover:border-ink dark:border-grain-600 dark:hover:border-wash'
                        "
                    >
                        <input
                            v-model="form.job_type"
                            type="radio"
                            :value="type.value"
                            class="mt-1 size-4 shrink-0 accent-enamel"
                        />
                        <span class="min-w-0">
                            <span class="stencil block text-ink dark:text-wash">{{ type.label }}</span>
                            <span class="mt-0.5 block text-sm text-muted">{{ type.hint }}</span>
                        </span>
                    </label>
                </div>
            </Card>

            <Card>
                <template #header>
                    <div>
                        <h2 class="text-base">What they need to be able to do</h2>
                        <p class="mt-0.5 text-xs text-muted">This is what the matching runs on.</p>
                    </div>
                </template>

                <div v-for="group in skills" :key="group.sector" class="mb-5 last:mb-0">
                    <p class="stencil text-muted">{{ group.sector }}</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="skill in group.skills"
                            :key="skill.id"
                            class="flex cursor-pointer items-center gap-2 rounded-sm border-2 p-2 text-sm transition-colors"
                            :class="
                                form.skill_ids.includes(skill.id)
                                    ? 'border-ink bg-chrome-50 dark:border-wash dark:bg-grain-800'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600 dark:hover:border-wash'
                            "
                        >
                            <input
                                type="checkbox"
                                :checked="form.skill_ids.includes(skill.id)"
                                class="size-4 shrink-0 accent-enamel"
                                @change="toggleSkill(skill.id)"
                            />
                            {{ skill.name }}
                        </label>
                    </div>
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">Where and when</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Select v-model="form.state" label="State" :error="form.errors.state" required>
                        <option value="">Choose a state</option>
                        <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                    </Select>
                    <Input v-model="form.lga" label="LGA" :error="form.errors.lga" />
                    <Input
                        v-model="form.positions_available"
                        label="How many people"
                        type="number"
                        min="1"
                        figures
                        :error="form.errors.positions_available"
                    />
                    <Input
                        v-model="form.start_date"
                        label="Start date"
                        type="date"
                        :error="form.errors.start_date"
                    />
                    <Input
                        v-model="form.application_deadline"
                        label="Apply by"
                        type="date"
                        hint="The job closes itself on this date."
                        :error="form.errors.application_deadline"
                        class="sm:col-span-2"
                    />
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">The offer</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-3">
                    <Input
                        v-model="form.pay_min"
                        label="Pay from"
                        type="number"
                        min="0"
                        figures
                        prefix="₦"
                        :error="form.errors.pay_min"
                    />
                    <Input
                        v-model="form.pay_max"
                        label="Up to"
                        type="number"
                        min="0"
                        figures
                        prefix="₦"
                        :error="form.errors.pay_max"
                    />
                    <Select v-model="form.pay_period" label="Per" :error="form.errors.pay_period">
                        <option v-for="(label, value) in payPeriods" :key="value" :value="value">{{ label }}</option>
                    </Select>
                </div>

                <!--
                    These two decide whether a job three states away is possible
                    at all for somebody with nothing, so they are asked plainly
                    rather than left to the description.
                -->
                <div class="mt-4 space-y-3">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input
                            v-model="form.is_accommodation_provided"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-enamel"
                        />
                        <span class="text-sm">
                            Accommodation provided
                            <span class="block text-xs text-muted">
                                Often the difference between a worker being able to take the job or not.
                            </span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input v-model="form.is_food_provided" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                        <span class="text-sm">Food provided</span>
                    </label>
                </div>
            </Card>

            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : listing?.status === 'draft' || !listing ? 'Post it' : 'Save changes' }}
                </Button>
                <Button
                    v-if="!listing || listing.status === 'draft'"
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="submit(false)"
                >
                    Save as a draft
                </Button>
                <Button variant="ghost" :href="route('jobs.employer.dashboard')">Cancel</Button>
            </div>
        </form>
    </PublicLayout>
</template>
