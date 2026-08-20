<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';

/**
 * An invitation that has been spent or has run out.
 *
 * Deliberately not a 404. Somebody standing here really was invited, and
 * telling them to ask for a new link is more use than a dead end.
 */
defineProps({
    reason: { type: String, default: null },
});
</script>

<template>
    <PublicLayout title="Invitation closed">
        <Card class="max-w-xl">
            <template #header>
                <h1 class="text-xl">This invitation cannot be used</h1>
            </template>

            <p class="text-sm">{{ reason }}</p>

            <p class="mt-3 text-sm text-muted">
                Mentors join by invitation only, so there is no form to fill in from here. Get in touch with whoever
                sent you the link and they can issue a new one.
            </p>

            <template #footer>
                <Button :href="route('home')" variant="secondary" size="sm">Back to the home page</Button>
            </template>
        </Card>
    </PublicLayout>
</template>
