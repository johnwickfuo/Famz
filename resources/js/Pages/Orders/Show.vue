<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { orderTone, subOrderTone } from '@/Support/statusTone';

const props = defineProps({
    order: { type: Object, required: true },
    subOrders: { type: Array, default: () => [] },
});

const confirming = ref(null);
const working = ref(false);

function confirmReceipt() {
    working.value = true;

    router.post(
        route('orders.received', confirming.value.reference),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                confirming.value = null;
            },
        },
    );
}

function payAgain() {
    router.post(route('checkout.pay', props.order.reference));
}

const canPay = computed(() => props.order.status === 'pending_payment');
</script>

<template>
    <PublicLayout :title="`Order ${order.reference}`">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <h1 class="figures text-2xl sm:text-3xl">{{ order.reference }}</h1>
                <p class="mt-1 text-sm text-muted">
                    Placed {{ order.placed_at }}<span v-if="order.paid_at"> · paid {{ order.paid_at }}</span>
                </p>
            </div>
            <Badge :variant="orderTone(order.status)" dot>{{ order.status_label }}</Badge>
        </div>

        <hr class="seam my-5" />

        <div
            v-if="canPay"
            class="mb-5 rounded-sm border-2 border-chrome-700 bg-chrome-100 p-4 dark:bg-grain-800"
        >
            <p class="font-display text-sm font-bold uppercase tracking-wider">This order has not been paid for</p>
            <p class="mt-1 text-sm">
                Nothing is reserved until it is. The prices here are already fixed, so you pay exactly what you see.
            </p>
            <Button class="mt-3" @click="payAgain">Pay {{ order.grand_total }}</Button>
        </div>

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <!--
                    One card per seller. A buyer paid once, but the goods come
                    from several people and arrive at different times, so each
                    part carries its own state.
                -->
                <Card v-for="sub in subOrders" :key="sub.reference">
                    <template #header>
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                            <div class="min-w-0">
                                <h2 class="text-base">
                                    <Link
                                        :href="route('catalogue.storefront', sub.seller.slug)"
                                        class="underline-offset-4 hover:underline"
                                    >
                                        {{ sub.seller.name }}
                                    </Link>
                                </h2>
                                <p v-if="sub.seller.location" class="text-xs text-muted">{{ sub.seller.location }}</p>
                            </div>
                            <Badge :variant="subOrderTone(sub.status)" dot>{{ sub.status_label }}</Badge>
                        </div>
                    </template>

                    <ul class="divide-y-2 divide-dashed divide-grain-200 dark:divide-grain-700">
                        <li v-for="(item, index) in sub.items" :key="index" class="flex gap-3 py-3 first:pt-0">
                            <img
                                v-if="item.image"
                                :src="item.image"
                                :alt="item.name"
                                class="size-14 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                                decoding="async"
                                width="56"
                                height="56"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold leading-snug">{{ item.name }}</p>
                                <p class="figures mt-0.5 text-xs text-muted">
                                    {{ item.quantity }} {{ item.unit }} × {{ item.unit_price }}
                                </p>
                            </div>
                            <p class="figures shrink-0 text-sm font-bold">{{ item.line_total }}</p>
                        </li>
                    </ul>

                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Delivery</dt>
                            <dd class="text-right">
                                {{ sub.delivery_method_label }}
                                <span class="figures font-semibold">— {{ sub.delivery_fee }}</span>
                            </dd>
                        </div>
                    </dl>

                    <!-- Withheld until the money is in; that is the point of it. -->
                    <p
                        v-if="sub.seller.address"
                        class="mt-3 rounded-sm border-2 border-dashed border-grain-300 p-3 text-sm dark:border-grain-600"
                    >
                        <span class="font-semibold">Collect from:</span> {{ sub.seller.address }}
                        <span v-if="sub.seller.phone" class="figures block text-muted">{{ sub.seller.phone }}</span>
                    </p>

                    <p
                        v-if="sub.rejection_reason"
                        class="mt-3 rounded-sm border-2 border-cockscomb p-3 text-sm"
                    >
                        <span class="font-semibold">The seller could not fill this:</span> {{ sub.rejection_reason }}
                        <span class="mt-1 block text-muted">
                            Your money for this part has been sent back. It reaches your bank on your bank's own
                            schedule.
                        </span>
                    </p>

                    <ol v-if="sub.timeline.length" class="mt-3 space-y-1 text-xs text-muted">
                        <li v-for="step in sub.timeline" :key="step.label" class="flex gap-2">
                            <span aria-hidden="true">·</span>
                            <span>{{ step.label }}</span>
                            <span class="figures ml-auto">{{ step.at }}</span>
                        </li>
                    </ol>

                    <template v-if="sub.can_mark_received" #footer>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button variant="enamel" @click="confirming = sub">I have received this</Button>
                            <p class="text-xs text-muted">
                                This pays the seller. Only confirm once the goods are with you.
                            </p>
                        </div>
                    </template>
                </Card>
            </div>

            <div class="mt-6 space-y-5 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card>
                    <template #header>
                        <h2 class="text-lg">What you paid</h2>
                    </template>

                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Goods</dt>
                            <dd class="figures font-semibold">{{ order.subtotal }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Delivery</dt>
                            <dd class="figures font-semibold">{{ order.delivery_total }}</dd>
                        </div>
                    </dl>

                    <hr class="seam my-3" />

                    <div class="flex items-baseline justify-between gap-3">
                        <span class="font-display text-sm font-bold uppercase tracking-wider">Total</span>
                        <span class="figures text-xl font-bold">{{ order.grand_total }}</span>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-lg">Delivery address</h2>
                    </template>

                    <address class="space-y-0.5 text-sm not-italic">
                        <p v-for="(line, index) in order.address_lines" :key="index">{{ line }}</p>
                    </address>

                    <p v-if="order.note" class="mt-3 text-sm text-muted">“{{ order.note }}”</p>
                </Card>
            </div>
        </div>

        <Modal :show="confirming !== null" title="Confirm you have the goods" @close="confirming = null">
            <p class="text-sm">
                Confirming pays
                <span class="font-semibold">{{ confirming?.seller.name }}</span>
                straight away, and we can no longer hold the money on your behalf.
            </p>
            <p class="mt-3 text-sm text-muted">
                If something is wrong with what arrived, do not confirm — get in touch first.
            </p>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" :disabled="working" @click="confirming = null">Not yet</Button>
                    <Button variant="enamel" :loading="working" :disabled="working" @click="confirmReceipt">
                        Yes, I have it
                    </Button>
                </div>
            </template>
        </Modal>
    </PublicLayout>
</template>
