<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import CatalogueFilters from '@/Components/Catalogue/CatalogueFilters.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import Badge from '@/Components/Ui/Badge.vue';

defineProps({
    seller: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="seller.business_name">
        <Breadcrumb
            class="mb-4"
            :items="[
                { label: 'Marketplace', href: route('catalogue.home') },
                { label: seller.business_name },
            ]"
        />

        <header class="flex flex-wrap items-start gap-4 rounded-sm border-2 border-ink bg-surface-raised p-4 shadow-offset dark:border-wash dark:bg-grain-900">
            <img
                v-if="seller.logo_url"
                :src="seller.logo_url"
                :alt="seller.business_name"
                class="size-16 shrink-0 rounded-sm border-2 border-ink object-contain dark:border-wash"
                loading="lazy"
                decoding="async"
            />
            <span
                v-else
                class="flex size-16 shrink-0 items-center justify-center rounded-sm border-2 border-ink bg-chrome-100 font-display text-lg font-bold dark:border-wash dark:bg-grain-800"
                aria-hidden="true"
            >{{ seller.business_name.slice(0, 2).toUpperCase() }}</span>

            <div class="min-w-0 flex-1">
                <h1 class="text-2xl">{{ seller.business_name }}</h1>
                <p v-if="seller.location" class="text-sm text-muted">{{ seller.location }}</p>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    <Badge size="sm">Member since {{ seller.member_since }}</Badge>
                    <Badge size="sm">{{ seller.listing_count }} listings</Badge>
                    <Badge v-if="seller.rating" size="sm" variant="active">{{ seller.rating }}</Badge>
                    <Badge v-else size="sm">Not rated yet</Badge>
                </div>
            </div>
        </header>

        <p v-if="seller.description" class="prose-farm mt-4 text-muted">{{ seller.description }}</p>

        <hr class="seam my-5" />

        <div class="lg:flex lg:gap-6">
            <div class="lg:w-64 lg:shrink-0">
                <CatalogueFilters
                    :filters="filters"
                    :options="filterOptions"
                    route-name="catalogue.storefront"
                    :route-params="seller.slug"
                />
            </div>

            <div class="mt-5 min-w-0 flex-1 lg:mt-0">
                <ProductGrid
                    :products="products"
                    empty-title="No listings here yet"
                    :empty-description="`${seller.business_name} has nothing published at the moment.`"
                />

                <div class="mt-6">
                    <Pagination
                        :links="pagination.links"
                        :from="pagination.from"
                        :to="pagination.to"
                        :total="pagination.total"
                    />
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
