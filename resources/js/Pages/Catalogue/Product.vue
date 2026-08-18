<script setup>
import { computed, ref } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import Table from '@/Components/Ui/Table.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import SellerCard from '@/Components/Catalogue/SellerCard.vue';
import HandlingNotice from '@/Components/Catalogue/HandlingNotice.vue';

const props = defineProps({
    product: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    related: { type: Array, default: () => [] },
});

const activeImage = ref(0);
const quantity = ref(props.product.min_order_quantity ?? 1);
const selectedVariant = ref(props.product.variants[0]?.id ?? null);

const variant = computed(() =>
    props.product.variants.find((option) => option.id === selectedVariant.value) ?? null,
);

/** The bulk tier that applies at the quantity currently chosen, if any. */
const activeTier = computed(() =>
    [...props.product.price_tiers]
        .filter((tier) => quantity.value >= tier.min_quantity)
        .sort((a, b) => b.min_quantity - a.min_quantity)[0] ?? null,
);

/**
 * Mirrors Product::unitPriceKoboFor(): a bulk tier replaces the base price and
 * the option's difference still applies on top, so at ten bags a 50kg bag
 * still costs more than a 25kg one.
 */
const effectiveUnitKobo = computed(() => {
    const base = activeTier.value?.unit_price_kobo ?? props.product.price_kobo;

    return Math.max(0, base + (variant.value?.price_delta_kobo ?? 0));
});

const naira = (kobo) =>
    '₦' + (kobo / 100).toLocaleString('en-NG', { maximumFractionDigits: kobo % 100 === 0 ? 0 : 2 });

const total = computed(() => naira(effectiveUnitKobo.value * quantity.value));

const maxQuantity = computed(() => variant.value?.stock_quantity ?? props.product.stock_quantity);

const tierColumns = [
    { key: 'quantity', label: 'Quantity' },
    { key: 'unit_price', label: 'Price each', align: 'right', figures: true },
    { key: 'saving', label: 'You save', align: 'right', figures: true },
];

const tierRows = computed(() =>
    props.product.price_tiers.map((tier, index) => ({
        id: index,
        quantity: `${tier.min_quantity}+`,
        unit_price: tier.unit_price,
        saving: tier.saving_percent ? `${tier.saving_percent}%` : '—',
    })),
);

function step(by) {
    const next = quantity.value + by;
    quantity.value = Math.min(Math.max(next, props.product.min_order_quantity ?? 1), Math.max(maxQuantity.value, 1));
}
</script>

