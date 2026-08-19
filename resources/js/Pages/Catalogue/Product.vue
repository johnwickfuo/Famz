<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Breadcrumb from '@/Components/Ui/Breadcrumb.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import Modal from '@/Components/Ui/Modal.vue';
import Input from '@/Components/Ui/Input.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import Table from '@/Components/Ui/Table.vue';
import ProductGrid from '@/Components/Catalogue/ProductGrid.vue';
import SellerCard from '@/Components/Catalogue/SellerCard.vue';
import HandlingNotice from '@/Components/Catalogue/HandlingNotice.vue';
import { naira } from '@/Support/money';

const props = defineProps({
    product: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    related: { type: Array, default: () => [] },
});

const activeImage = ref(0);
const adding = ref(false);
const haggling = ref(false);
const answering = ref(false);

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const offerForm = useForm({
    quantity: props.product.min_order_quantity ?? 1,
    unit_price: '',
    message: '',
});

const answerForm = useForm({
    decision: 'accept',
    quantity: props.product.my_offer?.quantity ?? 1,
    unit_price: '',
    message: '',
});

const offerTotal = computed(() => {
    const price = Math.round(Number(offerForm.unit_price || 0) * 100);
    return price > 0 ? naira(price * Number(offerForm.quantity || 0)) : null;
});

function sendOffer() {
    offerForm.post(route('offers.store', props.product.slug), {
        preserveScroll: true,
        onSuccess: () => {
            haggling.value = false;
            offerForm.reset('unit_price', 'message');
        },
    });
}

function answerOffer(decision) {
    answerForm.decision = decision;
    answerForm.post(route('offers.respond', props.product.my_offer.id), {
        preserveScroll: true,
        onSuccess: () => (answering.value = false),
    });
}

function withdrawOffer() {
    router.post(route('offers.withdraw', props.product.my_offer.id), {}, { preserveScroll: true });
}
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

const total = computed(() => naira(effectiveUnitKobo.value * quantity.value));

const maxQuantity = computed(() => variant.value?.stock_quantity ?? props.product.stock_quantity);

function addToCart() {
    adding.value = true;

    router.post(
        route('cart.store'),
        {
            product_id: props.product.id,
            variant_id: selectedVariant.value,
            quantity: quantity.value,
        },
        {
            preserveScroll: true,
            onFinish: () => (adding.value = false),
        },
    );
}

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
                        <Button
                            size="lg"
                            class="flex-1"
                            :disabled="!product.in_stock || adding"
                            :loading="adding"
                            @click="addToCart"
                        >
                            {{ product.in_stock ? 'Add to cart' : 'Out of stock' }}
                        </Button>

                        <Button
                            v-if="product.is_negotiable && !product.my_offer"
                            variant="secondary"
                            size="lg"
                            @click="haggling = true"
                        >
                            Make an offer
                        </Button>
                    </div>

                    <!--
                        A haggle in progress belongs where the price is, not in
                        a menu somewhere: it is the reason this buyer came back.
                    -->
                    <div
                        v-if="product.my_offer"
                        class="mt-4 rounded-sm border-2 border-ink bg-chrome-100 p-3 dark:border-wash dark:bg-grain-800"
                    >
                        <p class="font-display text-xs font-bold uppercase tracking-wider">
                            {{ product.my_offer.awaiting_me ? 'The seller has come back to you' : 'Your offer is with the seller' }}
                        </p>
                        <p class="figures mt-1 text-sm">
                            {{ product.my_offer.quantity }} × {{ product.my_offer.unit_price }} —
                            {{ product.my_offer.total }}
                            <span v-if="product.my_offer.expires_at" class="text-muted">
                                · until {{ product.my_offer.expires_at }}
                            </span>
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button
                                v-if="product.my_offer.awaiting_me"
                                size="sm"
                                variant="enamel"
                                :loading="answerForm.processing"
                                @click="answerOffer('accept')"
                            >
                                Take it
                            </Button>
                            <Button
                                v-if="product.my_offer.awaiting_me"
                                size="sm"
                                variant="secondary"
                                @click="answering = true"
                            >
                                Come back with a price
                            </Button>
                            <button
                                v-else
                                type="button"
                                class="stencil text-cockscomb underline underline-offset-4"
                                @click="withdrawOffer"
                            >
                                Take my offer back
                            </button>
                        </div>
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

        <Modal :show="haggling" title="Make an offer" @close="haggling = false">
            <p v-if="!signedIn" class="text-sm">
                Sign in first — an offer needs somebody the seller can answer.
            </p>

            <form v-else class="space-y-4" @submit.prevent="sendOffer">
                <p class="text-sm text-muted">
                    The seller can take it, turn it down, or come back with a price of their own. Nothing is
                    charged unless you both agree.
                </p>

                <Input
                    v-model="offerForm.quantity"
                    label="How many"
                    type="number"
                    figures
                    :min="product.min_order_quantity ?? 1"
                    :max="maxQuantity"
                    required
                    :error="offerForm.errors.quantity"
                />

                <Input
                    v-model="offerForm.unit_price"
                    label="Your price each"
                    type="number"
                    step="0.01"
                    prefix="₦"
                    figures
                    required
                    :hint="`They are asking ${naira(product.price_kobo)}.`"
                    :error="offerForm.errors.unit_price"
                />

                <Textarea
                    v-model="offerForm.message"
                    label="Anything to say"
                    :rows="3"
                    :maxlength="1000"
                    hint="A line about why tends to work better than the number alone."
                    :error="offerForm.errors.message"
                />

                <p v-if="offerTotal" class="figures text-sm">
                    <span class="text-muted">You would pay</span>
                    <span class="ml-2 font-bold">{{ offerTotal }}</span>
                </p>
            </form>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" @click="haggling = false">Never mind</Button>
                    <Button v-if="!signedIn" :href="route('login')">Sign in</Button>
                    <Button
                        v-else
                        :loading="offerForm.processing"
                        :disabled="offerForm.processing"
                        @click="sendOffer"
                    >
                        Send the offer
                    </Button>
                </div>
            </template>
        </Modal>

        <Modal :show="answering" title="Come back with a price" @close="answering = false">
            <div class="space-y-4">
                <p v-if="product.my_offer" class="text-sm text-muted">
                    They are asking
                    <span class="figures font-semibold">{{ product.my_offer.unit_price }}</span>
                    each for
                    <span class="figures font-semibold">{{ product.my_offer.quantity }}</span>.
                </p>

                <Input
                    v-model="answerForm.quantity"
                    label="How many"
                    type="number"
                    figures
                    :min="1"
                    required
                    :error="answerForm.errors.quantity"
                />

                <Input
                    v-model="answerForm.unit_price"
                    label="Your price each"
                    type="number"
                    step="0.01"
                    prefix="₦"
                    figures
                    required
                    :error="answerForm.errors.unit_price"
                />

                <Textarea
                    v-model="answerForm.message"
                    label="Anything to say"
                    :rows="2"
                    :maxlength="1000"
                    :error="answerForm.errors.message"
                />
            </div>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" @click="answering = false">Never mind</Button>
                    <Button
                        :loading="answerForm.processing"
                        :disabled="answerForm.processing"
                        @click="answerOffer('counter')"
                    >
                        Send it
                    </Button>
                </div>
            </template>
        </Modal>

    </PublicLayout>
</template>
