<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';

const props = defineProps({
    order: { type: Object, required: true },
});

/**
 * The gateway has sent the buyer back here, but coming back is not proof of
 * payment — the order only moves when the signed webhook lands. So this page
 * asks the server what it actually knows, every few seconds, until it knows
 * something.
 */
const status = ref({ ...props.order });
const waited = ref(0);
const givenUp = ref(false);

const INTERVAL = 3000;
const LIMIT = 90_000;

let timer = null;

async function poll() {
    try {
        const response = await fetch(route('checkout.status', status.value.reference), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            status.value = { ...status.value, ...(await response.json()) };
        }
    } catch {
        // A dropped request on a patchy connection is not worth a message;
        // the next tick will try again.
    }

    if (status.value.is_paid) {
        stop();

        return;
    }

    waited.value += INTERVAL;

    if (waited.value >= LIMIT) {
        givenUp.value = true;
        stop();
    }
}

function stop() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    if (status.value.is_paid) return;

    timer = setInterval(poll, INTERVAL);
});

onBeforeUnmount(stop);
</script>

<template>
    <PublicLayout title="Your payment">
        <div class="mx-auto max-w-xl">
            <Card>
                <template #header>
                    <h1 class="text-xl sm:text-2xl">
                        {{ status.is_paid ? 'Payment received' : 'Confirming your payment' }}
                    </h1>
                    <p class="figures mt-1 text-sm text-muted">Order {{ status.reference }}</p>
                </template>

                <template v-if="status.is_paid">
                    <p class="text-sm">
                        Thank you. We have <span class="figures font-semibold">{{ order.grand_total }}</span> and each
                        seller has been told to get your order ready.
                    </p>
                    <p class="mt-3 text-sm text-muted">
                        We hold the money until you confirm your goods have arrived. Your order page shows where each
                        part has got to.
                    </p>
                </template>

                <template v-else-if="givenUp">
                    <p class="text-sm">
                        Your bank has not confirmed this yet. That is usually a slow network rather than a failed
                        payment.
                    </p>
                    <p class="mt-3 text-sm text-muted">
                        Nothing is lost. Open your order in a few minutes — if the money went through, it will be
                        marked paid there. If you were not charged, you can pay again from the same page.
                    </p>
                </template>

                <template v-else>
                    <p class="flex items-center gap-3 text-sm">
                        <svg
                            class="size-5 shrink-0 animate-spin motion-reduce:animate-none"
                            viewBox="0 0 24 24"
                            fill="none"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.3" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="square" />
                        </svg>
                        <span role="status">
                            Waiting for your bank to confirm. Please keep this page open — it updates by itself.
                        </span>
                    </p>
                    <p class="mt-3 text-sm text-muted">
                        This normally takes a few seconds. You do not need to pay again.
                    </p>
                </template>

                <template #footer>
                    <div class="flex flex-wrap gap-2">
                        <Button :href="route('orders.show', status.reference)" variant="primary">
                            View this order
                        </Button>
                        <Button :href="route('catalogue.home')" variant="ghost">Keep shopping</Button>
                    </div>
                </template>
            </Card>

            <p class="mt-4 text-center text-xs text-muted">
                Trouble with a payment?
                <Link :href="route('orders.index')" class="underline underline-offset-4">See all your orders</Link>.
            </p>
        </div>
    </PublicLayout>
</template>
