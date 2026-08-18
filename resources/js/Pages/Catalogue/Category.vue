<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import CatalogueFilters from '@/Components/Catalogue/CatalogueFilters.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

const props = defineProps({
    category: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    children: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="category.name">
        <Breadcrumb
            class="mb-4"
            :items="[{ label: 'Marketplace', href: route('catalogue.home') }, ...breadcrumbs]"
        />

        <h1 class="text-2xl sm:text-3xl">{{ category.name }}</h1>
        <p v-if="category.description" class="prose-farm mt-2 text-muted">{{ category.description }}</p>

        <ul v-if="children.length" class="mt-4 flex flex-wrap gap-2">
            <li v-for="child in children" :key="child.slug">
                <Link
                    :href="route('catalogue.category', child.slug)"
                    class="inline-flex items-center gap-1.5 rounded-sm border-2 border-grain-300 px-2.5 py-1.5 text-sm hover:border-ink dark:border-grain-600 dark:hover:border-wash"
                >
                    {{ child.name }}
                    <span class="figures text-2xs text-muted">{{ child.count }}</span>
                </Link>
            </li>
        </ul>

        <hr class="seam my-5" />

        <div class="lg:flex lg:gap-6">
            <div class="lg:w-64 lg:shrink-0">
                <CatalogueFilters
                    :filters="filters"
                    :options="filterOptions"
                    route-name="catalogue.category"
                    :route-params="category.slug"
                />
            </div>

            <div class="mt-5 min-w-0 flex-1 lg:mt-0">
                <p v-if="pagination.total" class="figures mb-3 text-xs text-muted" aria-live="polite">
                    {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }}
                </p>

                <ProductGrid
                    :products="products"
                    empty-title="Nothing matches those filters"
                    empty-description="Try widening the price range, or clear the filters to see everything in this category."
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
