<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

defineProps({
    engagements: { type: Array, default: () => [] },
});
</script>

<template>
    <PublicLayout title="My mentorship">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="stencil mb-1 text-enamel dark:text-chrome">Mentors</p>
                <h1 class="text-2xl sm:text-3xl">My mentorship</h1>
            </div>
            <Button :href="route('mentors.find')" variant="secondary" size="sm">Find another mentor</Button>
        </div>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!engagements.length"
            title="You have not hired anybody yet"
            description="Tell us what you are struggling with and we will find the people who have done it before."
        >
            <template #action>
                <Button :href="route('mentors.find')">Find a mentor</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="engagement in engagements" :key="engagement.reference">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 class="text-base leading-snug">{{ engagement.package }}</h2>
                        <p class="figures mt-1 text-sm text-muted">
                            {{ engagement.mentor }} · {{ engagement.reference }}
                            <span v-if="engagement.started"> · started {{ engagement.started }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- The one that needs them, said as a job rather than a state. -->
                        <Badge v-if="engagement.awaiting_you" variant="pending" dot>Needs you</Badge>
                        <Badge :variant="engagement.tone" dot>{{ engagement.status_label }}</Badge>
                    </div>
                </div>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="figures text-sm font-semibold">{{ engagement.price }}</p>
                        <Button :href="engagement.url" size="sm">Open</Button>
                    </div>
                </template>
            </Card>
        </div>
    </PublicLayout>
</template>
