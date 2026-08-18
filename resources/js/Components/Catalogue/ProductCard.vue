<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';

const props = defineProps({
    product: { type: Object, required: true },
});

/**
 * Live animals and perishables carry a handling obligation, so they are called
 * out on the card itself — a farmer scanning a grid needs to know which of
 * these is a living thing before they click into it.
 */
const flags = computed(() => {
    const list = [];
    if (props.product.is_live_animal) list.push({ label: 'Live animal', variant: 'active' });
    if (props.product.is_perishable) list.push({ label: 'Perishable', variant: 'danger' });
    return list;
});
</script>

<template>
    <article class="group flex h-full flex-col rounded-sm border-2 border-ink bg-surface-raised shadow-offset dark:border-wash dark:bg-grain-900">
        <Link
            :href="route('catalogue.product', product.slug)"
            class="block focus-visible:outline-offset-2"
        >
            <div class="relative aspect-4/3 overflow-hidden border-b-2 border-dashed border-grain-300 bg-grain-100 dark:border-grain-700 dark:bg-grain-800">
                <img
                    v-if="product.image"
                    :src="product.image"
                    :alt="product.name"
                    class="size-full object-cover"
                    loading="lazy"
                    decoding="async"
                    width="400"
                    height="300"
                />
                <div v-else class="flex size-full items-center justify-center text-muted">
                    <svg class="size-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M4 6h16v12H4z" /><path d="m5 16 4-4 3 3 3-3 4 4" /><circle cx="9" cy="10" r="1" />
                    </svg>
                    <span class="sr-only">No photograph</span>
                </div>

                <span
                    v-if="product.discount_percent"
                    class="figures absolute left-2 top-2 rounded-sm border-2 border-ink bg-cockscomb px-1.5 py-0.5 text-2xs font-bold text-wash"
                >−{{ product.discount_percent }}%</span>

                <span
                    v-if="!product.in_stock"
                    class="stencil absolute right-2 top-2 rounded-sm border-2 border-ink bg-grain-200 px-1.5 py-0.5 text-ink"
                >Out of stock</span>
            </div>
        </Link>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <div v-if="flags.length" class="flex flex-wrap gap-1">
                <Badge v-for="flag in flags" :key="flag.label" :variant="flag.variant" size="sm" dot>
                    {{ flag.label }}
                </Badge>
            </div>

            <h3 class="text-sm font-semibold leading-snug">
                <Link :href="route('catalogue.product', product.slug)" class="hover:underline">
                    {{ product.name }}
                </Link>
            </h3>

            <p class="mt-auto">
                <span class="figures text-lg font-bold">{{ product.price }}</span>
                <span class="ml-1 text-xs text-muted">/ {{ product.unit }}</span>
                <span
                    v-if="product.compare_at_price"
                    class="figures ml-2 text-xs text-muted line-through"
                >{{ product.compare_at_price }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-1.5">
                <Badge v-if="product.is_negotiable" size="sm">Negotiable</Badge>
                <Badge v-if="product.has_bulk_pricing" size="sm">Bulk price</Badge>
            </div>

            <p class="truncate text-xs text-muted">
                <Link
                    :href="route('catalogue.storefront', product.seller.slug)"
                    class="hover:underline"
                >{{ product.seller.name }}</Link>
                <span v-if="product.seller.location"> · {{ product.seller.location }}</span>
            </p>
        </div>
    </article>
</template>
