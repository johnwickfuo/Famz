<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';

/**
 * One farm setup request, from the client's side.
 *
 * There is deliberately no "Accept" button. Committing to a farm build is a
 * conversation and a contract — a button would misrepresent what happens next,
 * and would leave somebody thinking they had ordered a farm. What this page
 * offers instead is the proposal, the PDF, and a phone number.
 */
const props = defineProps({
    request: { type: Object, required: true },
    studyFee: { type: Object, default: null },
    quotation: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    contact: { type: Object, default: () => ({}) },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: null },
});

const payForm = useForm({
    gateway: props.gateways.some((g) => g.key === props.defaultGateway)
        ? props.defaultGateway
        : (props.gateways[0]?.key ?? null),
});

const awaitingFee = computed(() => props.request.awaits_study_fee);

/** Older versions only — the current one is already shown in full above. */
const olderVersions = computed(() => props.history.filter((entry) => !entry.is_current));

function payStudyFee() {
    payForm.post(route('quotations.studyFee', props.request.reference));
}
</script>

<template>
    <PublicLayout :title="`Farm setup ${request.reference}`">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="stencil text-enamel dark:text-chrome">Farm setup</p>
                <h1 class="figures mt-1 text-2xl sm:text-3xl">{{ request.reference }}</h1>
                <p class="mt-1 text-sm text-muted">Asked {{ request.submitted_at }}</p>
            </div>

            <Badge :variant="request.tone" dot>{{ request.status_label }}</Badge>
        </div>

        <hr class="seam seam-chrome my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <!-- The gate, when it has not been passed. -->
                <Card v-if="awaitingFee && studyFee">
                    <template #header>
                        <h2 class="text-base">One step before we start</h2>
                    </template>

                    <p class="text-sm">
                        There is a study fee of
                        <strong class="figures">{{ studyFee.amount }}</strong
                        >. {{ studyFee.covers }}
                    </p>

                    <p class="mt-3 text-sm text-muted">
                        If you go ahead with the project, we normally take this off your first invoice.
                    </p>

                    <div v-if="gateways.length" class="mt-4">
                        <Select
                            v-if="gateways.length > 1"
                            v-model="payForm.gateway"
                            label="Pay with"
                            class="max-w-xs"
                        >
                            <option v-for="gateway in gateways" :key="gateway.key" :value="gateway.key">
                                {{ gateway.name }}
                            </option>
                        </Select>

                        <Button class="mt-3" :disabled="payForm.processing" @click="payStudyFee">
                            {{ payForm.processing ? 'Starting…' : `Pay ${studyFee.amount}` }}
                        </Button>
                    </div>
                    <p v-else class="mt-4 text-sm font-semibold text-cockscomb">
                        No payment provider has been set up yet, so this cannot be paid at the moment.
                    </p>
                </Card>

                <!-- Between paying and the proposal landing. -->
                <Card v-else-if="!quotation">
                    <template #header>
                        <h2 class="text-base">We are costing it</h2>
                    </template>

                    <p class="text-sm">
                        Your study fee cleared<template v-if="studyFee?.paid_at"> on {{ studyFee.paid_at }}</template
                        >. Somebody is working through the housing, equipment and stock for your site now. This takes a
                        few days to do properly, and we will email you the moment it is ready.
                    </p>
                </Card>

                <!-- The proposal itself. -->
                <template v-else>
                    <Card>
                        <template #header>
                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div class="min-w-0">
                                    <h2 class="text-base">{{ quotation.title }}</h2>
                                    <p class="mt-0.5 text-xs text-muted">
                                        Version {{ quotation.version }} · sent {{ quotation.sent_at }}
                                    </p>
                                </div>
                                <Badge :variant="quotation.tone" dot>{{ quotation.status_label }}</Badge>
                            </div>
                        </template>

                        <p v-if="quotation.executive_summary" class="whitespace-pre-line text-sm">
                            {{ quotation.executive_summary }}
                        </p>

                        <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <p class="stencil text-muted">Total</p>
                                <p class="figures mt-1 text-3xl font-bold">{{ quotation.total }}</p>
                            </div>

                            <Button :href="quotation.pdf_url" variant="secondary">Download the proposal</Button>
                        </div>
                    </Card>

                    <!-- The costs, grouped the way the admin grouped them. -->
                    <Card>
                        <template #header>
                            <h2 class="text-base">What it costs</h2>
                        </template>

                        <div v-for="section in quotation.sections" :key="section.name" class="mb-6 last:mb-0">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="stencil text-ink dark:text-wash">{{ section.name }}</h3>
                                <span class="figures text-sm font-semibold">{{ section.total }}</span>
                            </div>

                            <hr class="seam my-2" />

                            <!--
                                A list on a phone, a table on a laptop. A
                                four-column table at 360px is unreadable, and
                                this is exactly the document somebody opens on
                                their phone in the middle of a market.
                            -->
                            <ul class="space-y-3 sm:hidden">
                                <li v-for="(item, index) in section.items" :key="index" class="text-sm">
                                    <p>{{ item.description }}</p>
                                    <p class="figures mt-0.5 text-xs text-muted">
                                        {{ item.unit ? `${item.quantity}\u00a0${item.unit}` : item.quantity }}
                                        × {{ item.unit_price }}
                                        <span class="float-right font-semibold text-ink dark:text-wash">
                                            {{ item.total }}
                                        </span>
                                    </p>
                                </li>
                            </ul>

                            <table class="hidden w-full text-sm sm:table">
                                <thead>
                                    <tr class="stencil text-left text-muted">
                                        <th class="pb-1 font-normal">Item</th>
                                        <th class="pb-1 text-right font-normal">Qty</th>
                                        <th class="pb-1 text-right font-normal">Unit price</th>
                                        <th class="pb-1 text-right font-normal">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(item, index) in section.items"
                                        :key="index"
                                        class="border-t border-grain-200 dark:border-grain-700"
                                    >
                                        <td class="py-2 pr-3">{{ item.description }}</td>
                                        <td class="figures whitespace-nowrap py-2 text-right">
                                            {{ item.unit ? `${item.quantity}\u00a0${item.unit}` : item.quantity }}
                                        </td>
                                        <td class="figures whitespace-nowrap py-2 text-right">
                                            {{ item.unit_price }}
                                        </td>
                                        <td class="figures whitespace-nowrap py-2 text-right font-semibold">
                                            {{ item.total }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <hr class="seam seam-chrome my-4" />

                        <dl class="ml-auto max-w-xs space-y-1 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted">Subtotal</dt>
                                <dd class="figures">{{ quotation.subtotal }}</dd>
                            </div>
                            <div v-if="quotation.contingency !== '₦0'" class="flex justify-between gap-4">
                                <dt class="text-muted">Contingency ({{ quotation.contingency_percent }}%)</dt>
                                <dd class="figures">{{ quotation.contingency }}</dd>
                            </div>
                            <div
                                class="flex justify-between gap-4 border-t-2 border-ink pt-2 text-lg font-bold dark:border-wash"
                            >
                                <dt>Total</dt>
                                <dd class="figures">{{ quotation.total }}</dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- The prose that stops arguments later. -->
                    <Card v-if="quotation.scope_of_work || quotation.assumptions || quotation.exclusions">
                        <template #header>
                            <h2 class="text-base">The detail</h2>
                        </template>

                        <template v-if="quotation.scope_of_work">
                            <h3 class="stencil text-muted">What we will do</h3>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ quotation.scope_of_work }}</p>
                        </template>

                        <template v-if="quotation.assumptions">
                            <h3 class="stencil mt-5 text-muted">What we have assumed</h3>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ quotation.assumptions }}</p>
                        </template>

                        <template v-if="quotation.exclusions">
                            <h3 class="stencil mt-5 text-muted">What is not included</h3>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ quotation.exclusions }}</p>
                        </template>

                        <template v-if="quotation.timeline_description">
                            <h3 class="stencil mt-5 text-muted">How long it takes</h3>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ quotation.timeline_description }}</p>
                        </template>

                        <template v-if="quotation.payment_terms">
                            <h3 class="stencil mt-5 text-muted">Payment terms</h3>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ quotation.payment_terms }}</p>
                        </template>
                    </Card>
                </template>

                <!-- What they told us, so they can check we understood it. -->
                <Card>
                    <template #header>
                        <h2 class="text-base">What you asked for</h2>
                    </template>

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="stencil text-muted">Project</dt>
                            <dd class="mt-0.5 text-sm">{{ request.project_type }} · {{ request.farm_type }}</dd>
                        </div>
                        <div v-if="request.capacity">
                            <dt class="stencil text-muted">Size</dt>
                            <dd class="figures mt-0.5 text-sm">{{ request.capacity }}</dd>
                        </div>
                        <div v-if="request.where">
                            <dt class="stencil text-muted">Where</dt>
                            <dd class="mt-0.5 text-sm">{{ request.where }}</dd>
                        </div>
                        <div v-if="request.land_size || request.owns_land">
                            <dt class="stencil text-muted">Land</dt>
                            <dd class="mt-0.5 text-sm">
                                {{ request.land_size }}
                                <span class="text-muted">
                                    {{ request.owns_land ? '· you own it' : '· not secured yet' }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="request.budget">
                            <dt class="stencil text-muted">Budget</dt>
                            <dd class="figures mt-0.5 text-sm">{{ request.budget }}</dd>
                        </div>
                        <div v-if="request.target_start_date">
                            <dt class="stencil text-muted">Wanted to start</dt>
                            <dd class="mt-0.5 text-sm">{{ request.target_start_date }}</dd>
                        </div>
                        <div v-if="request.power">
                            <dt class="stencil text-muted">Power</dt>
                            <dd class="mt-0.5 text-sm">{{ request.power }}</dd>
                        </div>
                        <div v-if="request.water">
                            <dt class="stencil text-muted">Water</dt>
                            <dd class="mt-0.5 text-sm">{{ request.water }}</dd>
                        </div>
                        <div v-if="request.scopes.length" class="sm:col-span-2">
                            <dt class="stencil text-muted">Scope</dt>
                            <dd class="mt-0.5 text-sm">{{ request.scopes.join(' · ') }}</dd>
                        </div>
                        <div v-if="request.notes" class="sm:col-span-2">
                            <dt class="stencil text-muted">Your notes</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm">{{ request.notes }}</dd>
                        </div>
                    </dl>

                    <div v-if="request.photos.length" class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <a
                            v-for="(photo, index) in request.photos"
                            :key="index"
                            :href="photo"
                            target="_blank"
                            rel="noopener"
                            class="block"
                        >
                            <img
                                :src="photo"
                                alt=""
                                class="aspect-square w-full rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                            />
                        </a>
                    </div>
                </Card>
            </div>

            <!-- The sidebar: how long the price stands, and how to say yes. -->
            <div class="mt-5 w-full lg:mt-0 lg:w-80 lg:shrink-0">
                <div class="space-y-5 lg:sticky lg:top-4">
                    <Card v-if="quotation">
                        <template #header>
                            <h2 class="text-base">How long this stands</h2>
                        </template>

                        <p class="figures text-lg font-bold" :class="quotation.is_live ? '' : 'text-cockscomb'">
                            {{ quotation.validity_countdown }}
                        </p>
                        <p class="mt-1 text-sm text-muted">Valid until {{ quotation.valid_until }}.</p>

                        <p
                            v-if="quotation.is_lapsing_soon"
                            class="mt-3 rounded-sm border-2 border-chrome bg-chrome-50 p-2 text-xs dark:bg-grain-800"
                        >
                            Building materials and stock move quickly. If you are close to deciding, call us before this
                            date and we will hold it.
                        </p>
                        <p v-else-if="!quotation.is_live" class="mt-3 text-xs text-muted">
                            This is not us withdrawing the offer — prices simply move. Call us and we will re-cost it,
                            which is usually quick since the design work is done.
                        </p>
                    </Card>

                    <!-- Acceptance is offline by design. -->
                    <Card v-if="quotation">
                        <template #header>
                            <h2 class="text-base">To go ahead</h2>
                        </template>

                        <p class="text-sm">
                            Call or email us and quote
                            <strong class="figures">{{ request.reference }}</strong
                            >. There is nothing to click here — we will talk it through and put a contract in front of
                            you.
                        </p>

                        <hr class="seam my-4" />

                        <p class="font-semibold">{{ contact.name }}</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-if="contact.phone">
                                <a class="figures underline" :href="`tel:${contact.phone}`">{{ contact.phone }}</a>
                            </li>
                            <li v-if="contact.whatsapp && contact.whatsapp !== contact.phone" class="figures">
                                {{ contact.whatsapp }} <span class="text-muted">(WhatsApp)</span>
                            </li>
                            <li v-if="contact.email">
                                <a class="break-all underline" :href="`mailto:${contact.email}`">{{ contact.email }}</a>
                            </li>
                            <li v-if="contact.address" class="text-muted">{{ contact.address }}</li>
                        </ul>

                        <p v-if="!contact.phone && !contact.email" class="text-sm text-muted">
                            Contact details have not been set up yet.
                        </p>
                    </Card>

                    <!-- Earlier versions, because comparing them is reasonable. -->
                    <Card v-if="olderVersions.length">
                        <template #header>
                            <h2 class="text-base">Earlier versions</h2>
                        </template>

                        <ul class="space-y-3">
                            <li
                                v-for="entry in olderVersions"
                                :key="entry.version"
                                class="flex items-start justify-between gap-3 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="font-semibold">Version {{ entry.version }}</p>
                                    <p class="figures mt-0.5 text-xs text-muted">
                                        {{ entry.total }} · {{ entry.sent_at }}
                                    </p>
                                </div>
                                <a class="shrink-0 text-xs underline" :href="entry.pdf_url">PDF</a>
                            </li>
                        </ul>
                    </Card>

                    <Card v-if="studyFee?.paid">
                        <template #header>
                            <h2 class="text-base">Study fee</h2>
                        </template>

                        <p class="figures text-lg font-bold">{{ studyFee.amount }}</p>
                        <p class="mt-1 text-sm text-muted">Paid {{ studyFee.paid_at }}.</p>
                    </Card>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
