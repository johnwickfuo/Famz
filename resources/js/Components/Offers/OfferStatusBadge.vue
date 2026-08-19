<script setup>
import { computed } from 'vue';
import Badge from '@/Components/Ui/Badge.vue';

const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
});

const tones = {
    pending: 'pending',
    countered: 'pending',
    accepted: 'active',
    rejected: 'danger',
    expired: 'neutral',
    withdrawn: 'neutral',
    // Request statuses share this component; the words differ, the shape does not.
    pending_approval: 'pending',
    open: 'active',
    offer_accepted: 'active',
    closed: 'neutral',
};

const variant = computed(() => tones[props.status] ?? 'neutral');
</script>

<template>
    <Badge :variant="variant" dot>{{ label }}</Badge>
</template>
