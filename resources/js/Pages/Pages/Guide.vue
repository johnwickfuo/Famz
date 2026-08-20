<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    page: { type: Object, required: true },
    role: { type: String, required: true },
    others: { type: Array, default: () => [] },
});
</script>

<template>
    <PublicLayout :title="page.title">
        <article class="mx-auto w-full max-w-2xl">
            <p class="stencil mb-2 text-enamel dark:text-chrome">Guide</p>
            <h1 class="text-2xl sm:text-3xl">{{ page.title }}</h1>
            <p class="mt-3 text-base leading-relaxed">{{ page.lead }}</p>

            <hr class="seam seam-chrome my-6" />

            <section v-for="(section, index) in page.sections" :key="index" class="mb-8">
                <h2 class="text-xl">{{ section.heading }}</h2>
                <ul class="mt-3 space-y-2.5">
                    <li
                        v-for="(point, i) in section.body"
                        :key="i"
                        class="flex gap-2.5 text-sm leading-relaxed sm:text-base"
                    >
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-enamel dark:bg-chrome" aria-hidden="true" />
                        <span>{{ point }}</span>
                    </li>
                </ul>
            </section>

            <hr class="seam seam-chrome my-6" />

            <p class="text-sm text-muted">
                Other guides:
                <template v-for="(other, index) in others" :key="other.role">
                    <Link :href="route('pages.guide', other.role)" class="underline underline-offset-4">
                        {{ other.title }}
                    </Link><span v-if="index < others.length - 1">, </span>
                </template>
            </p>
        </article>
    </PublicLayout>
</template>
