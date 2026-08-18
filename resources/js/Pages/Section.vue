<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Button from '@/Components/Ui/Button.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';

defineProps({
    section: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="section.title">
        <Breadcrumb
            class="mb-4"
            :items="[{ label: 'Home', href: route('home') }, { label: section.title }]"
        />

        <h1 class="text-2xl sm:text-3xl">{{ section.title }}</h1>
        <hr class="seam my-5" />

        <EmptyState :title="section.empty_title" :description="section.empty_description">
            <template #action>
                <Button :href="route('register')">Create an account</Button>
            </template>
        </EmptyState>
    </PublicLayout>
</template>
