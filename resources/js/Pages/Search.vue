<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

defineProps({
    query: { type: String, default: '' },
});
</script>

<template>
    <PublicLayout title="Search">
        <h1 class="text-2xl sm:text-3xl">
            Search<span v-if="query"> — “{{ query }}”</span>
        </h1>
        <hr class="seam my-5" />

        <EmptyState
            title="Nothing to show yet"
            description="Listings will appear here once sellers start publishing."
        />
    </PublicLayout>
</template>
