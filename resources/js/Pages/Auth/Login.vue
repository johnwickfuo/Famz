<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    canResetPassword: { type: Boolean, default: false },
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <GuestLayout title="Sign in" heading="Sign in" subheading="Welcome back.">
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

            <Input
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="size-4 rounded-sm border-2 border-ink text-enamel dark:border-wash"
                />
                Keep me signed in
            </label>

            <Button type="submit" block :loading="form.processing">Sign in</Button>

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-enamel underline underline-offset-4 dark:text-chrome"
                >
                    Forgot your password?
                </Link>

                <Link :href="route('register')" class="text-enamel underline underline-offset-4 dark:text-chrome">
                    Create an account
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>
