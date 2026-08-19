<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import { naira } from '@/Support/money';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    subtotal: { type: String, required: true },
    subtotal_kobo: { type: Number, default: 0 },
    address: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: 'paystack' },
    priceChanges: { type: Array, default: () => [] },
});

function defaultMethods(groups) {
    return Object.fromEntries(
        groups.map((group) => [group.seller.id, group.delivery_methods[0]?.value ?? null]),
    );
}

const form = useForm({
    name: props.address.name ?? '',
    phone: props.address.phone ?? '',
    address: props.address.address ?? '',
    state: props.address.state ?? '',
    lga: props.address.lga ?? '',
    note: '',
    delivery_methods: defaultMethods(props.groups),
    gateway: props.defaultGateway,
});

// What a seller charges depends on where the goods are going, so the delivery
// block is re-quoted by the server whenever the buyer changes state rather
// than guessed at in the browser.
const quoting = ref(false);

watch(
    () => form.state,
    (state) => {
        if (!state) return;

        quoting.value = true;
        router.reload({
            only: ['groups'],
            data: { state },
            onFinish: () => (quoting.value = false),
        });
    },
);

// A re-quote can withdraw a method the buyer had chosen — a seller who does
// not deliver to the new state, say — so the selection is reconciled against
// what is actually on offer.
watch(
    () => props.groups,
    (groups) => {
        const next = { ...form.delivery_methods };

        groups.forEach((group) => {
            const offered = group.delivery_methods.map((method) => method.value);

            if (!offered.includes(next[group.seller.id])) {
                next[group.seller.id] = offered[0] ?? null;
            }
        });

        form.delivery_methods = next;
    },
);

function chosenMethod(group) {
    return group.delivery_methods.find(
        (method) => method.value === form.delivery_methods[group.seller.id],
    );
}

const deliveryKobo = computed(() =>
    props.groups.reduce((total, group) => total + (chosenMethod(group)?.fee_kobo ?? 0), 0),
);

const grandTotalKobo = computed(() => props.subtotal_kobo + deliveryKobo.value);

const hasLiveOrPerishable = computed(() =>
    props.groups.some((group) =>
        group.lines.some((line) => line.is_live_animal || line.is_perishable),
    ),
);

