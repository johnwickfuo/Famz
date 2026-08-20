<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';

/**
 * One section per service, each a numbered list of what actually happens.
 *
 * Numbered because these are sequences, and somebody reading "how do I get
 * paid" wants to know what comes after what, not a description of a philosophy.
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

            <nav class="mt-6 flex flex-wrap gap-2" aria-label="Services">
                <a
                    v-for="service in page.services"
                    :key="service.key"
                    :href="`#${service.key}`"
                    class="rounded-sm border-2 border-chrome px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-muted hover:border-ink hover:text-ink dark:border-grain-700 dark:hover:border-wash dark:hover:text-wash"
                >
                    {{ service.heading }}
                </a>
            </nav>

            <hr class="seam seam-chrome my-6" />

            <section
                v-for="service in page.services"
                :id="service.key"
                :key="service.key"
                class="mb-10 scroll-mt-24"
            >
                <h2 class="text-xl">{{ service.heading }}</h2>

                <ol class="mt-4 space-y-3">
                    <li
                        v-for="(step, index) in service.steps"
                        :key="index"
                        class="flex gap-3 text-sm leading-relaxed sm:text-base"
                    >
                        <span
                            class="figures mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-ink text-xs font-bold dark:border-wash"
                            aria-hidden="true"
                        >{{ index + 1 }}</span>
                        <span>{{ step }}</span>
                    </li>
                </ol>

                <Card v-if="service.note" class="mt-4" :seam="false">
                    <p class="text-sm">{{ service.note }}</p>
                </Card>
            </section>
        </div>
    </PublicLayout>
</template>
