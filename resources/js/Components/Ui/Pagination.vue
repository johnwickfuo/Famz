<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/** Takes a Laravel paginator's `links` array straight from the props. */
const props = defineProps({
    links: { type: Array, default: () => [] },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    total: { type: Number, default: null },
});

const pages = computed(() => props.links ?? []);

function label(raw) {
    // Laravel ships "&laquo; Previous" / "Next &raquo;" as HTML entities.
    return raw.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <nav
        v-if="pages.length > 3"
        aria-label="Pagination"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <p v-if="total !== null" class="figures text-xs text-muted">
            {{ from }}–{{ to }} of {{ total }}
        </p>

        <ul class="flex flex-wrap items-center gap-1">
            <li v-for="(link, index) in pages" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="figures inline-flex min-h-9 min-w-9 items-center justify-center rounded-sm border-2 px-2 text-sm font-semibold"
                    :class="
                        link.active
                            ? 'border-ink bg-ink text-wash dark:border-wash'
                            : 'border-grain-300 text-ink hover:border-ink dark:border-grain-600 dark:text-wash dark:hover:border-wash'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                    preserve-scroll
                >
                    {{ label(link.label) }}
                </Link>
                <span
                    v-else
                    class="figures inline-flex min-h-9 min-w-9 items-center justify-center rounded-sm border-2 border-grain-200 px-2 text-sm text-grain-400 dark:border-grain-700"
                    aria-hidden="true"
                >
                    {{ label(link.label) }}
                </span>
            </li>
        </ul>
    </nav>
</template>
