<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import FileUpload from '@/Components/Ui/FileUpload.vue';
import Button from '@/Components/Ui/Button.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Steps from '@/Components/Ui/Steps.vue';
import CheckboxGroup from '@/Components/Ui/CheckboxGroup.vue';

const props = defineProps({
    application: { type: Object, default: null },
    businessTypes: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    categoryGroups: { type: Array, default: () => [] },
});

const STEPS = ['Business', 'Location', 'Documents', 'What you sell'];

/**
 * Which fields belong to which step, so "Next" can validate only what is on
 * screen instead of dumping every error at the end.
 */
const FIELDS_BY_STEP = [
    ['business_name', 'business_type', 'cac_number'],
    ['address', 'state', 'lga', 'phone', 'whatsapp'],
    ['id_document', 'logo'],
    ['categories', 'description'],
];

const step = ref(0);

const a = props.application;

const form = useForm({
    business_name: a?.business_name ?? '',
    cac_number: a?.cac_number ?? '',
    business_type: a?.business_type ?? '',
    address: a?.address ?? '',
    state: a?.state ?? '',
    lga: a?.lga ?? '',
    phone: a?.phone ?? '',
    whatsapp: a?.whatsapp ?? '',
    id_document: null,
    logo: null,
    description: a?.description ?? '',
    categories: a?.categories ?? [],
});

const isOpen = computed(() => props.application === null || props.application.is_open);
const isLastStep = computed(() => step.value === STEPS.length - 1);

const businessTypeOptions = computed(() =>
    props.businessTypes.map((type) => ({ value: type.value, label: type.label })),
);

/** Client-side gate for moving on — the server validates properly regardless. */
function stepIsComplete(index) {
    switch (index) {
        case 0:
            return form.business_name.trim().length >= 2 && form.business_type !== '';
        case 1:
            return (
                form.address.trim() !== '' &&
                form.state !== '' &&
                form.lga.trim() !== '' &&
                form.phone.trim() !== ''
            );
        case 2:
            return Boolean(form.id_document) || Boolean(props.application?.has_id_document);
        case 3:
            return form.categories.length > 0 && form.description.trim().length >= 40;
        default:
            return true;
    }
}

function next() {
    if (!stepIsComplete(step.value)) return;
    step.value = Math.min(step.value + 1, STEPS.length - 1);
}

function back() {
    step.value = Math.max(step.value - 1, 0);
}

function goTo(index) {
    if (index <= step.value) step.value = index;
}

function submit() {
    form.post(route('seller-application.store'), {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => {
            // Land the applicant on the first step that actually has a problem
            // rather than leaving them on the last one wondering.
            const first = FIELDS_BY_STEP.findIndex((fields) =>
                fields.some((field) => field in errors),
            );

            if (first !== -1) step.value = first;
        },
    });
}

const statusTone = computed(() => {
    switch (props.application?.status) {
        case 'approved':
            return 'active';
        case 'rejected':
            return 'danger';
        default:
            return 'pending';
    }
});
</script>

