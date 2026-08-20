<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';

/**
 * Questions as native <details>, which costs no JavaScript, works before the
 * page has finished loading, and is searchable by the browser's own find.
 */
defineProps({
    page: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="page.title">
        <div class="mx-auto w-full max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">{{ page.title }}</h1>
            <p class="mt-3 text-base leading-relaxed">{{ page.lead }}</p>

            <hr class="seam seam-chrome my-6" />

            <section v-for="group in page.groups" :key="group.heading" class="mb-8">
                <h2 class="text-xl">{{ group.heading }}</h2>

                <div class="mt-3 space-y-2">
                    <details
                        v-for="item in group.items"
                        :key="item.q"
                        class="rounded-sm border-2 border-chrome px-3 py-2.5 dark:border-grain-700"
                    >
                        <summary class="cursor-pointer font-semibold leading-snug">{{ item.q }}</summary>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{{ item.a }}</p>
                    </details>
                </div>
            </section>
        </div>
    </PublicLayout>
</template>
