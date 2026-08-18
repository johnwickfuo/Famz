<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * Registration creates a plain user with no elevated role. Seller, mentor,
 * worker and employer capabilities are granted afterwards by an administrator.
 */
const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout
        title="Create account"
        heading="Create your account"
        subheading="Free to join. You can add selling or mentoring later."
    >
        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.name"
                label="Full name"
                autocomplete="name"
                required
                autofocus
                :error="form.errors.name"
            />

            <Input
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="username"
                required
                :error="form.errors.email"
            />

            <Input
                v-model="form.phone"
                label="Phone number"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                figures
                hint="Used for order updates. We never show it publicly."
                :error="form.errors.phone"
            />

            <Input
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />

            <Input
                v-model="form.password_confirmation"
                label="Confirm password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <Button type="submit" block :loading="form.processing">Create account</Button>

            <p class="text-sm">
                Already registered?
                <Link :href="route('login')" class="text-enamel underline underline-offset-4 dark:text-chrome">
                    Sign in
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
