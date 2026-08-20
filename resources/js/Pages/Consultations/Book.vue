<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import Badge from '@/Components/Ui/Badge.vue';
import { compressAll, readableSize } from '@/Support/compressImage';

/**
 * Booking a consultation.
 *
 * The whole form is one screen and only four fields are required. Everything
 * else sits under "Tell us more" and is genuinely optional — somebody whose
 * birds are dying this morning should be able to send this in thirty seconds,
 * and we can ask the rest on the phone.
 */
const props = defineProps({
    tiers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    workingHours: { type: Object, default: () => ({ start: 8, end: 17 }) },
    prefill: { type: Object, default: null },
});

const form = useForm({
    full_name: props.prefill?.full_name ?? '',
    phone: props.prefill?.phone ?? '',
    email: props.prefill?.email ?? '',
    tier: props.tiers[0]?.value ?? 'standard',

    category: '',
    situation: '',
    farm_type: '',
    animal_type: '',
    flock_size: '',
    state: props.prefill?.state ?? '',
    lga: props.prefill?.lga ?? '',
    photos: [],
});

const showMore = ref(false);
const compressing = ref(false);
const photoNote = ref(null);

const chosenTier = computed(() => props.tiers.find((tier) => tier.value === form.tier) ?? props.tiers[0]);

/**
 * Shrink the photos before they go anywhere near the network.
 */
async function onPhotos(event) {
    const picked = Array.from(event.target.files ?? []).slice(0, 6);

    if (picked.length === 0) {
        return;
    }

    compressing.value = true;
    photoNote.value = null;

    const before = picked.reduce((total, file) => total + file.size, 0);
    const compressed = await compressAll(picked);
    const after = compressed.reduce((total, file) => total + file.size, 0);

    form.photos = compressed;
    compressing.value = false;

    photoNote.value =
        after < before
            ? `${compressed.length} photo${compressed.length === 1 ? '' : 's'} · ${readableSize(before)} shrunk to ${readableSize(after)}`
            : `${compressed.length} photo${compressed.length === 1 ? '' : 's'} · ${readableSize(after)}`;
}

