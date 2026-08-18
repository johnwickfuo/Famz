<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';

const page = usePage();
const branding = computed(() => page.props.branding ?? {});
const user = computed(() => page.props.auth?.user ?? null);

const pillars = [
    {
        title: 'Buy and sell',
        body: 'Feed, day-old chicks, cages, drinkers and equipment — from sellers who show their prices.',
        stencil: 'Market',
    },
    {
        title: 'Learn the work',
        body: 'Short, practical training you can finish on a small screen, with a certificate at the end.',
        stencil: 'Training',
    },
    {
        title: 'Ask a mentor',
        body: 'Put a question to an experienced farmer and get a written answer, not a guess.',
        stencil: 'Mentors',
    },
    {
        title: 'Find farm work',
        body: 'Workers and employers meet here — by state and LGA, not by luck.',
        stencil: 'Jobs',
    },
];
</script>

<template>
    <PublicLayout>
        <section class="mb-12">
            <p class="stencil mb-3 text-enamel dark:text-chrome">
                For poultry farmers, feed sellers and farm workers
            </p>

            <h1 class="text-display max-w-4xl text-ink dark:text-wash">
                {{ branding.tagline || 'Everything the farm needs, in one place.' }}
            </h1>

            <hr class="seam seam-chrome my-6 max-w-4xl" />

            <p class="prose-farm text-lg text-muted">
                Buy feed and equipment, learn the work, ask a mentor, and find people to hire —
                built for a phone on a slow connection.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <Button v-if="!user" :href="route('register')" size="lg">Join free</Button>
                <Button v-else :href="route('dashboard')" size="lg">Go to my dashboard</Button>
                <Button :href="route('sections.show', 'marketplace')" variant="secondary" size="lg">
                    Browse the market
                </Button>
            </div>
        </section>

        <section aria-labelledby="pillars-heading">
            <h2 id="pillars-heading" class="sr-only">What this platform does</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <Card v-for="pillar in pillars" :key="pillar.title">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg">{{ pillar.title }}</h3>
                            <Badge variant="pending" size="sm">{{ pillar.stencil }}</Badge>
                        </div>
                    </template>

                    <p class="text-sm text-muted">{{ pillar.body }}</p>
                </Card>
            </div>
        </section>
    </PublicLayout>
</template>