function submit() {
    form.post(route('checkout.store'), { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="Checkout">
        <h1 class="text-2xl sm:text-3xl">Checkout</h1>
        <p class="mt-1 text-sm text-muted">
            You pay once. We hold the money and pass it to each seller as your goods arrive.
        </p>

        <hr class="seam my-5" />

        <!-- Said before payment, never for the first time on the receipt. -->
        <div
            v-if="priceChanges.length"
            class="mb-5 rounded-sm border-2 border-cockscomb bg-cockscomb-100 p-4 text-ink dark:bg-cockscomb-800 dark:text-wash"
            role="status"
        >
            <p class="font-display text-sm font-bold uppercase tracking-wider">Some prices have changed</p>
            <ul class="mt-2 space-y-1 text-sm">
                <li v-for="change in priceChanges" :key="change.name">
                    <span class="font-semibold">{{ change.name }}</span>
                    <span class="figures">
                        — was {{ change.was }}, now {{ change.now }}
                    </span>
                    <span class="text-muted">({{ change.increased ? 'gone up' : 'come down' }})</span>
                </li>
            </ul>
            <p class="mt-2 text-sm">The new prices are the ones in the total below.</p>
        </div>

        <form class="lg:flex lg:items-start lg:gap-6" @submit.prevent="submit">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-lg">Where it is going</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.name"
                            label="Full name"
                            required
                            autocomplete="name"
                            :error="form.errors.name"
                        />
                        <Input
                            v-model="form.phone"
                            label="Phone"
                            type="tel"
                            figures
                            required
                            autocomplete="tel"
                            hint="The seller will call this number."
                            :error="form.errors.phone"
                        />

                        <div class="sm:col-span-2">
                            <Textarea
                                v-model="form.address"
                                label="Street address"
                                :rows="3"
                                required
                                :maxlength="400"
                                hint="House or shop number, street, and a landmark if it helps."
                                :error="form.errors.address"
                            />
                        </div>

                        <Select
                            v-model="form.state"
                            label="State"
                            :options="states"
                            placeholder="Choose a state"
                            required
                            :error="form.errors.state"
                        />
                        <Input
                            v-model="form.lga"
                            label="Local government area"
                            required
                            :error="form.errors.lga"
                        />

                        <div class="sm:col-span-2">
                            <Textarea
                                v-model="form.note"
                                label="Note for the sellers"
                                :rows="2"
                                :maxlength="500"
                                hint="Optional. Delivery times, gate codes, anything they should know."
                                :error="form.errors.note"
                            />
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-lg">How it gets to you</h2>
                            <span v-if="quoting" class="text-xs text-muted" role="status">Re-checking rates…</span>
                        </div>
                        <p class="mt-1 text-sm text-muted">
                            Your cart has {{ groups.length }} seller{{ groups.length === 1 ? '' : 's' }} in it, so it is
                            {{ groups.length === 1 ? 'one delivery' : 'delivered separately by each of them' }}.
                        </p>
                    </template>

                    <p v-if="!form.state" class="text-sm text-muted">
                        Choose a state above and we will show what each seller charges to get there.
                    </p>

                    <div class="space-y-5">
                        <fieldset v-for="group in groups" :key="group.seller.id">
                            <legend class="flex w-full flex-wrap items-baseline justify-between gap-2 pb-2">
                                <span class="font-semibold">{{ group.seller.name }}</span>
                                <span class="figures text-sm text-muted">{{ group.subtotal }}</span>
                            </legend>

                            <p v-if="group.seller.location" class="-mt-1 mb-2 text-xs text-muted">
                                Ships from {{ group.seller.location }}
                            </p>

                            <ul class="mb-3 space-y-1 text-sm">
                                <li v-for="(line, index) in group.lines" :key="index" class="flex gap-2">
                                    <span class="figures shrink-0 text-muted">{{ line.quantity }}&times;</span>
                                    <span class="min-w-0">
                                        {{ line.name }}
                                        <span v-if="line.variant_name" class="text-muted">— {{ line.variant_name }}</span>
                                        <Badge v-if="line.is_live_animal" variant="active" size="sm" dot class="ml-1">
                                            Live animal
                                        </Badge>
                                        <Badge v-if="line.is_perishable" variant="danger" size="sm" dot class="ml-1">
                                            Perishable
                                        </Badge>
                                    </span>
                                    <span class="figures ml-auto shrink-0">{{ line.line_total }}</span>
                                </li>
                            </ul>

                            <div class="space-y-2">
                                <label
                                    v-for="method in group.delivery_methods"
                                    :key="method.value"
                                    class="flex cursor-pointer gap-3 rounded-sm border-2 p-3"
                                    :class="
                                        form.delivery_methods[group.seller.id] === method.value
                                            ? 'border-ink bg-chrome-100 dark:border-wash dark:bg-grain-800'
                                            : 'border-grain-300 dark:border-grain-600'
                                    "
                                >
                                    <input
                                        v-model="form.delivery_methods[group.seller.id]"
                                        type="radio"
                                        :name="`delivery-${group.seller.id}`"
                                        :value="method.value"
                                        class="mt-1 size-4 shrink-0 accent-enamel"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-baseline justify-between gap-2">
                                            <span class="font-semibold">{{ method.label }}</span>
                                            <span class="figures text-sm font-semibold">
                                                {{ method.fee_kobo > 0 ? method.fee : 'No charge' }}
                                            </span>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-muted">{{ method.description }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    </div>

                    <p v-if="form.errors.delivery_methods" class="mt-3 text-sm font-semibold text-cockscomb">
                        {{ form.errors.delivery_methods }}
                    </p>

                    <p v-if="hasLiveOrPerishable" class="mt-4 text-sm text-muted">
                        Live and perishable goods travel on the day. Keep your phone on — the seller will call before
                        setting out.
                    </p>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-lg">How you are paying</h2>
                    </template>

                    <!-- No gateway has been given keys yet. Saying so beats an
                         empty box and a button that fails validation. -->
                    <p v-if="!gateways.length" class="text-sm font-semibold text-cockscomb dark:text-cockscomb-300">
                        No payment provider has been set up yet, so this order cannot be paid for. Your cart is safe —
                        please try again shortly.
                    </p>

                    <fieldset v-else>
                        <legend class="sr-only">Payment method</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="gateway in gateways"
                                :key="gateway.key"
                                class="flex cursor-pointer items-center gap-3 rounded-sm border-2 p-3"
                                :class="
                                    form.gateway === gateway.key
                                        ? 'border-ink bg-chrome-100 dark:border-wash dark:bg-grain-800'
                                        : 'border-grain-300 dark:border-grain-600'
                                "
                            >
                                <input
                                    v-model="form.gateway"
                                    type="radio"
                                    name="gateway"
                                    :value="gateway.key"
                                    class="size-4 shrink-0 accent-enamel"
                                />
                                <span class="font-semibold">{{ gateway.name }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <p v-if="gateways.length" class="mt-3 text-xs text-muted">
                        Card, bank transfer and USSD are all handled on the next page. We never see your card details.
                    </p>
                    <p v-if="form.errors.gateway" class="mt-2 text-sm font-semibold text-cockscomb">
                        {{ form.errors.gateway }}
                    </p>
                </Card>
            </div>

            <div class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-lg">What you pay</h2>
                    </template>

                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Goods</dt>
                            <dd class="figures font-semibold">{{ subtotal }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Delivery</dt>
                            <dd class="figures font-semibold">
                                {{ deliveryKobo > 0 ? naira(deliveryKobo) : 'No charge' }}
                            </dd>
                        </div>
                    </dl>

                    <hr class="seam my-3" />

                    <div class="flex items-baseline justify-between gap-3">
                        <span class="font-display text-sm font-bold uppercase tracking-wider">Total</span>
                        <span class="figures text-xl font-bold">{{ naira(grandTotalKobo) }}</span>
                    </div>

                    <template #footer>
                        <Button
                            type="submit"
                            size="lg"
                            block
                            :loading="form.processing"
                            :disabled="form.processing || !groups.length || !gateways.length"
                        >
                            Pay {{ naira(grandTotalKobo) }}
                        </Button>
                        <p class="mt-2 text-center text-2xs text-muted">
                            You will be taken to a secure payment page and brought back here.
                        </p>
                    </template>
                </Card>
            </div>
        </form>
    </PublicLayout>
</template>
