<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

/**
 * Registering as a mentor, from an invitation.
 *
 * The tags are required and the paragraph is required, and they do different
 * jobs: the paragraph is what a client reads, the tags are what a shortlist is
 * built from. Asking for both is the point — a mentor with no tags can never be
 * matched, and a mentor with only tags reads like a form.
 */
const props = defineProps({
    token: { type: String, required: true },
    invitation: { type: Object, default: () => ({}) },
    specialisations: { type: Array, default: () => [] },
    contactMethods: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
});

const form = useForm({
    name: props.invitation.name ?? '',
    email: props.invitation.email ?? '',
    phone: '',
    state: '',
    lga: '',
    password: '',
    password_confirmation: '',

    headline: '',
    bio: '',
    strengths: '',
    specialisations: [],

    years_experience: 0,
    qualifications: '',
    affiliation: '',

    preferred_contact_method: 'whatsapp',
    contact_value: '',

    states_served: [],
    accepts_remote: true,
    accepts_in_person: false,
});

const method = computed(
    () => props.contactMethods.find((m) => m.value === form.preferred_contact_method) ?? props.contactMethods[0],
);

const openSector = ref(props.specialisations[0]?.sector ?? null);

function submit() {
    form.post(route('mentors.join.store', props.token));
}
</script>

<template>
    <PublicLayout title="Join as a mentor">
        <p class="stencil mb-2 text-enamel dark:text-chrome">By invitation</p>
        <h1 class="text-2xl sm:text-3xl">Set up your mentor profile</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            You were invited to mentor on this platform. Fill this in once and an administrator will review it — usually
            the same day.
            <span v-if="invitation.expires"> This invitation expires {{ invitation.expires }}.</span>
        </p>

        <p v-if="invitation.note" class="mt-3 max-w-2xl rounded-sm border-2 border-chrome-700 bg-chrome-100 p-3 text-sm dark:bg-grain-800">
            {{ invitation.note }}
        </p>

        <hr class="seam seam-chrome my-6" />

        <form class="max-w-3xl space-y-5" @submit.prevent="submit">
            <Card>
                <template #header>
                    <h2 class="text-base">Your account</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.name" label="Your name" :error="form.errors.name" required />
                    <Input
                        v-model="form.email"
                        label="Email"
                        type="email"
                        :error="form.errors.email"
                        :disabled="Boolean(invitation.email)"
                        required
                    />
                    <Input v-model="form.phone" label="Phone" :error="form.errors.phone" figures required />
                    <Select
                        v-model="form.state"
                        label="Where you are based"
                        :options="states"
                        placeholder="Choose a state"
                        :error="form.errors.state"
                    />
                    <Input
                        v-model="form.password"
                        label="Password"
                        type="password"
                        :error="form.errors.password"
                        required
                    />
                    <Input
                        v-model="form.password_confirmation"
                        label="Password again"
                        type="password"
                        required
                    />
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">Your profile</h2>
                </template>

                <Input
                    v-model="form.headline"
                    label="One line about you"
                    placeholder="Poultry farm manager, 14 years on commercial layer farms"
                    :error="form.errors.headline"
                    required
                />

                <Textarea
                    v-model="form.bio"
                    class="mt-4"
                    label="About you"
                    :rows="5"
                    :error="form.errors.bio"
                    required
                />

                <Textarea
                    v-model="form.strengths"
                    class="mt-4"
                    label="What are you good at?"
                    hint="In your own words. Clients read this."
                    :rows="4"
                    :error="form.errors.strengths"
                    required
                />

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <Input
                        v-model="form.years_experience"
                        label="Years doing this"
                        type="number"
                        figures
                        :error="form.errors.years_experience"
                        required
                    />
                    <Input
                        v-model="form.affiliation"
                        label="Farm or business (optional)"
                        :error="form.errors.affiliation"
                    />
                </div>

                <Textarea
                    v-model="form.qualifications"
                    class="mt-4"
                    label="Qualifications (optional)"
                    :rows="3"
                    :error="form.errors.qualifications"
                />
            </Card>

            <!--
                Required, and separate from the paragraph above. This is what
                matching actually runs on.
            -->
            <Card>
                <template #header>
                    <h2 class="text-base">What can you help with?</h2>
                </template>

                <p class="mb-3 text-sm text-muted">
                    Tick everything you could genuinely take work on. This is what we match clients to — the paragraph
                    above is what they read afterwards.
                </p>

                <p
                    v-if="form.errors.specialisations"
                    class="mb-3 text-sm font-semibold text-cockscomb dark:text-cockscomb-300"
                >
                    {{ form.errors.specialisations }}
                </p>

                <div v-for="group in specialisations" :key="group.sector" class="mb-4 last:mb-0">
                    <button
                        type="button"
                        class="stencil flex w-full items-center justify-between py-1 text-left text-muted"
                        @click="openSector = openSector === group.sector ? null : group.sector"
                    >
                        {{ group.label }}
                        <span aria-hidden="true">{{ openSector === group.sector ? '−' : '+' }}</span>
                    </button>

                    <div v-if="openSector === group.sector" class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="tag in group.tags"
                            :key="tag.id"
                            class="cursor-pointer rounded-full border-2 px-3 py-1.5 text-xs transition-colors"
                            :class="
                                form.specialisations.includes(tag.id)
                                    ? 'border-ink bg-chrome text-ink dark:border-wash'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600'
                            "
                            :title="tag.description"
                        >
                            <input v-model="form.specialisations" type="checkbox" :value="tag.id" class="sr-only" />
                            {{ tag.name }}
                        </label>
                    </div>
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">How clients reach you</h2>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Select
                        v-model="form.preferred_contact_method"
                        label="Preferred way"
                        :options="contactMethods.map((m) => ({ value: m.value, label: m.label }))"
                        :error="form.errors.preferred_contact_method"
                    />

                    <Input
                        v-model="form.contact_value"
                        :label="method?.value_label ?? 'Contact'"
                        :placeholder="method?.placeholder"
                        :error="form.errors.contact_value"
                        required
                    />
                </div>

                <p class="mt-2 rounded-sm border-2 border-grain-300 p-2 text-xs text-muted dark:border-grain-600">
                    This is never shown to anybody until they have paid for an engagement with you.
                </p>

                <div class="mt-4 space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.accepts_remote" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                        <span>I can work by phone, WhatsApp or video</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.accepts_in_person" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                        <span>I can visit a farm in person</span>
                    </label>
                </div>

                <div v-if="form.accepts_in_person" class="mt-4">
                    <p class="stencil mb-2 text-muted">States you will travel to</p>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="state in states"
                            :key="state"
                            class="cursor-pointer rounded-full border-2 px-2.5 py-1 text-xs"
                            :class="
                                form.states_served.includes(state)
                                    ? 'border-ink bg-chrome text-ink dark:border-wash'
                                    : 'border-grain-300 dark:border-grain-600'
                            "
                        >
                            <input v-model="form.states_served" type="checkbox" :value="state" class="sr-only" />
                            {{ state }}
                        </label>
                    </div>
                </div>
            </Card>

            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :loading="form.processing">Create my profile</Button>
                <p class="text-xs text-muted">You will not be listed until an administrator approves it.</p>
            </div>
        </form>
    </PublicLayout>
</template>
