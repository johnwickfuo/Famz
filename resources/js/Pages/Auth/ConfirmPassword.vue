<script setup>
import { useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

const form = useForm({ password: '' });

function submit() {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <GuestLayout
        title="Confirm password"
        heading="Confirm your password"
        subheading="This is a secure area. Please confirm your password to continue."
    >
        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="current-password"
                required
                autofocus
                :error="form.errors.password"
            />

            <Button type="submit" block :loading="form.processing">Confirm</Button>
        </form>
    </GuestLayout>
</template>
