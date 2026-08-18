<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/Ui/Button.vue';

const props = defineProps({
    status: { type: String, default: null },
});

const form = useForm({});

const justSent = computed(() => props.status === 'verification-link-sent');

function submit() {
    form.post(route('verification.send'));
}
</script>

<template>
    <GuestLayout
        title="Verify your email"
        heading="Verify your email"
        subheading="We sent you a link. Open it to finish setting up your account."
    >
        <p v-if="justSent" class="mb-4 rounded-sm border-2 border-enamel bg-enamel-50 px-3 py-2 text-sm font-semibold text-enamel-700">
            A fresh verification link has been sent to your email address.
        </p>

        <form class="flex flex-wrap items-center gap-3" @submit.prevent="submit">
            <Button type="submit" :loading="form.processing">Resend the link</Button>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="text-sm text-enamel underline underline-offset-4 dark:text-chrome"
            >
                Sign out
            </Link>
        </form>
    </GuestLayout>
</template>
