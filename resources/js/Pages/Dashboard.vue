<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => user.value?.roles ?? []);

const statusTone = computed(() => {
    switch (user.value?.status) {
        case 'active':
            return 'active';
        case 'suspended':
            return 'danger';
        default:
            return 'pending';
    }
});
</script>

<template>
    <AppLayout title="Dashboard" :breadcrumbs="[{ label: 'Dashboard' }]">
        <div class="grid gap-4 sm:grid-cols-2">
            <Card>
                <template #header>
                    <h2 class="text-lg">Your account</h2>
                </template>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Name</dt>
                        <dd class="truncate font-semibold">{{ user?.display_name }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Status</dt>
                        <dd><Badge :variant="statusTone" dot>{{ user?.status }}</Badge></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="stencil text-muted">Roles</dt>
                        <dd class="flex flex-wrap justify-end gap-1">
                            <Badge v-for="role in roles" :key="role" size="sm">{{ role }}</Badge>
                            <span v-if="!roles.length" class="text-muted">Member</span>
                        </dd>
                    </div>
                </dl>

                <template #footer>
                    <Button :href="route('profile.edit')" variant="secondary" size="sm">
                        Edit profile
                    </Button>
                </template>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-lg">Recent activity</h2>
                </template>

                <EmptyState
                    title="Nothing here yet"
                    description="Orders, consultations and training progress will show up here."
                />
            </Card>
        </div>
    </AppLayout>
</template>
