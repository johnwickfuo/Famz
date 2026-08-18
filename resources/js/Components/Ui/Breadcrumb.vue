<script setup>
import { Link } from '@inertiajs/vue3';

/** items: [{ label, href? }] — the last item is the current page. */
defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <li
                v-for="(item, index) in items"
                :key="`${item.label}-${index}`"
                class="flex min-w-0 items-center gap-2"
            >
                <Link
                    v-if="item.href && index < items.length - 1"
                    :href="item.href"
                    class="stencil truncate text-enamel underline-offset-4 hover:underline dark:text-chrome"
                >
                    {{ item.label }}
                </Link>
                <span v-else class="stencil truncate text-muted" aria-current="page">
                    {{ item.label }}
                </span>

                <span v-if="index < items.length - 1" class="text-grain-400" aria-hidden="true">/</span>
            </li>
        </ol>
    </nav>
</template>
