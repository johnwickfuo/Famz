<script setup>
import { useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ email: '' });

function submit() {
    form.post(route('password.email'));
}
</script>

<template>
    <GuestLayout
        title="Reset password"
        heading="Reset your password"
        subheading="Tell us your email address and we will send you a reset link."
    >
        <p v-if="status" class="mb-4 rounded-sm border-2 border-enamel bg-enamel-50 px-3 py-2 text-sm font-semibold text-enamel-700">
            {{ status }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="username"
                required
                autofocus
                :error="form.errors.email"
            />

            <Button type="submit" block :loading="form.processing">Email reset link</Button>
        </form>
    </GuestLayout>
</template>
