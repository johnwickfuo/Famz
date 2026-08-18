<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import FileUpload from '@/Components/Ui/FileUpload.vue';
import Button from '@/Components/Ui/Button.vue';

const props = defineProps({
    mustVerifyEmail: { type: Boolean, default: false },
    status: { type: String, default: null },
    profile: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    _method: 'patch',
    name: user.value.name,
    email: user.value.email,
    display_name: props.profile.display_name ?? '',
    phone: props.profile.phone ?? '',
    whatsapp: props.profile.whatsapp ?? '',
    state: props.profile.state ?? '',
    lga: props.profile.lga ?? '',
    bio: props.profile.bio ?? '',
    avatar: null,
});

function submit() {
    form.post(route('profile.update'), {
        preserveScroll: true,
        forceFormData: true,
    });
}
</script>

<template>
    <Card as="form" @submit.prevent="submit">
        <template #header>
            <h2 class="text-lg">Profile</h2>
            <p class="mt-1 text-sm text-muted">
                How you appear to buyers, sellers and mentors on the platform.
            </p>
        </template>

        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.name" label="Full name" required autocomplete="name" :error="form.errors.name" />
            <Input v-model="form.display_name" label="Display name" hint="Shown on listings and answers." :error="form.errors.display_name" />
            <Input v-model="form.email" label="Email address" type="email" required autocomplete="username" :error="form.errors.email" />
            <Input v-model="form.phone" label="Phone" type="tel" inputmode="tel" figures autocomplete="tel" :error="form.errors.phone" />
            <Input v-model="form.whatsapp" label="WhatsApp" type="tel" inputmode="tel" figures :error="form.errors.whatsapp" />
            <Select v-model="form.state" label="State" :options="states" placeholder="Select a state" :error="form.errors.state" />
            <Input v-model="form.lga" label="LGA" :error="form.errors.lga" />

            <div class="sm:col-span-2">
                <FileUpload
                    v-model="form.avatar"
                    label="Profile photo"
                    accept="image/png,image/jpeg,image/webp"
                    :current-url="profile.avatar_url"
                    hint="Square images look best. Max 2MB."
                    :error="form.errors.avatar"
                />
            </div>

            <div class="sm:col-span-2">
                <Textarea
                    v-model="form.bio"
                    label="About you"
                    :rows="4"
                    :maxlength="600"
                    hint="What you farm, sell or teach."
                    :error="form.errors.bio"
                />
            </div>
        </div>

        <div v-if="mustVerifyEmail && !user.email_verified" class="mt-4 rounded-sm border-2 border-chrome-700 bg-chrome-50 px-3 py-2 text-sm">
            <p>Your email address is not verified.</p>
            <Link
                :href="route('verification.send')"
                method="post"
                as="button"
                class="mt-1 font-semibold text-enamel underline underline-offset-4"
            >
                Resend the verification email
            </Link>
            <p v-if="status === 'verification-link-sent'" class="mt-2 font-semibold text-enamel-700">
                A new verification link has been sent.
            </p>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :loading="form.processing">Save changes</Button>
                <p v-if="form.recentlySuccessful" class="text-sm font-semibold text-enamel" aria-live="polite">
                    Saved.
                </p>
            </div>
        </template>
    </Card>
</template>
