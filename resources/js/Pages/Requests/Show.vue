<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import Badge from '@/Components/Ui/Badge.vue';
import OfferStatusBadge from '@/Components/Offers/OfferStatusBadge.vue';
import { naira } from '@/Support/money';

const props = defineProps({
    request: { type: Object, required: true },
    seller: { type: Object, default: null },
    isMine: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const form = useForm({
    quantity: props.request.quantity,
    unit_price: '',
    delivery_days: '',
    message: '',
});

const total = computed(() => {
    const price = Math.round(Number(form.unit_price || 0) * 100);
    return price > 0 ? naira(price * Number(form.quantity || 0)) : null;
});

function submit() {
    form.post(route('requests.offer', props.request.slug), {
        preserveScroll: true,
        onSuccess: () => form.reset('unit_price', 'message', 'delivery_days'),
    });
}
</script>

<template>
    <PublicLayout :title="request.title">
        <div class="mx-auto max-w-4xl">
            <Link :href="route('requests.index')" class="stencil text-sm text-muted underline-offset-4 hover:underline">
                ← All requests
            </Link>

            <div class="mt-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div class="min-w-0">
                    <h1 class="text-2xl leading-tight sm:text-3xl">{{ request.title }}</h1>
                    <p class="mt-1 text-sm text-muted">
                        <span class="figures">{{ request.reference }}</span> · posted {{ request.posted_at }}
                        <span v-if="request.buyer"> by {{ request.buyer }}</span>
                    </p>
                </div>
                <OfferStatusBadge :status="request.status" :label="request.status_label" />
            </div>

            <hr class="seam my-5" />

            <div class="lg:flex lg:items-start lg:gap-6">
                <div class="min-w-0 flex-1 space-y-5">
                    <Card>
                        <template #header>
                            <h2 class="text-lg">What is wanted</h2>
                        </template>

                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="stencil text-muted">Quantity</dt>
                                <dd class="figures mt-0.5 text-base font-semibold">
                                    {{ request.quantity }} {{ request.unit }}
                                </dd>
                            </div>
                            <div v-if="request.budget">
                                <dt class="stencil text-muted">Budget</dt>
                                <dd class="figures mt-0.5 text-base font-semibold">{{ request.budget }}</dd>
                            </div>
                            <div>
                                <dt class="stencil text-muted">Delivered to</dt>
                                <dd class="mt-0.5 text-base">{{ request.location }}</dd>
                            </div>
                            <div v-if="request.needed_by">
                                <dt class="stencil text-muted">Needed by</dt>
                                <dd class="mt-0.5 text-base">{{ request.needed_by }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <Badge v-if="request.category" variant="neutral">{{ request.category }}</Badge>
                            <Badge v-if="request.accepts_partial" variant="active">
                                Part of the order is fine
                            </Badge>
                            <Badge v-else variant="pending">All of it, or nothing</Badge>
                        </div>

                        <hr class="seam my-4" />

                        <p class="whitespace-pre-line text-sm leading-relaxed">{{ request.description }}</p>

                        <ul v-if="request.images.length" class="mt-4 flex flex-wrap gap-2">
                            <li v-for="(url, index) in request.images" :key="index">
                                <a :href="url" target="_blank" rel="noopener">
                                    <img
                                        :src="url"
                                        :alt="`Photograph ${index + 1}`"
                                        class="size-24 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                        loading="lazy"
                                    />
                                </a>
                            </li>
                        </ul>
                    </Card>
                </div>

                <div class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                    <Card class="lg:sticky lg:top-4">
                        <template #header>
                            <h2 class="text-lg">{{ isMine ? 'Your request' : 'Make an offer' }}</h2>
                            <p class="figures mt-1 text-sm text-muted">
                                {{ request.offer_count }} offer{{ request.offer_count === 1 ? '' : 's' }} so far
                            </p>
                        </template>

                        <!--
                            One default slot with branches, and a single named
                            footer slot beside it: a named slot nested inside a
                            v-if block is not a slot at all, it is a stray
                            template the compiler cannot place.
                        -->
                        <p v-if="isMine" class="text-sm text-muted">
                            Compare what you have been offered and take the one that suits.
                        </p>

                        <p v-else-if="!user" class="text-sm">
                            Sign in as an approved seller in this category to make an offer.
                        </p>

                        <p v-else-if="!seller" class="text-sm">
                            Only approved sellers can answer requests. Applying takes a few minutes.
                        </p>

                        <template v-else-if="seller.existing_offer">
                            <p class="text-sm">Your offer is with the buyer.</p>
                            <dl class="mt-3 space-y-1 text-sm">
                                <div class="flex justify-between gap-3">
                                    <dt class="text-muted">Quantity</dt>
                                    <dd class="figures">{{ seller.existing_offer.quantity }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-muted">Your price</dt>
                                    <dd class="figures font-semibold">{{ seller.existing_offer.unit_price }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-muted">Answer by</dt>
                                    <dd>{{ seller.existing_offer.expires_at }}</dd>
                                </div>
                            </dl>
                        </template>

                        <p v-else-if="!seller.may_offer" class="text-sm text-muted">{{ seller.reason }}</p>

                        <form v-else class="space-y-4" @submit.prevent="submit">
                            <Input
                                v-model="form.quantity"
                                label="How many you can supply"
                                type="number"
                                figures
                                :min="1"
                                :max="request.quantity"
                                required
                                :error="form.errors.quantity"
                            />

                            <Input
                                v-model="form.unit_price"
                                label="Your price each"
                                type="number"
                                step="0.01"
                                prefix="₦"
                                figures
                                required
                                :error="form.errors.unit_price"
                            />

                            <Input
                                v-model="form.delivery_days"
                                label="Days to deliver"
                                type="number"
                                figures
                                :min="0"
                                hint="Optional. Buyers pick on speed as often as on price."
                                :error="form.errors.delivery_days"
                            />

                            <Textarea
                                v-model="form.message"
                                label="Anything to add"
                                :rows="3"
                                :maxlength="1000"
                                :error="form.errors.message"
                            />

                            <p v-if="total" class="figures text-sm">
                                <span class="text-muted">Total</span>
                                <span class="ml-2 font-bold">{{ total }}</span>
                            </p>

                            <p class="text-xs text-muted">
                                The buyer sees your price. Other sellers never do.
                            </p>

                            <Button type="submit" block :loading="form.processing" :disabled="form.processing">
                                Send this offer
                            </Button>
                        </form>

                        <template v-if="isMine || !user || !seller" #footer>
                            <Button v-if="isMine" :href="route('requests.manage', request.slug)" block>
                                Compare offers
                            </Button>
                            <Button v-else-if="!user" :href="route('login')" block>Sign in</Button>
                            <Button
                                v-else
                                :href="route('seller-application.create')"
                                variant="secondary"
                                block
                            >
                                Apply to sell
                            </Button>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
