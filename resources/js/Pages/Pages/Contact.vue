<script setup>
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import FieldShell from '@/Components/Ui/FieldShell.vue';

/**
 * Writing to the company.
 *
 * The other ways of reaching us sit above the form, not below it. Somebody
 * whose birds are dying wants the phone number, and making them scroll past a
 * four-field form to find it is the wrong order.
 */
const props = defineProps({
    contact: { type: Object, default: () => ({}) },
});

const form = useForm({
    name: '',
    email: '',
    subject: '',
    message: '',
});

function submit() {
    form.post(route('contact.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <PublicLayout title="Contact us">
        <div class="mx-auto w-full max-w-2xl">
            <h1 class="text-2xl sm:text-3xl">Contact us</h1>
            <p class="mt-3 text-base leading-relaxed">
                A real person reads these. We answer during working hours, usually the same day.
            </p>

            <!-- Faster routes first. -->
            <Card v-if="contact.phone || contact.whatsapp || contact.email" class="mt-5">
                <dl class="space-y-2 text-sm">
                    <div v-if="contact.phone" class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold">Phone</dt>
                        <dd><a :href="`tel:${contact.phone}`" class="underline underline-offset-4">{{ contact.phone }}</a></dd>
                    </div>
                    <div v-if="contact.whatsapp" class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold">WhatsApp</dt>
                        <dd>{{ contact.whatsapp }}</dd>
                    </div>
                    <div v-if="contact.email" class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold">Email</dt>
                        <dd><a :href="`mailto:${contact.email}`" class="underline underline-offset-4">{{ contact.email }}</a></dd>
                    </div>
                    <div v-if="contact.address" class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold">Address</dt>
                        <dd>{{ contact.address }}</dd>
                    </div>
                </dl>
            </Card>

            <hr class="seam seam-chrome my-6" />

            <h2 class="text-xl">Send a message</h2>

            <Card class="mt-3">
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input v-model="form.name" label="Your name" :error="form.errors.name" required />
                        <Input
                            v-model="form.email"
                            type="email"
                            label="Your email"
                            :error="form.errors.email"
                            required
                        />
                    </div>

                    <Input v-model="form.subject" label="What is it about?" :error="form.errors.subject" required />

                    <!--
                        FieldShell rather than a hand-written label, so this
                        matches the Input fields above it. A form where three
                        labels are stencil caps and the fourth is sentence case
                        looks like two forms stuck together.
                    -->
                    <FieldShell
                        id="contact-message"
                        label="Message"
                        :error="form.errors.message"
                        required
                        hint-id="contact-message-hint"
                        error-id="contact-message-error"
                    >
                        <textarea
                            id="contact-message"
                            v-model="form.message"
                            rows="6"
                            maxlength="4000"
                            required
                            class="w-full rounded-sm border-2 border-ink bg-surface-raised px-3 py-2.5 text-base dark:border-wash dark:bg-grain-800"
                        />
                    </FieldShell>

                    <Button type="submit" :loading="form.processing">Send message</Button>
                </form>
            </Card>

            <p class="mt-4 text-xs text-muted">
                Sick or dying animals need a vet, not an email.
                <a :href="route('consultations.create')" class="underline underline-offset-4">Book an urgent consultation</a>
                if it cannot wait.
            </p>
        </div>
    </PublicLayout>
</template>
