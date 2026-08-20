<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import { compressAll, readableSize } from '@/Support/compressImage';

/**
 * Asking what it would cost to set up a farm.
 *
 * The longest form on the platform, and deliberately so. A consultation takes
 * four fields because speed is the product; this one is filled in by somebody
 * contemplating spending millions, who is willing to spend ten minutes on it —
 * and none of it can be skipped without the proposal being guesswork.
 */
const props = defineProps({
    projectTypes: { type: Array, default: () => [] },
    scopes: { type: Array, default: () => [] },
    powerOptions: { type: Object, default: () => ({}) },
    waterOptions: { type: Object, default: () => ({}) },
    states: { type: Array, default: () => [] },
    studyFee: { type: String, default: null },
    earlierRequests: { type: Number, default: 0 },
    prefill: { type: Object, default: null },
});

const form = useForm({
    project_type: props.projectTypes[0]?.value ?? 'new_build',
    farm_type: '',

    target_capacity: '',
    capacity_unit: 'birds',

    owns_land: false,
    land_size: '',
    land_unit: 'hectares',

    state: props.prefill?.state ?? '',
    lga: props.prefill?.lga ?? '',
    address: '',

    budget_range_min: '',
    budget_range_max: '',
    target_start_date: '',

    scope_wanted: [],
    power_situation: '',
    water_source: '',
    additional_notes: '',
    site_photos: [],
});

const compressing = ref(false);
const photoNote = ref(null);

const chosenType = computed(
    () => props.projectTypes.find((type) => type.value === form.project_type) ?? props.projectTypes[0],
);

/** Equipment-only buyers are not asked about hectares they do not need. */
const needsLand = computed(() => chosenType.value?.needs_land ?? true);

function toggleScope(value) {
    form.scope_wanted = form.scope_wanted.includes(value)
        ? form.scope_wanted.filter((item) => item !== value)
        : [...form.scope_wanted, value];
}

async function onPhotos(event) {
    const picked = Array.from(event.target.files ?? []).slice(0, 8);

    if (picked.length === 0) {
        return;
    }

    compressing.value = true;
    photoNote.value = null;

    const before = picked.reduce((total, file) => total + file.size, 0);
    const compressed = await compressAll(picked);
    const after = compressed.reduce((total, file) => total + file.size, 0);

    form.site_photos = compressed;
    compressing.value = false;

    photoNote.value =
        after < before
            ? `${compressed.length} photo${compressed.length === 1 ? '' : 's'} · ${readableSize(before)} shrunk to ${readableSize(after)}`
            : `${compressed.length} photo${compressed.length === 1 ? '' : 's'} · ${readableSize(after)}`;
}

