<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    page: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="page.title">
        <article class="mx-auto w-full max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">{{ page.title }}</h1>
            <p class="mt-3 text-base leading-relaxed">{{ page.lead }}</p>

            <hr class="seam seam-chrome my-6" />

            <section v-for="(section, index) in page.sections" :key="index" class="mb-8">
                <h2 class="text-xl">{{ section.heading }}</h2>
                <p
                    v-for="(paragraph, i) in section.body"
                    :key="i"
                    class="mt-3 text-sm leading-relaxed sm:text-base"
                >
                    {{ paragraph }}
                </p>
            </section>
        </article>
    </PublicLayout>
</template>
