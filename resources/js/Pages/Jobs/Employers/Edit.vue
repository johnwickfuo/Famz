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
    profile: { type: Object, default: null },
    states: { type: Array, default: () => [] },
    prefill: { type: Object, default: () => ({}) },
    notice: { type: Object, required: true },
});

const form = useForm({
    business_name: props.profile?.business_name ?? props.prefill.business_name ?? '',
    business_type: props.profile?.business_type ?? '',
    state: props.profile?.state ?? props.prefill.state ?? '',
    lga: props.profile?.lga ?? '',
    address: props.profile?.address ?? '',
    about: props.profile?.about ?? '',
    contact_person: props.profile?.contact_person ?? '',
    phone: props.profile?.phone ?? props.prefill.phone ?? '',
    email: props.profile?.email ?? props.prefill.email ?? '',
    logo: null,
});

function submit() {
    form.post(route('jobs.employer.save'), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="My farm">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Hiring</p>
        <h1 class="text-2xl sm:text-3xl">{{ profile ? 'My farm' : 'Register as an employer' }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            This is what workers see when you post a job or open their profile.
        </p>

        <div class="mt-5">
            <BoardNotice :notice="notice" />
        </div>

        <hr class="seam seam-chrome my-6" />

        <form class="space-y-5" @submit.prevent="submit">
            <Card>
                <template #header>
                    <h2 class="text-base">The farm</h2>
                </template>

                <!--
                    Said at the point of registering, because this is the moment
                    somebody gains access to other people's phone numbers.
                -->
                <p class="mb-4 rounded-sm border-2 border-grain-300 p-3 text-sm dark:border-grain-600">
                    Registering as an employer lets you see workers' phone numbers. We count and record every profile
                    you open, and there is a daily limit. These are people trusting us with the one number they have.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Input
                        v-model="form.business_name"
                        label="Farm or business name"
                        :error="form.errors.business_name"
                        required
                        class="sm:col-span-2"
                    />
                    <Input
                        v-model="form.business_type"
                        label="What you farm"
                        placeholder="Layer poultry, catfish, mixed…"
                        :error="form.errors.business_type"
                    />
                    <Input v-model="form.contact_person" label="Who to ask for" :error="form.errors.contact_person" />
                    <Input
                        v-model="form.phone"
                        label="Phone number"
                        type="tel"
                        figures
                        :error="form.errors.phone"
                        required
                    />
                    <Input v-model="form.email" label="Email" type="email" :error="form.errors.email" />
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
                    <Textarea
                        v-model="form.address"
                        label="Address or landmark"
                        :rows="2"
                        :error="form.errors.address"
                        class="sm:col-span-2"
                    />
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-base">About the farm</h2>
                </template>

                <Textarea
                    v-model="form.about"
                    label="Tell workers about the place"
                    :rows="4"
                    hint="How big, what you keep, what it is like to work there. Somebody is deciding whether to travel."
                    :error="form.errors.about"
                />

                <div class="mt-4">
                    <p class="stencil text-ink dark:text-wash">Logo or photograph</p>
                    <input
                        type="file"
                        accept="image/*"
                        class="mt-2 block w-full rounded-sm border-2 border-ink p-2 text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-chrome file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wider dark:border-wash"
                        @change="form.logo = $event.target.files[0]"
                    />
                </div>
            </Card>

            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </Button>
                <Button v-if="profile" variant="ghost" :href="route('jobs.employer.dashboard')">Cancel</Button>
            </div>
        </form>
    </PublicLayout>
</template>