function submit() {
    form.post(route('quotations.store'), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="Get a farm setup quotation">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Farm setup</p>
        <h1 class="text-2xl sm:text-3xl">Tell us what you want to build</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            We will cost it properly — housing, equipment, stock, labour — and write it up as a proposal you can take to
            a bank. The more you can tell us here, the closer the numbers will be.
        </p>

        <p v-if="earlierRequests" class="mt-3 text-sm">
            <Link class="underline" :href="route('quotations.index')">
                See the {{ earlierRequests }} you have already asked about
            </Link>
        </p>

        <hr class="seam seam-chrome my-6" />

        <form class="lg:flex lg:items-start lg:gap-6" @submit.prevent="submit">
            <div class="min-w-0 flex-1 space-y-5">
                <!-- What kind of job, which changes everything downstream. -->
                <Card>
                    <template #header>
                        <h2 class="text-base">What are you doing?</h2>
                    </template>

                    <div class="space-y-3">
                        <label
                            v-for="type in projectTypes"
                            :key="type.value"
                            class="flex cursor-pointer items-start gap-3 rounded-sm border-2 p-3 transition-colors"
                            :class="
                                form.project_type === type.value
                                    ? 'border-ink bg-chrome-50 dark:border-wash dark:bg-grain-800'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600 dark:hover:border-wash'
                            "
                        >
                            <input
                                v-model="form.project_type"
                                type="radio"
                                :value="type.value"
                                class="mt-1 size-4 shrink-0 accent-enamel"
                            />
                            <span class="min-w-0">
                                <span class="stencil block text-ink dark:text-wash">{{ type.label }}</span>
                                <span class="mt-0.5 block text-sm text-muted">{{ type.hint }}</span>
                            </span>
                        </label>
                    </div>

                    <p v-if="form.errors.project_type" class="mt-2 text-xs text-cockscomb">
                        {{ form.errors.project_type }}
                    </p>

                    <hr class="seam my-4" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.farm_type"
                            label="What kind of farm"
                            placeholder="Layer poultry, catfish, piggery…"
                            :error="form.errors.farm_type"
                            required
                            class="sm:col-span-2"
                        />
                        <Input
                            v-model="form.target_capacity"
                            label="How big"
                            type="number"
                            min="1"
                            figures
                            placeholder="20000"
                            hint="What you want to end up with."
                            :error="form.errors.target_capacity"
                        />
                        <Input
                            v-model="form.capacity_unit"
                            label="Of what"
                            placeholder="birds, tonnes a month, ponds…"
                            :error="form.errors.capacity_unit"
                        />
                    </div>
                </Card>

                <!-- The site. Whether they own it changes the whole proposal. -->
                <Card>
                    <template #header>
                        <h2 class="text-base">Where is it going?</h2>
                    </template>

                    <label
                        v-if="needsLand"
                        class="flex cursor-pointer items-start gap-3 rounded-sm border-2 border-grain-300 p-3 dark:border-grain-600"
                    >
                        <input v-model="form.owns_land" type="checkbox" class="mt-0.5 size-4 shrink-0 accent-enamel" />
                        <span class="min-w-0">
                            <span class="stencil block text-ink dark:text-wash">I already have the land</span>
                            <span class="mt-0.5 block text-sm text-muted">
                                If not, we can still cost the build — we will just quote against a typical site.
                            </span>
                        </span>
                    </label>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <template v-if="needsLand">
                            <Input
                                v-model="form.land_size"
                                label="How much land"
                                type="number"
                                step="0.01"
                                min="0"
                                figures
                                placeholder="3"
                                :error="form.errors.land_size"
                            />
                            <Input
                                v-model="form.land_unit"
                                label="Measured in"
                                placeholder="hectares, acres, plots"
                                :error="form.errors.land_unit"
                            />
                        </template>

                        <Select v-model="form.state" label="State" :error="form.errors.state">
                            <option value="">Choose a state</option>
                            <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                        </Select>
                        <Input v-model="form.lga" label="LGA" :error="form.errors.lga" />

                        <Textarea
                            v-model="form.address"
                            label="Address or landmark"
                            :rows="2"
                            hint="Optional. Helps us judge access, power and water."
                            :error="form.errors.address"
                            class="sm:col-span-2"
                        />
                    </div>
                </Card>

                <!-- What they want us to do. Drives the proposal's sections. -->
                <Card>
                    <template #header>
                        <div>
                            <h2 class="text-base">What do you want us to handle?</h2>
                            <p class="mt-0.5 text-xs text-muted">Tick everything that applies.</p>
                        </div>
                    </template>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="scope in scopes"
                            :key="scope.value"
                            class="flex cursor-pointer items-start gap-3 rounded-sm border-2 p-3 transition-colors"
                            :class="
                                form.scope_wanted.includes(scope.value)
                                    ? 'border-ink bg-chrome-50 dark:border-wash dark:bg-grain-800'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600 dark:hover:border-wash'
                            "
                        >
                            <input
                                type="checkbox"
                                :checked="form.scope_wanted.includes(scope.value)"
                                class="mt-1 size-4 shrink-0 accent-enamel"
                                @change="toggleScope(scope.value)"
                            />
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ scope.label }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ scope.hint }}</span>
                            </span>
                        </label>
                    </div>
                </Card>

                <!-- Power and water: the two lines that move a Nigerian farm budget most. -->
                <Card>
                    <template #header>
                        <h2 class="text-base">Power and water</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Select
                            v-model="form.power_situation"
                            label="Power on site"
                            hint="A site with nothing needs generating capacity costed in."
                            :error="form.errors.power_situation"
                        >
                            <option value="">Not sure yet</option>
                            <option v-for="(label, value) in powerOptions" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </Select>
                        <Select
                            v-model="form.water_source"
                            label="Water on site"
                            hint="No water usually means a borehole, which is rarely a small line."
                            :error="form.errors.water_source"
                        >
                            <option value="">Not sure yet</option>
                            <option v-for="(label, value) in waterOptions" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </Select>
                    </div>
                </Card>

                <!-- Budget as a range, because nobody knows it to the naira. -->
                <Card>
                    <template #header>
                        <div>
                            <h2 class="text-base">Money and timing</h2>
                            <p class="mt-0.5 text-xs text-muted">
                                A rough range is fine. It tells us what to design towards.
                            </p>
                        </div>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.budget_range_min"
                            label="Budget from"
                            type="number"
                            min="0"
                            figures
                            prefix="₦"
                            :error="form.errors.budget_range_min"
                        />
                        <Input
                            v-model="form.budget_range_max"
                            label="Budget up to"
                            type="number"
                            min="0"
                            figures
                            prefix="₦"
                            :error="form.errors.budget_range_max"
                        />
                        <Input
                            v-model="form.target_start_date"
                            label="When you want to start"
                            type="date"
                            :error="form.errors.target_start_date"
                            class="sm:col-span-2"
                        />
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-base">Anything else</h2>
                    </template>

                    <Textarea
                        v-model="form.additional_notes"
                        label="Tell us more"
                        :rows="5"
                        hint="Anything you already own, anything you have been quoted before, anything worrying you."
                        :error="form.errors.additional_notes"
                    />

                    <hr class="seam my-4" />

                    <p class="stencil text-ink dark:text-wash">Photographs of the site</p>
                    <p class="mt-1 text-sm text-muted">
                        The land, anything already standing, the access road. We shrink them on your phone before
                        sending, so this costs very little data.
                    </p>

                    <input
                        type="file"
                        accept="image/*"
                        multiple
                        class="mt-3 block w-full rounded-sm border-2 border-ink p-2 text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-chrome file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wider dark:border-wash"
                        @change="onPhotos"
                    />

                    <p v-if="compressing" class="mt-2 text-xs text-muted">Shrinking the photos…</p>
                    <p v-else-if="photoNote" class="mt-2 text-xs text-muted">{{ photoNote }}</p>
                    <p v-if="form.errors.site_photos" class="mt-2 text-xs text-cockscomb">
                        {{ form.errors.site_photos }}
                    </p>
                </Card>
            </div>

            <!-- What happens next, and the one number they need before starting. -->
            <div class="mt-5 w-full lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">What happens next</h2>
                    </template>

                    <ol class="list-decimal space-y-2 pl-5 text-sm">
                        <li>You send us this.</li>
                        <li>You pay the study fee.</li>
                        <li>We cost the whole thing and write it up.</li>
                        <li>You get a proposal you can take to a bank.</li>
                    </ol>

                    <div class="mt-4 rounded-sm border-2 border-grain-300 p-3 dark:border-grain-600">
                        <p class="stencil text-muted">Study fee</p>
                        <p class="figures mt-1 text-xl font-bold">{{ studyFee }}</p>
                        <p class="mt-2 text-xs text-muted">
                            Charged before we start. If you go ahead with the project, we normally take it off your
                            first invoice.
                        </p>
                    </div>

                    <Button type="submit" class="mt-4" :disabled="form.processing || compressing" block>
                        {{ form.processing ? 'Sending…' : 'Send this to us' }}
                    </Button>

                    <p class="mt-2 text-center text-xs text-muted">Nothing is charged on this screen.</p>
                </Card>
            </div>
        </form>
    </PublicLayout>
</template>
