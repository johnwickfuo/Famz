<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import CatalogueFilters from '@/Components/Catalogue/CatalogueFilters.vue';
import Pagination from '@/Components/Ui/Pagination.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    query: { type: String, default: '' },
    products: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="query ? `Search: ${query}` : 'Search'">
        <h1 class="text-2xl sm:text-3xl">
            <template v-if="query">Results for “{{ query }}”</template>
            <template v-else>Everything for sale</template>
        </h1>

        <p v-if="pagination.total" class="figures mt-1 text-sm text-muted" aria-live="polite">
            {{ pagination.total }} listing{{ pagination.total === 1 ? '' : 's' }}
        </p>

        <hr class="seam my-5" />

        <div class="lg:flex lg:gap-6">
            <div class="lg:w-64 lg:shrink-0">
                <CatalogueFilters
                    :filters="filters"
                    :options="filterOptions"
                    route-name="search"
                />
            </div>

            <div class="mt-5 min-w-0 flex-1 lg:mt-0">
                <ProductGrid
                    :products="products"
                    empty-title="Nothing found"
                    :empty-description="query
                        ? `We could not find anything for “${query}”. Try a shorter word, or browse the categories.`
                        : 'No listings match those filters.'"
                >
                    <template #empty-action>
                        <Button :href="route('catalogue.home')" variant="secondary">Browse categories</Button>
                    </template>
                </ProductGrid>

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
