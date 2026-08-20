<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * The waiting room between registering and being approved.
 */
defineProps({
    status: { type: String, required: true },
    statusLabel: { type: String, required: true },
    note: { type: String, default: null },
    panelUrl: { type: String, default: null },
});
</script>

<template>
    <PublicLayout title="Your mentor profile">
        <Card class="max-w-xl">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h1 class="text-xl">Your mentor profile</h1>
                    <Badge :variant="status === 'approved' ? 'active' : status === 'suspended' ? 'danger' : 'pending'" dot>
                        {{ statusLabel }}
                    </Badge>
                </div>
            </template>

            <p v-if="status === 'pending'" class="text-sm">
                Thank you. An administrator is reviewing your profile — usually the same day. You will get an email as
                soon as it is live, and clients can find you from that moment.
            </p>

            <p v-else-if="status === 'approved'" class="text-sm">
                You are live. Clients can find you, and your panel is where you manage packages, engagements and
                earnings.
            </p>

            <p v-else class="text-sm">
                Your profile is suspended, so you are not taking new work at the moment. Anything already running is
                unaffected.
            </p>

            <p v-if="note" class="mt-3 rounded-sm border-2 border-grain-300 p-3 text-sm dark:border-grain-600">
                {{ note }}
            </p>

            <template #footer>
                <Button v-if="panelUrl" as="a" :href="panelUrl" size="sm">Open my mentor panel</Button>
                <Button v-else :href="route('home')" variant="secondary" size="sm">Back to the home page</Button>
            </template>
        </Card>
    </PublicLayout>
</template>
