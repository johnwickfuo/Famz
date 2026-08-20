<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Input from '@/Components/Ui/Input.vue';
import Textarea from '@/Components/Ui/Textarea.vue';

/**
 * Telling us what you need.
 *
 * The big box comes first and everything else is optional, because most people
 * can describe their problem long before they can name the category it belongs
 * to. The tags underneath are there for somebody who already knows — and when
 * they tick them, their own choice is used and nothing is inferred.
 */
const props = defineProps({
    sectors: { type: Object, default: () => ({}) },
    specialisations: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    mentorCount: { type: Number, default: 0 },
});

const form = useForm({
    need: '',
    sector: '',
    state: '',
    wants_remote: true,
    wants_in_person: false,
    budget_min: null,
    budget_max: null,
    specialisations: [],
});

const showTags = ref(false);

const sectorOptions = computed(() => [
    { value: '', label: 'Anything' },
    ...Object.entries(props.sectors).map(([value, label]) => ({ value, label })),
]);

// Narrowed to the chosen sector, so the list stays readable rather than
// showing every tag in the taxonomy at once.
const tags = computed(() =>
    form.sector ? props.specialisations.filter((tag) => tag.sector === form.sector) : props.specialisations,
);

function submit() {
    form.post(route('mentors.match'));
}
</script>

<template>
    <PublicLayout title="Find a mentor">
        <p class="stencil mb-2 text-enamel dark:text-chrome">Mentors</p>
        <h1 class="text-2xl sm:text-3xl">What do you need help with?</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">
            Write it the way you would say it. We will find the people who have done this before — there are
            {{ mentorCount }} of them on the platform — and show you what they charge.
        </p>

        <hr class="seam seam-chrome my-6" />

        <form class="lg:flex lg:items-start lg:gap-6" @submit.prevent="submit">
            <div class="min-w-0 flex-1 space-y-5">
                <Card>
                    <template #header>
                        <h2 class="text-base">Tell us the problem</h2>
                    </template>

                    <Textarea
                        v-model="form.need"
                        label="What is going on?"
                        :rows="6"
                        :error="form.errors.need"
                        placeholder="My day-old chicks keep dying in the first week. I have 500 birds in a block pen and I am using a kerosene stove for heat."
                        required
                    />

                    <p class="mt-2 text-xs text-muted">
                        The more you say, the better the match. Nobody sees this except us and the mentor you decide to
                        hire.
                    </p>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-base">How you want to work</h2>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Select v-model="form.sector" label="Area" :options="sectorOptions" />

                        <Select
                            v-model="form.state"
                            label="Your state"
                            :options="[{ value: '', label: 'Anywhere' }, ...states]"
                        />
                    </div>

                    <div class="mt-4 space-y-2">
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="form.wants_remote" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                            <span>Phone, WhatsApp or video is fine</span>
                        </label>

                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="form.wants_in_person" type="checkbox" class="mt-0.5 size-4 accent-enamel" />
                            <span>I want somebody who can come to the farm</span>
                        </label>
                    </div>

                    <hr class="seam my-4" />

                    <p class="stencil mb-2 text-muted">What you can spend</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input v-model="form.budget_min" label="From" type="number" prefix="₦" figures />
                        <Input v-model="form.budget_max" label="Up to" type="number" prefix="₦" figures />
                    </div>

                    <p class="mt-2 text-xs text-muted">
                        Leave both empty if you would rather see everybody and decide afterwards.
                    </p>
                </Card>

                <!--
                    For somebody who already knows what they need. Ticking these
                    takes priority: their own choice beats anybody's guess about
                    what they meant.
                -->
                <Card>
                    <template #header>
                        <button
                            type="button"
                            class="flex w-full items-center justify-between text-left"
                            :aria-expanded="showTags"
                            @click="showTags = !showTags"
                        >
                            <h2 class="text-base">Know exactly what you need? Pick it yourself</h2>
                            <span aria-hidden="true">{{ showTags ? '−' : '+' }}</span>
                        </button>
                    </template>

                    <div v-if="showTags" class="flex flex-wrap gap-2">
                        <label
                            v-for="tag in tags"
                            :key="tag.id"
                            class="cursor-pointer rounded-full border-2 px-3 py-1.5 text-xs transition-colors"
                            :class="
                                form.specialisations.includes(tag.id)
                                    ? 'border-ink bg-chrome text-ink dark:border-wash'
                                    : 'border-grain-300 hover:border-ink dark:border-grain-600'
                            "
                        >
                            <input
                                v-model="form.specialisations"
                                type="checkbox"
                                :value="tag.id"
                                class="sr-only"
                            />
                            {{ tag.name }}
                        </label>
                    </div>

                    <p v-else class="text-sm text-muted">
                        Optional. Skip it and we will work it out from what you wrote.
                    </p>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-72 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">What happens next</h2>
                    </template>

                    <ol class="list-decimal space-y-2 pl-5 text-sm">
                        <li>We show you a shortlist, best match first.</li>
                        <li>You compare what each one charges.</li>
                        <li>You pay for the package you want.</li>
                        <li>Their phone number appears, and you get started.</li>
                    </ol>

                    <p class="mt-4 rounded-sm border-2 border-grain-300 p-2 text-xs text-muted dark:border-grain-600">
                        Contact details stay hidden until you have paid. Your money is held until you confirm the work
                        was done.
                    </p>

                    <Button class="mt-4" type="submit" block :loading="form.processing">Find mentors</Button>
                </Card>
            </aside>
        </form>
    </PublicLayout>
</template>