function submit() {
    form.post(route('consultations.store'), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="Ask us for help">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Consultation</p>
        <h1 class="text-2xl sm:text-3xl">Tell us what is wrong</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Four things and you are done — we will ring you and take it from there. Nothing is charged now: we agree a
            price with you after we have talked.
        </p>

        <hr class="seam seam-chrome my-6" />

        <form class="lg:flex lg:items-start lg:gap-6" @submit.prevent="submit">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-base">How do we reach you?</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.full_name"
                            label="Your name"
                            :error="form.errors.full_name"
                            required
                        />
                        <Input
                            v-model="form.phone"
                            label="Phone number"
                            type="tel"
                            figures
                            placeholder="0803 000 0000"
                            :error="form.errors.phone"
                            required
                        />
                        <Input
                            v-model="form.email"
                            label="Email"
                            type="email"
                            :error="form.errors.email"
                            required
                            class="sm:col-span-2"
                        />
                    </div>
                </Card>

                <!--
                    The tier is the fourth required field, and it is the actual
                    product: what is being bought here is a response time.
                -->
                <Card>
                    <template #header>
                        <h2 class="text-base">How soon do you need us?</h2>
                    </template>

                    <div class="space-y-3">
                        <label
                            v-for="tier in tiers"
                            :key="tier.value"
                            class="flex cursor-pointer items-start gap-3 rounded-sm border-2 p-3 transition-colors"
                            :class="
                                form.tier === tier.value
                                    ? 'border-ink bg-chrome-50 dark:border-wash dark:bg-grain-800'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600 dark:hover:border-wash'
                            "
                        >
                            <input
                                v-model="form.tier"
                                type="radio"
                                :value="tier.value"
                                class="mt-1 size-5 shrink-0 accent-enamel"
                            />

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-display text-sm font-bold uppercase tracking-wide">
                                        {{ tier.label }}
                                    </span>
                                    <!-- The promise, read straight from settings. -->
                                    <Badge size="sm" :variant="tier.is_premium ? 'pending' : 'neutral'">
                                        {{ tier.promise }}
                                    </Badge>
                                </span>
                                <span class="mt-1 block text-sm text-muted">{{ tier.blurb }}</span>
                            </span>
                        </label>
                    </div>

                    <p v-if="chosenTier?.working_hours_only" class="mt-3 text-xs text-muted">
                        Working hours are {{ workingHours.start }}:00 to {{ workingHours.end }}:00, Monday to Saturday.
                        Send it overnight and the clock starts when we open.
                    </p>
                </Card>

                <!--
                    Everything below here is optional, and the form says so
                    rather than making somebody find out by trying to submit.
                -->
                <Card>
                    <template #header>
                        <button
                            type="button"
                            class="flex w-full items-center justify-between text-left"
                            :aria-expanded="showMore"
                            @click="showMore = !showMore"
                        >
                            <span>
                                <span class="block text-base">Tell us more</span>
                                <span class="block text-xs font-normal text-muted">
                                    All optional. It helps, but we can ask on the phone.
                                </span>
                            </span>
                            <span aria-hidden="true" class="text-lg">{{ showMore ? '−' : '+' }}</span>
                        </button>
                    </template>

                    <div v-if="showMore" class="space-y-4">
                        <Select
                            v-model="form.category"
                            label="What is it about?"
                            placeholder="Choose one"
                            :options="categories.map((c) => ({ value: c, label: c }))"
                            :error="form.errors.category"
                        />

                        <Textarea
                            v-model="form.situation"
                            label="What is happening?"
                            :rows="5"
                            placeholder="Losing about ten birds a day in a 2,000 layer house. Started four days ago."
                            :error="form.errors.situation"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <Input v-model="form.farm_type" label="Kind of farm" placeholder="Layer farm" />
                            <Input v-model="form.animal_type" label="Birds or animals" placeholder="Isa Brown layers" />
                            <Input v-model="form.flock_size" label="How many" type="number" figures />
                            <Select
                                v-model="form.state"
                                label="State"
                                placeholder="Choose a state"
                                :options="states.map((s) => ({ value: s, label: s }))"
                            />
                            <Input v-model="form.lga" label="LGA" class="sm:col-span-2" />
                        </div>
                    </div>

                    <p v-else class="text-sm text-muted">
                        Skip it if you are in a hurry — we will get what we need on the call.
                    </p>
                </Card>

                <!--
                    Photographs matter more than any of the text fields: a vet
                    asks for pictures before anything else. Compressed in the
                    browser so three photos do not cost somebody their bundle.
                -->
                <Card>
                    <template #header>
                        <h2 class="text-base">Photographs</h2>
                    </template>

                    <p class="mb-3 text-sm text-muted">
                        Pictures of the birds, the pen, the feed — whatever you can see. This is usually the fastest way
                        for us to tell what is going on.
                    </p>

                    <input
                        type="file"
                        accept="image/*"
                        multiple
                        class="block w-full rounded-sm border-2 border-ink bg-surface-raised p-2 text-sm file:mr-3 file:rounded-sm file:border-2 file:border-ink file:bg-chrome file:px-3 file:py-1.5 file:font-display file:text-xs file:font-bold file:uppercase dark:border-wash dark:bg-grain-900"
                        @change="onPhotos"
                    />

                    <p v-if="compressing" class="mt-2 text-sm text-muted">Making them smaller…</p>
                    <p v-else-if="photoNote" class="figures mt-2 text-sm text-muted">{{ photoNote }}</p>

                    <p v-if="form.errors.photos" class="mt-2 text-sm text-cockscomb dark:text-cockscomb-300">
                        {{ form.errors.photos }}
                    </p>

                    <p class="mt-2 text-xs text-muted">
                        Up to six. We shrink them on your phone before sending, so this costs very little data.
                    </p>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-72 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">What happens next</h2>
                    </template>

                    <ol class="list-decimal space-y-2 pl-5 text-sm">
                        <li>We ring you — {{ chosenTier?.promise?.toLowerCase() }}.</li>
                        <li>We talk about what is going on.</li>
                        <li>We agree a price and send it to you.</li>
                        <li>You pay, we do the work, you get a written report.</li>
                    </ol>

                    <p class="mt-4 rounded-sm border-2 border-grain-300 p-2 text-xs text-muted dark:border-grain-600">
                        No price is set until we have spoken. There is nothing to pay to send this.
                    </p>

                    <Button class="mt-4" type="submit" block :loading="form.processing" :disabled="compressing">
                        Send this to us
                    </Button>

                    <p class="mt-2 text-center text-xs text-muted">You do not need an account.</p>
                </Card>
            </aside>
        </form>
    </PublicLayout>
</template>
