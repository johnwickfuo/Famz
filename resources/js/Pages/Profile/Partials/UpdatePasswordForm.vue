<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) form.reset('password', 'password_confirmation');
            if (form.errors.current_password) form.reset('current_password');
        },
    });
}
</script>

<template>
    <Card as="form" @submit.prevent="submit">
        <template #header>
            <h2 class="text-lg">Password</h2>
            <p class="mt-1 text-sm text-muted">Use a long password you do not use anywhere else.</p>
        </template>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <Input
                    v-model="form.current_password"
                    label="Current password"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.current_password"
                />
            </div>
            <Input
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password"
            />
            <Input
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
            />
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :loading="form.processing">Change password</Button>
                <p v-if="form.recentlySuccessful" class="text-sm font-semibold text-enamel" aria-live="polite">
                    Saved.
                </p>
            </div>
        </template>
    </Card>
</template>
