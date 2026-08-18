<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    featuredCategories: { type: Array, default: () => [] },
    newestProducts: { type: Array, default: () => [] },
});
</script>

<template>
    <PublicLayout title="Marketplace">
        <section class="mb-10">
            <p class="stencil mb-2 text-enamel dark:text-chrome">Marketplace</p>
            <h1 class="text-2xl sm:text-3xl">What does the farm need today?</h1>
            <hr class="seam seam-chrome my-5" />

            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <li v-for="category in featuredCategories" :key="category.slug">
                    <Link
                        :href="route('catalogue.category', category.slug)"
                        class="flex h-full flex-col gap-1 rounded-sm border-2 border-ink bg-surface-raised p-3 shadow-offset hover:bg-chrome-50 dark:border-wash dark:bg-grain-900 dark:hover:bg-grain-800"
                    >
                        <span class="font-display text-sm font-bold uppercase tracking-wide">{{ category.name }}</span>
                        <span class="figures text-2xs text-muted">{{ category.count }} listings</span>
                        <span v-if="category.children.length" class="mt-1 line-clamp-2 text-xs text-muted">
                            {{ category.children.map((child) => child.name).join(' · ') }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <section aria-labelledby="newest-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <h2 id="newest-heading" class="text-xl">Just listed</h2>
                <Button :href="route('search')" variant="secondary" size="sm">See everything</Button>
            </div>

            <ProductGrid
                :products="newestProducts"
                empty-title="No listings yet"
                empty-description="Sellers are being approved now. Check back shortly."
            />
        </section>
    </PublicLayout>
</template>
