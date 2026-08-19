<script setup>
import { router } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Badge from '@/Components/Ui/Badge.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    subtotal: { type: String, required: true },
    count: { type: Number, default: 0 },
});

function setQuantity(line, quantity) {
    router.patch(
        route('cart.update'),
        { product_id: line.product_id, variant_id: line.variant_id, quantity },
        { preserveScroll: true },
    );
}

function remove(line) {
    router.delete(route('cart.destroy'), {
        data: { product_id: line.product_id, variant_id: line.variant_id },
        preserveScroll: true,
    });
}
</script>

<template>
    <PublicLayout title="Your cart">
        <h1 class="text-2xl sm:text-3xl">Your cart</h1>
        <p v-if="count" class="figures mt-1 text-sm text-muted">{{ count }} item{{ count === 1 ? '' : 's' }}</p>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!groups.length"
            title="Your cart is empty"
            description="Everything you add is kept here while you look around."
        >
            <template #action>
                <Button :href="route('catalogue.home')">Browse the market</Button>
            </template>
        </EmptyState>

        <div v-else class="lg:flex lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <!-- One card per seller, because that is how it will be split. -->
                <Card v-for="group in groups" :key="group.seller.id">
                    <template #header>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base">
                                <Link :href="route('catalogue.storefront', group.seller.slug)" class="hover:underline">
                                    {{ group.seller.name }}
                                </Link>
                            </h2>
                            <span class="figures text-sm font-semibold">{{ group.subtotal }}</span>
                        </div>
                        <p v-if="group.seller.location" class="text-xs text-muted">{{ group.seller.location }}</p>
                    </template>

                    <ul class="divide-y-2 divide-dashed divide-grain-200 dark:divide-grain-700">
                        <li
                            v-for="line in group.lines"
                            :key="`${line.product_id}-${line.variant_id ?? 0}`"
                            class="flex gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <img
                                v-if="line.image"
                                :src="line.image"
                                :alt="line.name"
                                class="size-16 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                                decoding="async"
                                width="64"
                                height="64"
                            />
                            <span
                                v-else
                                class="flex size-16 shrink-0 items-center justify-center rounded-sm border-2 border-grain-300 text-2xs text-muted dark:border-grain-600"
                                aria-hidden="true"
                            >No photo</span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold leading-snug">
                                    <Link :href="route('catalogue.product', line.slug)" class="hover:underline">
                                        {{ line.name }}
                                    </Link>
                                    <span v-if="line.variant_name" class="text-muted"> — {{ line.variant_name }}</span>
                                </p>

                                <div class="mt-1 flex flex-wrap gap-1">
                                    <Badge v-if="line.is_live_animal" variant="active" size="sm" dot>Live animal</Badge>
                                    <Badge v-if="line.is_perishable" variant="danger" size="sm" dot>Perishable</Badge>
                                </div>

                                <!-- A price that moved is said here, not first
                                     seen on the payment page. -->
                                <p
                                    v-if="line.price_changed"
                                    class="mt-1 text-xs font-semibold text-cockscomb dark:text-cockscomb-300"
                                >
                                    The seller has changed this price to {{ line.current_unit_price }}. That is what you
                                    will pay.
                                </p>

                                <div class="mt-2 flex flex-wrap items-center gap-3">
                                    <div class="flex items-stretch">
                                        <button
                                            type="button"
                                            class="rounded-l-sm border-2 border-r-0 border-ink px-2.5 font-bold dark:border-wash"
                                            :disabled="line.quantity <= line.min_order_quantity"
                                            @click="setQuantity(line, line.quantity - 1)"
                                        >
                                            <span aria-hidden="true">−</span>
                                            <span class="sr-only">One fewer {{ line.name }}</span>
                                        </button>
                                        <span class="figures border-2 border-ink px-3 py-1 text-sm dark:border-wash">
                                            {{ line.quantity }}
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-r-sm border-2 border-l-0 border-ink px-2.5 font-bold dark:border-wash"
                                            :disabled="line.quantity >= line.stock_quantity"
                                            @click="setQuantity(line, line.quantity + 1)"
                                        >
                                            <span aria-hidden="true">+</span>
                                            <span class="sr-only">One more {{ line.name }}</span>
                                        </button>
                                    </div>

                                    <button
                                        type="button"
                                        class="stencil text-cockscomb underline underline-offset-4"
                                        @click="remove(line)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <p class="figures shrink-0 text-sm font-bold">{{ line.line_total }}</p>
                        </li>
                    </ul>
                </Card>
            </div>

            <div class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-lg">Summary</h2>
                    </template>

                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Goods</dt>
                            <dd class="figures font-semibold">{{ subtotal }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Delivery</dt>
                            <dd class="text-muted">Chosen at checkout</dd>
                        </div>
                    </dl>

                    <template #footer>
                        <Button :href="route('checkout.show')" size="lg" block>Checkout</Button>
                    </template>
                </Card>
            </div>
        </div>
    </PublicLayout>
</template>
