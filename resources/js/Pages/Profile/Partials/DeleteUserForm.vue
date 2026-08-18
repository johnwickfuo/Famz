<script setup>
import { nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Modal from '@/Components/Ui/Modal.vue';
import Input from '@/Components/Ui/Input.vue';
import Button from '@/Components/Ui/Button.vue';

const confirming = ref(false);
const form = useForm({ password: '' });

async function open() {
    confirming.value = true;
    await nextTick();
}

function close() {
    confirming.value = false;
    form.reset();
    form.clearErrors();
}

function destroy() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <h2 class="text-lg">Close your account</h2>
            <p class="mt-1 text-sm text-muted">
                Your account is removed from the platform. Orders and certificates already issued are kept
                for record-keeping.
            </p>
        </template>

        <Button variant="danger" @click="open">Close my account</Button>

        <Modal :show="confirming" title="Close your account?" @close="close">
            <p class="text-sm text-muted">
                Enter your password to confirm. This cannot be undone from here.
            </p>

            <div class="mt-4">
                <Input
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.password"
                    @keyup.enter="destroy"
                />
            </div>

            <template #footer>
                <Button variant="secondary" @click="close">Cancel</Button>
                <Button variant="danger" :loading="form.processing" @click="destroy">
                    Close account
                </Button>
            </template>
        </Modal>
    </Card>
</template>
