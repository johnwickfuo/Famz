<script setup>
import { useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout title="Choose a new password" heading="Choose a new password">
        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="username"
                required
                :error="form.errors.email"
            />

            <Input
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                :error="form.errors.password"
            />

            <Input
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <Button type="submit" block :loading="form.processing">Save new password</Button>
        </form>
    </GuestLayout>
</template>
