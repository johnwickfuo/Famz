<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import Modal from '@/Components/Ui/Modal.vue';

/**
 * One engagement, from the client's side.
 *
 * The contact panel renders only when the server sent one. `contact` is null
 * before payment — not hidden, not blanked, absent — so there is nothing on
 * this page for somebody to find by opening the console.
 */
const props = defineProps({
    engagement: { type: Object, required: true },
    mentor: { type: Object, default: null },
    contact: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: null },
    review: { type: Object, default: null },
    mayReview: { type: Boolean, default: false },
    reviewRefusal: { type: String, default: null },
    mayDispute: { type: Boolean, default: false },
    disputeReasons: { type: Array, default: () => [] },
    liveDispute: { type: Object, default: null },
});

const payForm = useForm({
    gateway: props.gateways.some((g) => g.key === props.defaultGateway)
        ? props.defaultGateway
        : (props.gateways[0]?.key ?? null),
});

const reviewForm = useForm({ rating: 5, comment: '' });
const disputeForm = useForm({ reason: props.disputeReasons[0]?.value ?? '', description: '' });

const disputing = ref(false);
const confirming = ref(false);
const working = ref(false);

function pay() {
    payForm.post(route('mentorship.pay', props.engagement.reference));
}

function confirm() {
    working.value = true;
    router.post(
        route('mentorship.confirm', props.engagement.reference),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                confirming.value = false;
            },
        },
    );
}
</script>

