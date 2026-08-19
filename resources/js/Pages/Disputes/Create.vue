<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

const props = defineProps({
    subOrder: { type: Object, required: true },
    reasons: { type: Array, default: () => [] },
    closesAt: { type: String, default: null },
});

const form = useForm({
    reason: props.reasons[0]?.value ?? '',
    description: '',
    evidence: [],
});

const previews = ref([]);

function onFiles(event) {
    form.evidence = Array.from(event.target.files ?? []).slice(0, 6);
    previews.value = form.evidence.map((file) => ({ name: file.name, url: URL.createObjectURL(file) }));
}

function submit() {
    form.post(route('disputes.store', props.subOrder.reference), { forceFormData: true });
}
</script>

<template>
    <PublicLayout title="Report a problem">
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">Report a problem</h1>
            <p class="mt-1 text-sm text-muted">
                {{ subOrder.seller }} · <span class="figures">{{ subOrder.reference }}</span>
            </p>

            <hr class="seam my-5" />

            <!--
                Said plainly and up front. A buyer deciding whether to complain
                should know that complaining actually does something.
            -->
            <div class="mb-5 rounded-sm border-2 border-ink bg-chrome-100 p-4 dark:bg-grain-800">
                <p class="font-display text-sm font-bold uppercase tracking-wider">
                    The seller's money is held while we look at this
                </p>
                <p class="mt-1 text-sm">
                    <span class="figures font-semibold">{{ subOrder.total }}</span> stays with us until it is settled.
                    Nobody is paid, and nothing releases automatically, while a complaint is open.
                </p>
                <p v-if="closesAt" class="mt-2 text-xs text-muted">
                    You have until {{ closesAt }} to raise this.
                </p>
            </div>

            <form @submit.prevent="submit">
                <Card>
                    <template #header>
                        <h2 class="text-lg">What happened</h2>
                    </template>

                    <div class="space-y-4">
                        <Select
                            v-model="form.reason"
                            label="What went wrong"
                            :options="reasons.map((r) => ({ value: r.value, label: r.label }))"
                            required
                            :error="form.errors.reason"
                        />

                        <Textarea
                            v-model="form.description"
                            label="Tell us in your own words"
                            :rows="5"
                            :maxlength="2000"
                            required
                            hint="What you ordered, what arrived, and what you want done about it."
                            :error="form.errors.description"
                        />

                        <div>
                            <label
                                for="evidence"
                                class="mb-1.5 block font-display text-xs font-bold uppercase tracking-wider"
                            >
                                Photographs
                            </label>
                            <input
                                id="evidence"
                                type="file"
                                accept="image/*"
                                multiple
                                class="block w-full rounded-sm border-2 border-dashed border-grain-400 p-3 text-sm dark:border-grain-600"
                                @change="onFiles"
                            />
                            <p class="mt-1 text-xs text-muted">
                                Up to six. A photograph settles most of these faster than anything written.
                            </p>
                            <p v-if="form.errors.evidence" class="mt-1 text-sm font-semibold text-cockscomb">
                                {{ form.errors.evidence }}
                            </p>

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
                    </div>

                    <template #footer>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="submit" :loading="form.processing" :disabled="form.processing">
                                Send this to us
                            </Button>
                            <Button :href="route('orders.show', subOrder.order_reference)" variant="ghost">
                                Never mind
                            </Button>
                        </div>
                    </template>
                </Card>
            </form>
        </div>
    </PublicLayout>
</template>
