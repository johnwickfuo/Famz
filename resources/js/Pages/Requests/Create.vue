<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    units: { type: Object, default: () => ({}) },
    profile: { type: Object, default: () => ({}) },
});

const form = useForm({
    title: '',
    description: '',
    category_id: '',
    quantity: 1,
    unit: Object.keys(props.units)[0] ?? 'piece',
    budget_min: '',
    budget_max: '',
    delivery_state: props.profile.state ?? '',
    delivery_lga: props.profile.lga ?? '',
    needed_by: '',
    accepts_partial_fulfilment: true,
    images: [],
});

const previews = ref([]);

function onFiles(event) {
    form.images = Array.from(event.target.files ?? []).slice(0, 5);
    previews.value = form.images.map((file) => ({ name: file.name, url: URL.createObjectURL(file) }));
}

function submit() {
    form.post(route('requests.store'), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="Post what you need">
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">Post what you need</h1>
            <p class="mt-1 text-sm text-muted">
                Say what you are after and sellers come to you with prices. Somebody checks each request before it
                goes up — usually the same day.
            </p>

            <hr class="seam my-5" />

            <form class="space-y-5" @submit.prevent="submit">
                <Card>
                    <template #header>
                        <h2 class="text-lg">What you are looking for</h2>
                    </template>

                    <div class="space-y-4">
                        <Input
                            v-model="form.title"
                            label="In one line"
                            required
                            :maxlength="160"
                            hint="For example: “200 bags of layers mash, delivered to Ibadan”."
                            :error="form.errors.title"
                        />

                        <Select
                            v-model="form.category_id"
                            label="Category"
                            :options="categories"
                            placeholder="Choose the closest one"
                            required
                            hint="Sellers who trade in this category are the ones who will see it."
                            :error="form.errors.category_id"
                        />

                        <Textarea
                            v-model="form.description"
                            label="The detail"
                            :rows="5"
                            :maxlength="4000"
                            required
                            hint="Breed, size, brand, condition — whatever a seller needs to know to price it properly."
                            :error="form.errors.description"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <Input
                                v-model="form.quantity"
                                label="How many"
                                type="number"
                                figures
                                :min="1"
                                required
                                :error="form.errors.quantity"
                            />

                            <Select
                                v-model="form.unit"
                                label="Measured in"
                                :options="Object.entries(units).map(([value, label]) => ({ value, label }))"
                                required
                                :error="form.errors.unit"
                            />
                        </div>

                        <label class="flex items-start gap-2 text-sm">
                            <input
                                v-model="form.accepts_partial_fulfilment"
                                type="checkbox"
                                class="mt-0.5 size-4 accent-enamel"
                            />
                            <span>
                                Several sellers can share this between them
                                <span class="block text-xs text-muted">
                                    Leave this off if you need one seller to supply the lot.
                                </span>
                            </span>
                        </label>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-lg">Where and when</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Select
                            v-model="form.delivery_state"
                            label="State"
                            :options="states"
                            placeholder="Choose a state"
                            required
                            :error="form.errors.delivery_state"
                        />

                        <Input
                            v-model="form.delivery_lga"
                            label="Local government area"
                            required
                            :error="form.errors.delivery_lga"
                        />

                        <Input
                            v-model="form.needed_by"
                            label="Needed by"
                            type="date"
                            hint="Optional."
                            :error="form.errors.needed_by"
                        />
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-lg">What you expect to pay</h2>
                        <p class="mt-1 text-xs text-muted">
                            Optional, but a request with a budget gets far more answers than one without.
                        </p>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.budget_min"
                            label="From"
                            type="number"
                            step="0.01"
                            prefix="₦"
                            figures
                            :error="form.errors.budget_min"
                        />
                        <Input
                            v-model="form.budget_max"
                            label="Up to"
                            type="number"
                            step="0.01"
                            prefix="₦"
                            figures
                            :error="form.errors.budget_max"
                        />
                    </div>

                    <div class="mt-4">
                        <label for="images" class="mb-1.5 block font-display text-xs font-bold uppercase tracking-wider">
                            Photographs
                        </label>
                        <input
                            id="images"
                            type="file"
                            accept="image/*"
                            multiple
                            class="block w-full rounded-sm border-2 border-dashed border-grain-400 p-3 text-sm dark:border-grain-600"
                            @change="onFiles"
                        />
                        <p class="mt-1 text-xs text-muted">Up to five. A picture of the old one helps.</p>

                        <ul v-if="previews.length" class="mt-3 flex flex-wrap gap-2">
                            <li v-for="preview in previews" :key="preview.name">
                                <img
                                    :src="preview.url"
                                    :alt="preview.name"
                                    class="size-20 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                />
                            </li>
                        </ul>
                    </div>

                    <template #footer>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="submit" size="lg" :loading="form.processing" :disabled="form.processing">
                                Send it for checking
                            </Button>
                            <p class="text-xs text-muted">
                                We will email you as soon as it is live.
                            </p>
                        </div>
                    </template>
                </Card>
            </form>
        </div>
    </PublicLayout>
</template>