<template>
    <PublicLayout :title="`Mentorship ${engagement.reference}`">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="stencil text-muted">
                    <Link :href="route('mentorship.index')" class="underline-offset-4 hover:underline">
                        My mentorship
                    </Link>
                </p>
                <h1 class="mt-1 text-2xl sm:text-3xl">{{ engagement.package }}</h1>
                <p class="figures mt-1 text-sm text-muted">
                    {{ engagement.reference }}<span v-if="mentor"> · with {{ mentor.name }}</span>
                </p>
            </div>
            <Badge :variant="engagement.tone" dot>{{ engagement.status_label }}</Badge>
        </div>

        <hr class="seam my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <!--
                    The whole point of the product, in one card. It exists only
                    when the server decided this engagement has earned it.
                -->
                <Card v-if="contact">
                    <template #header>
                        <h2 class="text-base">How to reach {{ mentor?.name }}</h2>
                    </template>

                    <p class="stencil text-muted">{{ contact.method }}</p>
                    <p class="figures mt-1 break-all text-lg font-semibold">{{ contact.value }}</p>

                    <Button
                        v-if="contact.action_url"
                        class="mt-3"
                        as="a"
                        :href="contact.action_url"
                        target="_blank"
                        rel="noopener"
                    >
                        Open {{ contact.method }}
                    </Button>

                    <p class="mt-3 text-xs text-muted">
                        They have your phone number too. Keep the conversation going here if anything goes wrong — a
                        dispute is easier to settle when we can see what was agreed.
                    </p>
                </Card>

                <Card v-else>
                    <template #header>
                        <h2 class="text-base">Contact details</h2>
                    </template>

                    <p class="text-sm">
                        Hidden until this engagement is paid for. The moment it is, their
                        {{ mentor?.contact_method?.toLowerCase() }} appears here and they get your phone number.
                    </p>
                </Card>

                <Card v-if="engagement.brief">
                    <template #header>
                        <h2 class="text-base">What you asked for</h2>
                    </template>
                    <p class="whitespace-pre-line text-sm">{{ engagement.brief }}</p>
                </Card>

                <!-- Waiting on the client: the step that releases the money. -->
                <Card v-if="engagement.awaiting_confirmation">
                    <template #header>
                        <h2 class="text-base">Is this finished?</h2>
                    </template>

                    <p class="text-sm">
                        {{ mentor?.name }} says the work is done. Confirm it and they are paid. If you do nothing, it
                        confirms itself
                        <span class="font-semibold">{{ engagement.auto_confirm_in }}</span>
                        ({{ engagement.auto_confirm_at }}).
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button :loading="working" @click="confirming = true">Yes, it is finished</Button>
                        <Button v-if="mayDispute" variant="danger" @click="disputing = true">
                            No — something went wrong
                        </Button>
                    </div>
                </Card>

                <Card :padded="false">
                    <template #header>
                        <h2 class="text-base">Payments</h2>
                    </template>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[30rem] text-sm">
                            <thead>
                                <tr class="border-b-2 border-ink text-left dark:border-wash">
                                    <th class="stencil px-4 py-2">Period</th>
                                    <th class="stencil px-4 py-2">Reference</th>
                                    <th class="stencil px-4 py-2 text-right">Amount</th>
                                    <th class="stencil px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="invoice in invoices"
                                    :key="invoice.reference"
                                    class="border-b border-dashed border-grain-300 dark:border-grain-700"
                                >
                                    <td class="px-4 py-3">{{ invoice.period }}</td>
                                    <td class="figures px-4 py-3 text-xs">{{ invoice.reference }}</td>
                                    <td class="figures px-4 py-3 text-right">{{ invoice.amount }}</td>
                                    <td class="px-4 py-3">{{ invoice.status_label }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <!-- Reviewing: only after it is finished, and never visible until moderated. -->
                <Card v-if="mayReview">
                    <template #header>
                        <h2 class="text-base">Leave a review</h2>
                    </template>

                    <form @submit.prevent="reviewForm.post(route('mentorship.review', engagement.reference))">
                        <Select
                            v-model="reviewForm.rating"
                            label="Out of five"
                            :options="[5, 4, 3, 2, 1].map((n) => ({ value: n, label: `${n} / 5` }))"
                            :error="reviewForm.errors.rating"
                        />

                        <Textarea
                            v-model="reviewForm.comment"
                            class="mt-4"
                            label="Anything you want to say (optional)"
                            :rows="4"
                            :error="reviewForm.errors.comment"
                        />

                        <p class="mt-2 text-xs text-muted">
                            We read every review before it goes on their profile, so it will not appear straight away.
                        </p>

                        <Button class="mt-3" type="submit" :loading="reviewForm.processing">Send it</Button>
                    </form>
                </Card>

                <Card v-else-if="review">
                    <template #header>
                        <h2 class="text-base">Your review</h2>
                    </template>

                    <p class="figures text-sm font-semibold">{{ review.rating }}/5</p>
                    <p v-if="review.comment" class="mt-1 text-sm">{{ review.comment }}</p>
                    <p class="mt-2 text-xs text-muted">{{ review.status_label }}</p>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">This engagement</h2>
                    </template>

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="stencil text-muted">Price</dt>
                            <dd class="figures mt-0.5 text-lg font-bold">{{ engagement.price }}</dd>
                        </div>
                        <div>
                            <dt class="stencil text-muted">Paid so far</dt>
                            <dd class="figures mt-0.5">{{ engagement.paid_to_date }}</dd>
                        </div>
                        <div v-if="engagement.started">
                            <dt class="stencil text-muted">Started</dt>
                            <dd class="figures mt-0.5">{{ engagement.started }}</dd>
                        </div>
                    </dl>

                    <template v-if="invoices.some((i) => i.payable)">
                        <hr class="seam my-4" />

                        <form @submit.prevent="pay">
                            <p v-if="!gateways.length" class="text-sm font-semibold text-cockscomb">
                                No payment provider has been set up yet, so this cannot be paid for at the moment.
                            </p>

                            <template v-else>
                                <Select
                                    v-model="payForm.gateway"
                                    label="Pay with"
                                    :options="gateways.map((g) => ({ value: g.key, label: g.name }))"
                                    :error="payForm.errors.gateway"
                                />

                                <Button class="mt-3" type="submit" block :loading="payForm.processing">
                                    Pay {{ engagement.price_plain }}
                                </Button>

                                <p class="mt-2 text-xs text-muted">
                                    Held by us until you confirm the work was done.
                                </p>
                            </template>
                        </form>
                    </template>

                    <template v-if="liveDispute || mayDispute">
                        <hr class="seam my-4" />

                        <Button
                            v-if="liveDispute"
                            :href="liveDispute.url"
                            variant="secondary"
                            size="sm"
                            block
                        >
                            Open dispute · {{ liveDispute.status }}
                        </Button>

                        <Button v-else variant="secondary" size="sm" block @click="disputing = true">
                            Something went wrong
                        </Button>
                    </template>
                </Card>
            </aside>
        </div>

        <Modal :show="confirming" title="Confirm this is finished" @close="confirming = false">
            <p class="text-sm">
                Your mentor is paid as soon as you confirm, and this cannot be undone. If anything is outstanding, say
                so before confirming rather than after.
            </p>

            <template #footer>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="secondary" @click="confirming = false">Not yet</Button>
                    <Button :loading="working" @click="confirm">Confirm and pay them</Button>
                </div>
            </template>
        </Modal>

        <Modal :show="disputing" title="What went wrong?" @close="disputing = false">
            <form @submit.prevent="disputeForm.post(route('mentorship.dispute', engagement.reference))">
                <Select
                    v-model="disputeForm.reason"
                    label="The problem"
                    :options="disputeReasons.map((r) => ({ value: r.value, label: r.label }))"
                    :error="disputeForm.errors.reason"
                />

                <Textarea
                    v-model="disputeForm.description"
                    class="mt-4"
                    label="Tell us what happened"
                    :rows="5"
                    :error="disputeForm.errors.description"
                    required
                />

                <p class="mt-2 text-xs text-muted">
                    The money stays where it is until an administrator has read both sides.
                </p>

                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <Button variant="secondary" type="button" @click="disputing = false">Cancel</Button>
                    <Button variant="danger" type="submit" :loading="disputeForm.processing">Raise it</Button>
                </div>
            </form>
        </Modal>
    </PublicLayout>
</template>
