<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Input from '@/Components/Ui/Input.vue';

/**
 * The filter rail. Collapsible on a phone, always open from `lg` up.
 *
 * Every change goes through the URL rather than component state, so a filtered
 * view can be shared, bookmarked and reached with the back button — which
 * matters when the connection drops halfway through browsing.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    /** Route name and parameter for the page these filters belong to. */
    routeName: { type: String, required: true },
    routeParams: { type: [String, Number, Object, null], default: null },
});

const open = ref(false);

const form = ref({
    min_price: props.filters.min_price ?? '',
    max_price: props.filters.max_price ?? '',
    state: [...(props.filters.state ?? [])],
    condition: [...(props.filters.condition ?? [])],
    negotiable: Boolean(props.filters.negotiable),
    in_stock: Boolean(props.filters.in_stock),
    live_animals: Boolean(props.filters.live_animals),
    perishable: Boolean(props.filters.perishable),
    sort: props.filters.sort ?? '',
});

const activeCount = computed(() => {
    const f = form.value;
    return (
        (f.min_price !== '' ? 1 : 0) +
        (f.max_price !== '' ? 1 : 0) +
        f.state.length +
        f.condition.length +
        (f.negotiable ? 1 : 0) +
        (f.in_stock ? 1 : 0) +
        (f.live_animals ? 1 : 0) +
        (f.perishable ? 1 : 0)
    );
});

function destination() {
    return props.routeParams === null
        ? route(props.routeName)
        : route(props.routeName, props.routeParams);
}

function apply() {
    const query = { ...form.value };

    // Keep the URL to what is actually set: an address full of empty
    // parameters is unreadable and unshareable.
    Object.keys(query).forEach((key) => {
        const value = query[key];
        if (value === '' || value === false || (Array.isArray(value) && value.length === 0)) {
            delete query[key];
        }
    });

    if (props.filters.q) query.q = props.filters.q;

    router.get(destination(), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function clear() {
    form.value = {
        min_price: '',
        max_price: '',
        state: [],
        condition: [],
        negotiable: false,
        in_stock: false,
        live_animals: false,
        perishable: false,
        sort: form.value.sort,
    };

    apply();
}

function toggleIn(list, value) {
    const index = form.value[list].indexOf(value);
    if (index === -1) form.value[list].push(value);
    else form.value[list].splice(index, 1);
    apply();
}

// Sorting applies immediately — nobody expects to press a button for it.
watch(() => form.value.sort, apply);
</script>

<template>
    <div class="lg:sticky lg:top-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <Select
                v-model="form.sort"
                label="Sort by"
                :options="options.sorts"
                class="max-w-[15rem]"
            />

            <Button
                type="button"
                variant="secondary"
                size="sm"
                class="mt-6 shrink-0 lg:hidden"
                :aria-expanded="open"
                aria-controls="catalogue-filters"
                @click="open = !open"
            >
                Filters<span v-if="activeCount" class="figures"> ({{ activeCount }})</span>
            </Button>
        </div>

        <form
            id="catalogue-filters"
            class="space-y-5 rounded-sm border-2 border-ink bg-surface-raised p-4 dark:border-wash dark:bg-grain-900"
            :class="open ? 'block' : 'hidden lg:block'"
            @submit.prevent="apply"
        >
            <fieldset>
                <legend class="stencil mb-2">Price ({{ options.currency }})</legend>
                <div class="flex items-end gap-2">
                    <Input v-model="form.min_price" label="From" type="number" inputmode="numeric" figures min="0" />
                    <Input v-model="form.max_price" label="To" type="number" inputmode="numeric" figures min="0" />
                </div>
            </fieldset>

            <hr class="seam" />

            <fieldset v-if="options.states.length">
                <legend class="stencil mb-2">State</legend>
                <div class="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto">
                    <label
                        v-for="state in options.states"
                        :key="state"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-sm border-2 px-2 py-1 text-xs"
                        :class="form.state.includes(state)
                            ? 'border-ink bg-chrome-100 font-semibold dark:bg-grain-800 dark:border-wash'
                            : 'border-grain-300 dark:border-grain-600'"
                    >
                        <input
                            type="checkbox"
                            class="sr-only"
                            :checked="form.state.includes(state)"
                            @change="toggleIn('state', state)"
                        />
                        {{ state }}
                    </label>
                </div>
            </fieldset>

            <hr v-if="options.states.length" class="seam" />

            <fieldset>
                <legend class="stencil mb-2">Condition</legend>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="condition in options.conditions"
                        :key="condition.value"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-sm border-2 px-2 py-1 text-xs"
                        :class="form.condition.includes(condition.value)
                            ? 'border-ink bg-chrome-100 font-semibold dark:bg-grain-800 dark:border-wash'
                            : 'border-grain-300 dark:border-grain-600'"
                    >
                        <input
                            type="checkbox"
                            class="sr-only"
                            :checked="form.condition.includes(condition.value)"
                            @change="toggleIn('condition', condition.value)"
                        />
                        {{ condition.label }}
                    </label>
                </div>
            </fieldset>

            <hr class="seam" />

            <fieldset class="space-y-2">
                <legend class="stencil mb-2">Only show</legend>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.in_stock" type="checkbox" class="size-4 rounded-sm border-2 border-ink" @change="apply" />
                    In stock
                </label>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.negotiable" type="checkbox" class="size-4 rounded-sm border-2 border-ink" @change="apply" />
                    Negotiable price
                </label>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.live_animals" type="checkbox" class="size-4 rounded-sm border-2 border-ink" @change="apply" />
                    Live animals
                </label>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.perishable" type="checkbox" class="size-4 rounded-sm border-2 border-ink" @change="apply" />
                    Perishable goods
                </label>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" size="sm">Apply</Button>
                <Button v-if="activeCount" type="button" variant="ghost" size="sm" @click="clear">
                    Clear <span class="figures">{{ activeCount }}</span>
                </Button>
            </div>
        </form>
    </div>
</template>