<template>
    <AppLayout
        title="Sell on the platform"
        :breadcrumbs="[{ label: 'Dashboard', href: route('dashboard') }, { label: 'Sell with us' }]"
    >
        <!-- Where the application currently stands. -->
        <Card v-if="application" class="mb-6">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg">Your application</h2>
                    <Badge :variant="statusTone" dot>{{ application.status_label }}</Badge>
                </div>
            </template>

            <p v-if="application.status === 'approved'" class="text-sm text-muted">
                You are approved to sell. Your seller panel is at
                <a href="/seller" class="font-semibold text-enamel underline underline-offset-4 dark:text-chrome">/seller</a>.
            </p>

            <div v-else-if="application.review_notes" class="rounded-sm border-2 border-cockscomb bg-cockscomb-50 px-3 py-2 dark:bg-grain-800">
                <p class="stencil mb-1 text-cockscomb">
                    {{ application.status === 'rejected' ? 'Why it was not approved' : 'What we still need' }}
                </p>
                <p class="text-sm">{{ application.review_notes }}</p>
            </div>

            <p v-else class="text-sm text-muted">
                We have your application and will email you when it has been reviewed.
            </p>
        </Card>

        <Card v-if="isOpen" as="form" @submit.prevent="submit">
            <template #header>
                <h2 class="text-lg">{{ application ? 'Update your application' : 'Apply to sell' }}</h2>
                <p class="mt-1 text-sm text-muted">
                    Four short steps. Nothing goes live until an administrator has looked at it.
                </p>
                <div class="mt-4">
                    <Steps :steps="STEPS" :current="step" @go="goTo" />
                </div>
            </template>

            <!-- Step 1: the business -->
            <div v-show="step === 0" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <Input
                        v-model="form.business_name"
                        label="Business or trading name"
                        required
                        autofocus
                        hint="The name buyers will see on your listings."
                        :error="form.errors.business_name"
                    />
                </div>

                <Select
                    v-model="form.business_type"
                    label="Type of business"
                    :options="businessTypeOptions"
                    placeholder="Choose one"
                    required
                    :error="form.errors.business_type"
                />

                <Input
                    v-model="form.cac_number"
                    label="CAC number"
                    figures
                    hint="Leave empty if you are not registered — it is not required."
                    :error="form.errors.cac_number"
                />
            </div>

            <!-- Step 2: where and how to reach them -->
            <div v-show="step === 1" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <Textarea
                        v-model="form.address"
                        label="Business address"
                        :rows="3"
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

                <Input v-model="form.lga" label="LGA" required :error="form.errors.lga" />

                <Input
                    v-model="form.phone"
                    label="Phone"
                    type="tel"
                    inputmode="tel"
                    figures
                    required
                    :error="form.errors.phone"
                />

                <Input
                    v-model="form.whatsapp"
                    label="WhatsApp"
                    type="tel"
                    inputmode="tel"
                    figures
                    hint="Most buyers will reach you here first."
                    :error="form.errors.whatsapp"
                />
            </div>

            <!-- Step 3: proof of identity -->
            <div v-show="step === 2" class="space-y-4">
                <FileUpload
                    v-model="form.id_document"
                    label="ID or business registration"
                    accept="image/png,image/jpeg,image/webp,application/pdf"
                    :hint="
                        application?.has_id_document
                            ? 'We already have your document. Upload a new one only if it has changed.'
                            : 'NIN slip, voter card, driver licence, international passport or CAC certificate. Max 5MB.'
                    "
                    :error="form.errors.id_document"
                />

                <FileUpload
                    v-model="form.logo"
                    label="Business logo"
                    accept="image/png,image/jpeg,image/webp"
                    :current-url="application?.logo_url"
                    hint="Optional. Without one we set your business name as a wordmark instead."
                    :error="form.errors.logo"
                />
            </div>

            <!-- Step 4: what they sell -->
            <div v-show="step === 3" class="space-y-5">
                <CheckboxGroup
                    v-model="form.categories"
                    legend="What do you intend to sell?"
                    hint="Pick every area that applies. You can list in others later."
                    :groups="categoryGroups"
                    :max="12"
                    :error="form.errors.categories"
                />

                <Textarea
                    v-model="form.description"
                    label="About your business"
                    :rows="5"
                    :maxlength="2000"
                    required
                    hint="What you sell, how long you have been trading, and where you deliver. At least a sentence or two."
                    :error="form.errors.description"
                />
            </div>

            <template #footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Button v-if="step > 0" type="button" variant="secondary" @click="back">Back</Button>
                    <span v-else />

                    <div class="flex items-center gap-3">
                        <p v-if="!stepIsComplete(step) && !isLastStep" class="text-xs text-muted">
                            Fill in this step to continue.
                        </p>

                        <Button
                            v-if="!isLastStep"
                            type="button"
                            :disabled="!stepIsComplete(step)"
                            @click="next"
                        >
                            Next
                        </Button>

                        <Button v-else type="submit" :loading="form.processing">
                            {{ application ? 'Resubmit application' : 'Send application' }}
                        </Button>
                    </div>
                </div>
            </template>
        </Card>
    </AppLayout>
</template>
