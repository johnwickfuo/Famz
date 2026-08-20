<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';

const page = usePage();
const branding = computed(() => page.props.branding ?? {});
const user = computed(() => page.props.auth?.user ?? null);

/**
 * All eight services, from the server.
 *
 * They used to be four hard-coded cards here, which meant half the platform was
 * undiscoverable on the page most first-time visitors land on. The list now
 * comes from the same PlatformCopy the How-it-works page reads, so adding a
 * service adds it here too.
 */
defineProps({
    services: { type: Array, default: () => [] },
});
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
                <Button :href="route('catalogue.home')" variant="secondary" size="lg">
                    Browse the market
                </Button>
            </div>
        </section>

        <section aria-labelledby="services-heading">
            <h2 id="services-heading" class="sr-only">What this platform does</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <Card v-for="service in services" :key="service.key">
                    <template #header>
                        <h3 class="text-lg">{{ service.name }}</h3>
                    </template>

                    <p class="text-sm text-muted">{{ service.blurb }}</p>

                    <!-- Every card is a way in, not a description of one. -->
                    <Button :href="service.href" variant="secondary" size="sm" class="mt-3">
                        {{ service.cta }}
                    </Button>
                </Card>
            </div>

            <p class="mt-6 text-sm text-muted">
                Not sure where to start?
                <a :href="route('pages.how-it-works')" class="underline underline-offset-4">
                    Read how each of these works
                </a>.
            </p>
        </section>
    </PublicLayout>
</template>