<template>
    <PublicLayout :title="product.name">
        <Breadcrumb class="mb-4" :items="breadcrumbs" />

        <div class="grid gap-6 lg:grid-cols-12">
            <!-- Gallery -->
            <div class="lg:col-span-5">
                <div class="overflow-hidden rounded-sm border-2 border-ink bg-grain-100 shadow-offset dark:border-wash dark:bg-grain-800">
                    <img
                        v-if="product.images.length"
                        :src="product.images[activeImage].url"
                        :alt="product.images[activeImage].alt"
                        class="aspect-4/3 w-full object-cover"
                        loading="eager"
                        decoding="async"
                        width="800"
                        height="600"
                    />
                    <div v-else class="flex aspect-4/3 w-full items-center justify-center text-muted">
                        <span class="stencil">No photograph</span>
                    </div>
                </div>

                <ul v-if="product.images.length > 1" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    <li v-for="(image, index) in product.images" :key="index" class="shrink-0">
                        <button
                            type="button"
                            class="block overflow-hidden rounded-sm border-2"
                            :class="index === activeImage ? 'border-ink dark:border-wash' : 'border-grain-300 dark:border-grain-600'"
                            :aria-current="index === activeImage ? 'true' : undefined"
                            @click="activeImage = index"
                        >
                            <span class="sr-only">Photograph {{ index + 1 }}</span>
                            <img
                                :src="image.url"
                                alt=""
                                class="size-16 object-cover"
                                loading="lazy"
                                decoding="async"
                                width="64"
                                height="64"
                            />
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Buying -->
            <div class="lg:col-span-4">
                <div class="flex flex-wrap gap-1.5">
                    <Badge v-if="product.is_live_animal" variant="active" dot>Live animal</Badge>
                    <Badge v-if="product.is_perishable" variant="danger" dot>Perishable</Badge>
                    <Badge v-if="product.is_negotiable">Negotiable</Badge>
                    <Badge v-if="product.requires_delivery_quote">Delivery quoted</Badge>
                </div>

                <h1 class="mt-3 text-2xl sm:text-3xl">{{ product.name }}</h1>

                <p class="mt-3">
                    <span class="figures text-3xl font-bold">{{ naira(effectiveUnitKobo) }}</span>
                    <span class="ml-1.5 text-sm text-muted">{{ product.unit }}</span>
                </p>

                <p v-if="product.compare_at_price" class="mt-1">
                    <span class="figures text-sm text-muted line-through">{{ product.compare_at_price }}</span>
                    <span v-if="product.discount_percent" class="figures ml-2 text-sm font-semibold text-cockscomb">
                        −{{ product.discount_percent }}%
                    </span>
                </p>

                <hr class="seam my-4" />

                <dl class="space-y-2 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Availability</dt>
                        <dd>
                            <Badge :variant="product.in_stock ? 'active' : 'danger'" dot>
                                {{ product.in_stock ? `${product.stock_quantity} in stock` : 'Out of stock' }}
                            </Badge>
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Condition</dt>
                        <dd>{{ product.condition }}</dd>
                    </div>
                    <div v-if="product.min_order_quantity > 1" class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Minimum order</dt>
                        <dd class="figures">{{ product.min_order_quantity }}</dd>
                    </div>
                </dl>

                <!-- Options -->
                <fieldset v-if="product.variants.length" class="mt-4">
                    <legend class="stencil mb-2">Choose an option</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="option in product.variants"
                            :key="option.id"
                            class="inline-flex cursor-pointer flex-col rounded-sm border-2 px-3 py-2 text-sm"
                            :class="[
                                selectedVariant === option.id
                                    ? 'border-ink bg-chrome-100 dark:bg-grain-800 dark:border-wash'
                                    : 'border-grain-300 dark:border-grain-600',
                                option.in_stock ? '' : 'opacity-55',
                            ]"
                        >
                            <input
                                v-model="selectedVariant"
                                type="radio"
                                class="sr-only"
                                :value="option.id"
                                :disabled="!option.in_stock"
                            />
                            <span class="font-semibold">{{ option.name }}</span>
                            <span class="figures text-xs text-muted">{{ option.price }}</span>
                        </label>
                    </div>
                </fieldset>

                <!-- Quantity and cart -->
                <div class="mt-5">
                    <label for="quantity" class="stencil mb-1.5 block">Quantity</label>
                    <div class="flex items-stretch gap-2">
                        <div class="flex items-stretch">
                            <button
                                type="button"
                                class="rounded-l-sm border-2 border-r-0 border-ink px-3 font-bold dark:border-wash"
                                :disabled="quantity <= (product.min_order_quantity ?? 1)"
                                @click="step(-1)"
                            >
                                <span aria-hidden="true">−</span>
                                <span class="sr-only">One fewer</span>
                            </button>
                            <input
                                id="quantity"
                                v-model.number="quantity"
                                type="number"
                                inputmode="numeric"
                                class="figures w-20 border-2 border-ink bg-surface-raised px-2 py-2.5 text-center dark:border-wash dark:bg-grain-900"
                                :min="product.min_order_quantity ?? 1"
                                :max="Math.max(maxQuantity, 1)"
                            />
                            <button
                                type="button"
                                class="rounded-r-sm border-2 border-l-0 border-ink px-3 font-bold dark:border-wash"
                                :disabled="quantity >= maxQuantity"
                                @click="step(1)"
                            >
                                <span aria-hidden="true">+</span>
                                <span class="sr-only">One more</span>
                            </button>
                        </div>

                        <p class="flex flex-1 items-center justify-end">
                            <span class="stencil mr-2 text-muted">Total</span>
                            <span class="figures text-lg font-bold">{{ total }}</span>
                        </p>
                    </div>

                    <p v-if="activeTier" class="figures mt-2 text-xs font-semibold text-enamel dark:text-chrome" aria-live="polite">
                        Bulk price applied: {{ activeTier.unit_price }} each from {{ activeTier.min_quantity }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button size="lg" :disabled="!product.in_stock" class="flex-1">
                            {{ product.in_stock ? 'Add to cart' : 'Out of stock' }}
                        </Button>

                        <!-- Negotiation opens in a later phase; the control is
                             here so the seller's "negotiable" flag means
                             something on the page today. -->
                        <Button
                            v-if="product.is_negotiable"
                            variant="secondary"
                            size="lg"
                            disabled
                            title="Offers open soon"
                        >
                            Negotiate
                        </Button>
                    </div>

                    <p v-if="product.requires_delivery_quote" class="mt-3 text-xs text-muted">
                        Delivery for this item is quoted separately by the seller.
                    </p>
                </div>
            </div>

            <!-- Seller -->
            <div class="lg:col-span-3">
                <SellerCard :seller="product.seller" />
            </div>
        </div>

        <hr class="seam my-8" />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <HandlingNotice
                    :is-live-animal="product.is_live_animal"
                    :is-perishable="product.is_perishable"
                    :note="product.handling_note"
                />

                <section aria-labelledby="description-heading">
                    <h2 id="description-heading" class="text-xl">About this listing</h2>
                    <hr class="seam my-3" />
                    <p class="prose-farm whitespace-pre-line">{{ product.description }}</p>
                </section>
            </div>

            <div v-if="product.price_tiers.length">
                <Card>
                    <template #header>
                        <h2 class="text-lg">Buy more, pay less</h2>
                    </template>

                    <Table
                        :columns="tierColumns"
                        :rows="tierRows"
                        caption="Bulk prices for this listing"
                    />
                </Card>
            </div>
        </div>

        <section v-if="related.length" class="mt-10" aria-labelledby="related-heading">
            <h2 id="related-heading" class="text-xl">More in {{ product.category.name }}</h2>
            <hr class="seam my-4" />
            <ProductGrid :products="related" />
        </section>
    </PublicLayout>
</template>
