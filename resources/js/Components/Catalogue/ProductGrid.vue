<script setup>
import ProductCard from './ProductCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

defineProps({
    products: { type: Array, default: () => [] },
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyDescription: { type: String, default: 'Try a different category or clear your filters.' },
});
</script>

<template>
    <div v-if="products.length" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <ProductCard v-for="product in products" :key="product.id" :product="product" />
    </div>

    <EmptyState v-else :title="emptyTitle" :description="emptyDescription">
        <template #action>
            <slot name="empty-action" />
        </template>
    </EmptyState>
</template>
