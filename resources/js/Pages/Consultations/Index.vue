<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

defineProps({
    consultations: { type: Array, default: () => [] },
});
</script>

<template>
    <PublicLayout title="My consultations">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="stencil mb-1 text-enamel dark:text-chrome">Consultation</p>
                <h1 class="text-2xl sm:text-3xl">My consultations</h1>
            </div>
            <Button :href="route('consultations.create')" size="sm">Ask us something new</Button>
        </div>

        <hr class="seam my-5" />

        <EmptyState
            v-if="!consultations.length"
            title="You have not asked us anything yet"
            description="Tell us what is wrong and somebody will ring you. There is nothing to pay to send it."
        >
            <template #action>
                <Button :href="route('consultations.create')">Ask us for help</Button>
            </template>
        </EmptyState>

        <div v-else class="space-y-4">
            <Card v-for="consultation in consultations" :key="consultation.reference">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 class="figures text-base">{{ consultation.reference }}</h2>
                        <p class="mt-1 text-sm text-muted">
                            {{ consultation.tier_label }} · booked {{ consultation.booked_at }}
                        </p>

                        <!-- The promise, while it is still outstanding. -->
                        <p v-if="!consultation.responded" class="mt-1 text-sm">
                            We will call you by
                            <span class="font-semibold">{{ consultation.response_due_at }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="consultation.is_urgent" variant="danger" dot>Urgent</Badge>
                        <Badge v-if="consultation.awaits_payment" variant="pending" dot>Needs paying</Badge>
                        <Badge v-else-if="consultation.has_report" variant="active" dot>Report ready</Badge>
                        <Badge :variant="consultation.tone" dot>{{ consultation.status_label }}</Badge>
                    </div>
                </div>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="figures text-sm font-semibold">
                            <span v-if="consultation.quote">{{ consultation.quote }}</span>
                            <span v-else class="font-normal text-muted">No price yet</span>
                        </p>
                        <Button :href="consultation.url" size="sm">Open</Button>
                    </div>
                </template>
            </Card>
        </div>
    </PublicLayout>
</template>
