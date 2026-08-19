<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import { naira } from '@/Support/money';

const props = defineProps({
    purchase: { type: Object, required: true },
    address: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
    deliveryMethods: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: 'paystack' },
});

const form = useForm({
    name: props.address.name ?? '',
    phone: props.address.phone ?? '',
    address: '',
    state: props.address.state ?? '',
    lga: props.address.lga ?? '',
    note: '',
    delivery_method: props.deliveryMethods[0]?.value ?? '',
    gateway: props.defaultGateway,
});

const quoting = ref(false);

watch(
    () => form.state,
    (state) => {
        if (!state) return;
        quoting.value = true;
        router.reload({
            only: ['deliveryMethods'],
            data: { state },
            onFinish: () => (quoting.value = false),
        });
    },
);

watch(
    () => props.deliveryMethods,
    (methods) => {
        const offered = methods.map((m) => m.value);
        if (!offered.includes(form.delivery_method)) {
            form.delivery_method = offered[0] ?? '';
        }
    },
);

const chosen = computed(() =>
    props.deliveryMethods.find((method) => method.value === form.delivery_method),
);

const totalKobo = computed(() => props.purchase.subtotal_kobo + (chosen.value?.fee_kobo ?? 0));

function submit() {
    form.post(route('negotiated.store', props.purchase.token), { preserveScroll: true });
}
</script>

<template>
    <PublicLayout title="Your agreed price">
        <div class="mx-auto max-w-3xl">
            <h1 class="text-2xl sm:text-3xl">Your agreed price</h1>
            <p class="mt-1 text-sm text-muted">
                You and {{ purchase.seller.name }} settled on this. It is yours alone.
            </p>

            <hr class="seam my-5" />

            <!-- The deadline is the whole point of this page. It goes first. -->
            <div
                v-if="purchase.has_expired"
                class="mb-5 rounded-sm border-2 border-cockscomb bg-cockscomb-100 p-4 dark:bg-cockscomb-800"
            >
                <p class="font-display text-sm font-bold uppercase tracking-wider">This price is no longer held</p>
                <p class="mt-1 text-sm">
                    The window closed on {{ purchase.expires_at }} and the stock has gone back on sale. You are
                    welcome to make another offer.
                </p>
                <Button class="mt-3" size="sm" :href="route('catalogue.home')">Back to the market</Button>
            </div>

            <div v-else class="mb-5 rounded-sm border-2 border-ink bg-chrome-100 p-4 dark:border-wash dark:bg-grain-800">
                <p class="font-display text-sm font-bold uppercase tracking-wider">
                    Held for you until {{ purchase.expires_at }}
                </p>
                <p class="mt-1 text-sm">
                    That is {{ purchase.expires_in }}. After that the stock goes back on sale at the usual price.
                </p>
            </div>

            <Card class="mb-5">
                <template #header>
                    <h2 class="text-lg">What you agreed</h2>
                </template>

                <div class="flex gap-4">
                    <img
                        v-if="purchase.image"
                        :src="purchase.image"
                        :alt="purchase.name"
                        class="size-20 shrink-0 rounded-sm border-2 border-ink object-cover dark:border-wash"
                        loading="lazy"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ purchase.name }}</p>
                        <p class="figures mt-1 text-sm text-muted">
                            {{ purchase.quantity }} {{ purchase.unit }} × {{ purchase.unit_price }}
                        </p>
                        <p v-if="purchase.list_price" class="mt-1 text-sm">
                            <span class="text-muted line-through">{{ purchase.list_price }} each</span>
                            <span v-if="purchase.saving" class="ml-2 font-semibold text-enamel dark:text-chrome">
                                You save {{ purchase.saving }}
                            </span>
                        </p>
                    </div>

                    <p class="figures shrink-0 text-lg font-bold">{{ purchase.subtotal }}</p>
                </div>
            </Card>

            <form v-if="!purchase.has_expired" class="space-y-5" @submit.prevent="submit">
                <Card>
                    <template #header>
                        <h2 class="text-lg">Where it is going</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input v-model="form.name" label="Full name" required :error="form.errors.name" />
                        <Input v-model="form.phone" label="Phone" type="tel" figures required :error="form.errors.phone" />

                        <div class="sm:col-span-2">
                            <Textarea
                                v-model="form.address"
                                label="Street address"
                                :rows="3"
                                :maxlength="400"
                                required
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
                        <Input v-model="form.lga" label="Local government area" required :error="form.errors.lga" />

                        <div class="sm:col-span-2">
                            <Textarea
                                v-model="form.note"
                                label="Note for the seller"
                                :rows="2"
                                :maxlength="500"
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
                    </template>

                    <p v-if="!form.state" class="text-sm text-muted">
                        Choose a state above and we will show what this seller charges to get there.
                    </p>

                    <fieldset v-else class="space-y-2">
                        <legend class="sr-only">Delivery</legend>
                        <label
                            v-for="method in deliveryMethods"
                            :key="method.value"
                            class="flex cursor-pointer gap-3 rounded-sm border-2 p-3"
                            :class="
                                form.delivery_method === method.value
                                    ? 'border-ink bg-chrome-100 dark:border-wash dark:bg-grain-800'
                                    : 'border-grain-300 dark:border-grain-600'
                            "
                        >
                            <input
                                v-model="form.delivery_method"
                                type="radio"
                                name="delivery"
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
                    </fieldset>

                    <p v-if="form.errors.delivery_method" class="mt-2 text-sm font-semibold text-cockscomb">
                        {{ form.errors.delivery_method }}
                    </p>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-lg">Paying</h2>
                    </template>

                    <p v-if="!gateways.length" class="text-sm font-semibold text-cockscomb dark:text-cockscomb-300">
                        No payment provider has been set up yet, so this cannot be paid for. Your price is still held.
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

                    <hr class="seam my-4" />

                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Goods, at your price</dt>
                            <dd class="figures font-semibold">{{ purchase.subtotal }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Delivery</dt>
                            <dd class="figures font-semibold">
                                {{ chosen && chosen.fee_kobo > 0 ? chosen.fee : 'No charge' }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-t-2 border-dashed border-grain-200 pt-2 dark:border-grain-700">
                            <dt class="font-display text-sm font-bold uppercase tracking-wider">Total</dt>
                            <dd class="figures text-xl font-bold">{{ naira(totalKobo) }}</dd>
                        </div>
                    </dl>

                    <template #footer>
                        <Button
                            type="submit"
                            size="lg"
                            block
                            :loading="form.processing"
                            :disabled="form.processing || !gateways.length || !form.delivery_method"
                        >
                            Pay {{ naira(totalKobo) }}
                        </Button>
                    </template>
                </Card>
            </form>
        </div>
    </PublicLayout>
</template>
