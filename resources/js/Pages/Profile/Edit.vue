<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';

defineProps({
    mustVerifyEmail: { type: Boolean, default: false },
    status: { type: String, default: null },
    profile: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout
        title="My profile"
        :breadcrumbs="[{ label: 'Dashboard', href: route('dashboard') }, { label: 'My profile' }]"
    >
        <div class="space-y-6">
            <UpdateProfileInformationForm
                :must-verify-email="mustVerifyEmail"
                :status="status"
                :profile="profile"
                :states="states"
            />
            <UpdatePasswordForm />
            <DeleteUserForm />
        </div>
    </AppLayout>
</template>
