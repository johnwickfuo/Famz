<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';

/**
 * Terms and privacy, which share a shape: a lead, a date, and headed sections
 * of plain paragraphs.
 *
 * A sentence written in capitals in the copy stays in capitals here. Those are
 * the four or five sentences somebody will be held to — sales are final, we are
 * not the employer, the study fee is credited — and they are shouted on purpose.
 */
defineProps({
    page: { type: Object, required: true },
});
</script>

<template>
    <PublicLayout :title="page.title">
        <article class="mx-auto w-full max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">{{ page.title }}</h1>
            <p class="mt-3 text-sm text-muted">{{ page.intro }}</p>
            <p class="mt-1 text-xs text-muted">{{ page.updated }}</p>

            <hr class="seam seam-chrome my-6" />

            <!-- Contents, so a long document is navigable on a phone. -->
            <nav class="mb-8" aria-label="On this page">
                <p class="stencil mb-2 text-enamel dark:text-chrome">On this page</p>
                <ol class="space-y-1">
                    <li v-for="(section, index) in page.sections" :key="index">
                        <a
                            :href="`#section-${index}`"
                            class="text-sm underline underline-offset-4 hover:text-ink dark:hover:text-wash"
                        >
                            {{ section.heading }}
                        </a>
                    </li>
                </ol>
            </nav>

            <section
                v-for="(section, index) in page.sections"
                :id="`section-${index}`"
                :key="index"
                class="mb-8 scroll-mt-24"
            >
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
