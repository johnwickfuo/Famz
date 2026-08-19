<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Modal from '@/Components/Ui/Modal.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import OfferStatusBadge from '@/Components/Offers/OfferStatusBadge.vue';

const props = defineProps({
    request: { type: Object, required: true },
    offers: { type: Array, default: () => [] },
});

const accepting = ref(null);
const closing = ref(false);
const working = ref(false);

const liveOffers = computed(() => props.offers.filter((offer) => offer.is_open));
const settledOffers = computed(() => props.offers.filter((offer) => !offer.is_open));

/*
 * Cheapest by unit price, never by total.
 *
 * A seller offering half the order has half the total, and badging that
 * "cheapest" would send buyers at the wrong offer for a reason that has
 * nothing to do with price.
 */
const cheapestKobo = computed(() =>
    liveOffers.value.length ? Math.min(...liveOffers.value.map((o) => o.unit_price_kobo)) : null,
);
const fastest = computed(() => {
    const withDays = liveOffers.value.filter((o) => o.delivery_days !== null);
    return withDays.length ? Math.min(...withDays.map((o) => o.delivery_days)) : null;
});

function accept() {
    working.value = true;
    router.post(
        route('offers.respond', accepting.value.id),
        { decision: 'accept' },
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                accepting.value = null;
            },
        },
    );
}

function closeRequest() {
    working.value = true;
    router.post(
        route('requests.close', props.request.slug),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                closing.value = false;
            },
        },
    );
}
</script>

<template>
    <PublicLayout :title="request.title">
        <Link :href="route('requests.mine')" class="stencil text-sm text-muted underline-offset-4 hover:underline">
            ← My requests
        </Link>

        <div class="mt-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <h1 class="text-2xl leading-tight sm:text-3xl">{{ request.title }}</h1>
                <p class="mt-1 text-sm text-muted">
                    <span class="figures">{{ request.quantity }}</span> {{ request.unit }} · {{ request.location }}
                    <span v-if="request.closes_at"> · closes {{ request.closes_at }}</span>
                </p>
            </div>
            <OfferStatusBadge :status="request.status" :label="request.status_label" />
        </div>

        <hr class="seam my-5" />

        <div
            v-if="request.rejection_reason"
            class="mb-5 rounded-sm border-2 border-cockscomb p-4 text-sm"
        >
            <p class="font-semibold">We could not publish this one</p>
            <p class="mt-1">{{ request.rejection_reason }}</p>
            <Button class="mt-3" size="sm" :href="route('requests.create')">Post it again</Button>
        </div>

        <EmptyState
            v-if="!offers.length"
            title="No offers yet"
            :description="
                request.status === 'open'
                    ? 'Sellers in this category can see your request. Offers usually start arriving within a day.'
                    : 'Nobody offered while this was open.'
            "
        >
            <template #action>
                <Button :href="route('requests.index')" variant="secondary">See the board</Button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg">
                    {{ liveOffers.length }} offer{{ liveOffers.length === 1 ? '' : 's' }} to compare
                </h2>
                <p class="text-sm text-muted">Cheapest first. Take whichever suits you.</p>
            </div>

            <!--
                One card per offer, laid out to be read across rather than down:
                the whole job of this page is comparing like with like.
            -->
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card v-for="offer in liveOffers" :key="offer.id">
                    <template #header>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold leading-snug">
                                    <Link
                                        v-if="offer.seller_slug"
                                        :href="route('catalogue.storefront', offer.seller_slug)"
                                        class="underline-offset-4 hover:underline"
                                    >
                                        {{ offer.seller }}
                                    </Link>
                                    <span v-else>{{ offer.seller }}</span>
                                </h3>
                                <p v-if="offer.location" class="text-xs text-muted">{{ offer.location }}</p>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <Badge v-if="offer.unit_price_kobo === cheapestKobo" variant="active" size="sm" dot>
                                    Cheapest each
                                </Badge>
                                <Badge
                                    v-if="fastest !== null && offer.delivery_days === fastest"
                                    variant="pending"
                                    size="sm"
                                    dot
                                >
                                    Fastest
                                </Badge>
                            </div>
                        </div>
                    </template>

                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Price each</dt>
                            <dd class="figures font-semibold">{{ offer.unit_price }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Quantity</dt>
                            <dd class="figures text-right">
                                {{ offer.quantity }} of {{ request.quantity }}
                                <span
                                    v-if="!offer.covers_all"
                                    class="block text-xs font-semibold text-cockscomb dark:text-cockscomb-300"
                                >
                                    {{ request.quantity - offer.quantity }} still to find
                                </span>
                            </dd>
                        </div>
                        <div v-if="offer.delivery_days !== null" class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted">Delivers in</dt>
                            <dd class="figures">{{ offer.delivery_days }} day{{ offer.delivery_days === 1 ? '' : 's' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-t-2 border-dashed border-grain-200 pt-2 dark:border-grain-700">
                            <dt class="font-display text-xs font-bold uppercase tracking-wider">Total</dt>
                            <dd class="figures text-lg font-bold">{{ offer.total }}</dd>
                        </div>
                    </dl>

                    <p v-if="offer.message" class="mt-3 text-sm text-muted">“{{ offer.message }}”</p>

                    <p v-if="offer.expires_at" class="mt-2 text-xs text-muted">
                        Stands until {{ offer.expires_at }}
                    </p>

                    <template v-if="request.status === 'open'" #footer>
                        <Button variant="enamel" block @click="accepting = offer">Take this one</Button>
                    </template>
                </Card>
            </div>

            <template v-if="settledOffers.length">
                <hr class="seam my-6" />

                <h2 class="text-base text-muted">Offers that are no longer open</h2>

                <ul class="mt-3 space-y-2">
                    <li
                        v-for="offer in settledOffers"
                        :key="offer.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-sm border-2 border-grain-300 px-3 py-2 text-sm dark:border-grain-600"
                    >
                        <span class="min-w-0">{{ offer.seller }}</span>
                        <span class="figures text-muted">{{ offer.total }}</span>
                        <OfferStatusBadge :status="offer.status" :label="offer.status_label" />
                    </li>
                </ul>
            </template>
        </template>

        <div v-if="request.can_close" class="mt-8">
            <button
                type="button"
                class="stencil text-cockscomb underline underline-offset-4"
                @click="closing = true"
            >
                Close this request
            </button>
        </div>

        <Modal :show="accepting !== null" title="Take this offer" @close="accepting = null">
            <p class="text-sm">
                You will be buying
                <span class="figures font-semibold">{{ accepting?.quantity }} {{ request.unit }}</span>
                from
                <span class="font-semibold">{{ accepting?.seller }}</span>
                for
                <span class="figures font-semibold">{{ accepting?.total }}</span>.
            </p>
            <p class="mt-3 text-sm text-muted">
                Everybody else who offered is told straight away, and you get a private link to pay at this price.
                Nothing is charged until you use it.
            </p>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" :disabled="working" @click="accepting = null">Not yet</Button>
                    <Button variant="enamel" :loading="working" :disabled="working" @click="accept">
                        Yes, take it
                    </Button>
                </div>
            </template>
        </Modal>

        <Modal :show="closing" title="Close this request" @close="closing = false">
            <p class="text-sm">
                It comes off the board and any seller still waiting is told. You can always post another.
            </p>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" :disabled="working" @click="closing = false">Keep it open</Button>
                    <Button variant="danger" :loading="working" :disabled="working" @click="closeRequest">
                        Close it
                    </Button>
                </div>
            </template>
        </Modal>
    </PublicLayout>
</template>
