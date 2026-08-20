<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import BoardNotice from '@/Components/Jobs/BoardNotice.vue';

const props = defineProps({
    profile: { type: Object, default: null },
    skills: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    workTypes: { type: Object, default: () => ({}) },
    payPeriods: { type: Object, default: () => ({}) },
    availabilities: { type: Object, default: () => ({}) },
    prefill: { type: Object, default: () => ({}) },
    notice: { type: Object, required: true },
});

const form = useForm({
    full_name: props.profile?.full_name ?? props.prefill.full_name ?? '',
    phone: props.profile?.phone ?? props.prefill.phone ?? '',
    whatsapp: props.profile?.whatsapp ?? '',
    state: props.profile?.state ?? props.prefill.state ?? '',
    lga: props.profile?.lga ?? props.prefill.lga ?? '',
    willing_to_relocate: props.profile?.willing_to_relocate ?? false,
    work_type_wanted: props.profile?.work_type_wanted ?? 'both',
    years_experience: props.profile?.years_experience ?? 0,
    expected_pay_min: props.profile?.expected_pay_min ?? '',
    expected_pay_max: props.profile?.expected_pay_max ?? '',
    pay_period: props.profile?.pay_period ?? 'monthly',
    availability: props.profile?.availability ?? 'immediately',
    about: props.profile?.about ?? '',
    is_open_to_work: props.profile?.is_open_to_work ?? true,
    photo: null,
    skill_ids: props.profile?.skill_ids ?? [],
});

function toggleSkill(id) {
    form.skill_ids = form.skill_ids.includes(id)
        ? form.skill_ids.filter((s) => s !== id)
        : [...form.skill_ids, id];
}

function submit() {
    form.post(route('jobs.worker.save'), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="My worker profile">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Looking for work</p>
        <h1 class="text-2xl sm:text-3xl">{{ profile ? 'My profile' : 'Set up your profile' }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Farms search by skill and by state. The more you tick, the more often you turn up.
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <hr class="seam seam-chrome my-6" />

        <form class="space-y-5" @submit.prevent="submit">
            <Card>
                <template #header>
                    <h2 class="text-base">How farms reach you</h2>
                </template>

                <!--
                    Said where they enter it, not in a policy page. Somebody
                    typing their only phone number into a website deserves to
                    be told there and then who will see it.
                -->
                <p class="mb-4 rounded-sm border-2 border-grain-300 p-3 text-sm dark:border-grain-600">
                    Your number is only shown to farms registered as employers, and we count and record every time one
                    looks. It is never on a public page and never in a search engine.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.full_name" label="Your name" :error="form.errors.full_name" required />
                    <Input
                        v-model="form.phone"
                        label="Phone number"
                        type="tel"
                        figures
                        placeholder="0803 000 0000"
                        :error="form.errors.phone"
                        required
                    />
                    <Input
                        v-model="form.whatsapp"
                        label="WhatsApp"
                        type="tel"
                        figures
                        hint="Optional, if it is a different number."
                        :error="form.errors.whatsapp"
                    />
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">Where you are</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Select v-model="form.state" label="State" :error="form.errors.state" required>
                        <option value="">Choose a state</option>
                        <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                    </Select>
                    <Input v-model="form.lga" label="LGA" :error="form.errors.lga" />
                </div>

                <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-sm border-2 border-grain-300 p-3 dark:border-grain-600">
                    <input v-model="form.willing_to_relocate" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                    <span>
                        <span class="stencil block text-ink dark:text-wash">I will move for the right job</span>
                        <span class="mt-0.5 block text-sm text-muted">
                            Ticking this puts you in front of farms in every state, not just your own.
                        </span>
                    </span>
                </label>
            </Card>

            <Card>
                <template #header>
                    <div>
                        <h2 class="text-base">What you can do</h2>
                        <p class="mt-0.5 text-xs text-muted">Tick everything you have really done.</p>
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

                <p v-if="form.errors.skill_ids" class="text-xs text-cockscomb">{{ form.errors.skill_ids }}</p>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">The work you want</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Select v-model="form.work_type_wanted" label="Kind of work" :error="form.errors.work_type_wanted">
                        <option v-for="(label, value) in workTypes" :key="value" :value="value">{{ label }}</option>
                    </Select>
                    <Input
                        v-model="form.years_experience"
                        label="Years doing farm work"
                        type="number"
                        min="0"
                        figures
                        :error="form.errors.years_experience"
                    />
                    <Select v-model="form.availability" label="When you can start" :error="form.errors.availability">
                        <option v-for="(label, value) in availabilities" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </Select>
                    <Select v-model="form.pay_period" label="Pay quoted" :error="form.errors.pay_period">
                        <option v-for="(label, value) in payPeriods" :key="value" :value="value">{{ label }}</option>
                    </Select>
                    <Input
                        v-model="form.expected_pay_min"
                        label="Hoping for at least"
                        type="number"
                        min="0"
                        figures
                        prefix="₦"
                        :error="form.errors.expected_pay_min"
                    />
                    <Input
                        v-model="form.expected_pay_max"
                        label="Up to"
                        type="number"
                        min="0"
                        figures
                        prefix="₦"
                        :error="form.errors.expected_pay_max"
                    />
                </div>

                <Textarea
                    v-model="form.about"
                    class="mt-4"
                    label="About you"
                    :rows="4"
                    hint="Where you have worked, what you are good at. A few honest lines beats a long list."
                    :error="form.errors.about"
                />

                <div class="mt-4">
                    <p class="stencil text-ink dark:text-wash">Photograph</p>
                    <p class="mt-1 text-sm text-muted">Optional. A face makes a profile far more likely to be opened.</p>
                    <input
                        type="file"
                        accept="image/*"
                        class="mt-2 block w-full rounded-sm border-2 border-ink p-2 text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-chrome file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wider dark:border-wash"
                        @change="form.photo = $event.target.files[0]"
                    />
                </div>

                <label class="mt-4 flex cursor-pointer items-start gap-3">
                    <input v-model="form.is_open_to_work" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                    <span class="text-sm">
                        I am looking for work now
                        <span class="block text-xs text-muted">
                            Untick this when you take a job. Your profile and ratings stay, you just stop appearing in
                            searches.
                        </span>
                    </span>
                </label>
            </Card>

            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save my profile' }}
                </Button>
                <Button v-if="profile" variant="ghost" :href="route('jobs.worker.dashboard')">Cancel</Button>
            </div>
        </form>
    </PublicLayout>
</template>
