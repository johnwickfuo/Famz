<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Select from '@/Components/Ui/Select.vue';
import Textarea from '@/Components/Ui/Textarea.vue';
import { compressAll } from '@/Support/compressImage';

/**
 * One consultation, from the client's side.
 *
 * The report only exists on this page when the server sent one, and the server
 * only sends published ones — so a draft the company is still writing is not
 * hidden here behind a flag, it is simply absent.
 */
const props = defineProps({
    consultation: { type: Object, required: true },
    report: { type: Object, default: null },
    followups: { type: Array, default: () => [] },
    followupsOpen: { type: Boolean, default: false },
    followupsCloseAt: { type: String, default: null },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: String, default: null },
    isGuest: { type: Boolean, default: false },
});

const payForm = useForm({
    gateway: props.gateways.some((g) => g.key === props.defaultGateway)
        ? props.defaultGateway
        : (props.gateways[0]?.key ?? null),
});

const followUpForm = useForm({ message: '', photos: [] });
const compressing = ref(false);

const waiting = computed(() => !props.consultation.responded);

async function onPhotos(event) {
    const picked = Array.from(event.target.files ?? []).slice(0, 4);

    if (picked.length === 0) {
        return;
    }

    compressing.value = true;
    followUpForm.photos = await compressAll(picked);
    compressing.value = false;
}

function sendFollowUp() {
    followUpForm.post(route('consultations.followup', props.consultation.reference), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => followUpForm.reset(),
    });
}
</script>

<template>
    <PublicLayout :title="`Consultation ${consultation.reference}`">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="stencil text-muted">Consultation</p>
                <h1 class="figures mt-1 text-2xl sm:text-3xl">{{ consultation.reference }}</h1>
                <p class="mt-1 text-sm text-muted">Booked {{ consultation.booked_at }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="consultation.is_urgent" variant="danger" dot>Urgent</Badge>
                <Badge :variant="consultation.tone" dot>{{ consultation.status_label }}</Badge>
            </div>
        </div>

        <hr class="seam my-5" />

        <div class="lg:flex lg:items-start lg:gap-6">
            <div class="min-w-0 flex-1 space-y-5">
                <!-- The promise, and how it is going. -->
                <Card>
                    <template #header>
                        <h2 class="text-base">{{ waiting ? 'We will be in touch' : 'We have been in touch' }}</h2>
                    </template>

                    <p v-if="waiting" class="text-sm">
                        Somebody will call you on
                        <span class="figures font-semibold">{{ consultation.phone }}</span> by
                        <span class="font-semibold">{{ consultation.response_due_at }}</span>
                        <span v-if="consultation.response_countdown"> ({{ consultation.response_countdown }})</span>.
                    </p>

                    <p v-else class="text-sm">
                        We first got in touch on {{ consultation.responded_at }}.
                        <template v-if="followupsOpen">
                            If you have not heard from us since, write below and we will pick it up.
                        </template>
                        <template v-else>
                            If you have not heard from us since, reply to the email we sent you and we will pick it up.
                        </template>
                    </p>
                </Card>

                <Card v-if="consultation.situation || consultation.attachments.length">
                    <template #header>
                        <h2 class="text-base">What you told us</h2>
                    </template>

                    <p v-if="consultation.situation" class="whitespace-pre-line text-sm">
                        {{ consultation.situation }}
                    </p>

                    <dl v-if="consultation.animal_type || consultation.flock_size || consultation.state"
                        class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div v-if="consultation.animal_type">
                            <dt class="stencil text-muted">Birds or animals</dt>
                            <dd class="mt-0.5 text-sm">{{ consultation.animal_type }}</dd>
                        </div>
                        <div v-if="consultation.flock_size">
                            <dt class="stencil text-muted">How many</dt>
                            <dd class="figures mt-0.5 text-sm">{{ consultation.flock_size }}</dd>
                        </div>
                        <div v-if="consultation.state">
                            <dt class="stencil text-muted">Where</dt>
                            <dd class="mt-0.5 text-sm">
                                {{ [consultation.lga, consultation.state].filter(Boolean).join(', ') }}
                            </dd>
                        </div>
                    </dl>

                    <div v-if="consultation.attachments.length" class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <a
                            v-for="(photo, index) in consultation.attachments"
                            :key="index"
                            :href="photo"
                            target="_blank"
                            rel="noopener"
                            class="block"
                        >
                            <img
                                :src="photo"
                                alt=""
                                class="aspect-square w-full rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                            />
                        </a>
                    </div>
                </Card>

                <!-- The report. Absent until published, not hidden. -->
                <Card v-if="report">
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base">{{ report.title }}</h2>
                            <span class="stencil text-muted">{{ report.published_at }}</span>
                        </div>
                    </template>

                    <h3 class="stencil text-muted">What we found</h3>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ report.findings }}</p>

                    <h3 class="stencil mt-5 text-muted">What we recommend</h3>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ report.recommendations }}</p>

                    <template v-if="report.follow_up_actions">
                        <h3 class="stencil mt-5 text-muted">What to do next</h3>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ report.follow_up_actions }}</p>
                    </template>

                    <div v-if="report.attachments.length" class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <a
                            v-for="(file, index) in report.attachments"
                            :key="index"
                            :href="file"
                            target="_blank"
                            rel="noopener"
                        >
                            <img
                                :src="file"
                                alt=""
                                class="aspect-square w-full rounded-sm border-2 border-ink object-cover dark:border-wash"
                                loading="lazy"
                            />
                        </a>
                    </div>

                    <template #footer>
                        <!--
                            A real download: they paid for this advice, and a
                            report they cannot show to a vet is worth much less.
                        -->
                        <Button as="a" :href="report.pdf_url" size="sm">Download the report</Button>
                    </template>
                </Card>

                <!-- The conversation afterwards. -->
                <Card v-if="followups.length || followupsOpen">
                    <template #header>
                        <h2 class="text-base">Questions and answers</h2>
                    </template>

                    <ul v-if="followups.length" class="space-y-4">
                        <li
                            v-for="note in followups"
                            :key="note.id"
                            class="rounded-sm border-2 p-3"
                            :class="
                                note.from_company
                                    ? 'border-enamel bg-enamel-50 dark:border-enamel-300 dark:bg-grain-800'
                                    : 'border-grain-300 dark:border-grain-600'
                            "
                        >
                            <p class="stencil text-muted">{{ note.author }} · {{ note.at }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ note.message }}</p>

                            <div v-if="note.attachments.length" class="mt-2 flex flex-wrap gap-2">
                                <a
                                    v-for="(file, index) in note.attachments"
                                    :key="index"
                                    :href="file"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <img
                                        :src="file"
                                        alt=""
                                        class="size-16 rounded-sm border-2 border-ink object-cover dark:border-wash"
                                        loading="lazy"
                                    />
                                </a>
                            </div>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-muted">Nothing yet. Ask us anything about the work.</p>

                    <form v-if="followupsOpen" class="mt-4" @submit.prevent="sendFollowUp">
                        <Textarea
                            v-model="followUpForm.message"
                            label="Ask a question"
                            :rows="4"
                            :error="followUpForm.errors.message"
                            required
                        />

                        <input
                            type="file"
                            accept="image/*"
                            multiple
                            class="mt-3 block w-full rounded-sm border-2 border-ink bg-surface-raised p-2 text-sm file:mr-3 file:rounded-sm file:border-2 file:border-ink file:bg-chrome file:px-3 file:py-1.5 file:font-display file:text-xs file:font-bold file:uppercase dark:border-wash dark:bg-grain-900"
                            @change="onPhotos"
                        />

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <Button type="submit" size="sm" :loading="followUpForm.processing" :disabled="compressing">
                                Send
                            </Button>
                            <p v-if="followupsCloseAt" class="text-xs text-muted">
                                Open until {{ followupsCloseAt }}.
                            </p>
                        </div>
                    </form>

                    <p v-else-if="followupsCloseAt" class="mt-4 text-xs text-muted">
                        This closed on {{ followupsCloseAt }}. Book again if something new has come up.
                    </p>
                </Card>
            </div>

            <aside class="mt-6 lg:mt-0 lg:w-80 lg:shrink-0">
                <Card class="lg:sticky lg:top-4">
                    <template #header>
                        <h2 class="text-base">This consultation</h2>
                    </template>

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="stencil text-muted">Service</dt>
                            <dd class="mt-0.5">{{ consultation.tier_label }}</dd>
                        </div>
                        <div>
                            <dt class="stencil text-muted">Price</dt>
                            <dd class="figures mt-0.5 text-lg font-bold">
                                <span v-if="consultation.quote">{{ consultation.quote }}</span>
                                <span v-else class="text-sm font-normal text-muted">
                                    Not set yet — we agree it after we talk
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="consultation.quote_note"
                        class="mt-3 rounded-sm border-2 border-grain-300 p-2 text-xs dark:border-grain-600"
                    >
                        {{ consultation.quote_note }}
                    </p>

                    <template v-if="consultation.awaits_payment">
                        <hr class="seam my-4" />

                        <form v-if="!isGuest" @submit.prevent="payForm.post(route('consultations.pay', consultation.reference))">
                            <p v-if="!gateways.length" class="text-sm font-semibold text-cockscomb dark:text-cockscomb-300">
                                No payment provider has been set up yet, so this cannot be paid at the moment.
                            </p>

                            <template v-else>
                                <Select
                                    v-model="payForm.gateway"
                                    label="Pay with"
                                    :options="gateways.map((g) => ({ value: g.key, label: g.name }))"
                                    :error="payForm.errors.gateway"
                                />

                                <Button class="mt-3" type="submit" block :loading="payForm.processing">
                                    Pay {{ consultation.quote }}
                                </Button>
                            </template>
                        </form>

                        <!--
                            A guest can read their consultation but cannot pay:
                            a payment needs an account to attach the order and
                            the receipt to.
                        -->
                        <div v-else>
                            <p class="text-sm">Sign in or create an account with this email to pay.</p>
                            <Button class="mt-3" :href="route('login')" block>Sign in to pay</Button>
                        </div>
                    </template>

                    <template v-if="consultation.paid">
                        <hr class="seam my-4" />
                        <p class="text-sm">
                            {{ consultation.completed ? 'Paid, and the work is finished.' : 'Paid. We are on it.' }}
                        </p>
                    </template>

                    <template v-if="isGuest">
                        <hr class="seam my-4" />
                        <p class="text-xs text-muted">
                            Keep this reference safe — it is how you get back here. Registering with
                            <span class="break-all font-semibold">{{ consultation.email }}</span> puts it on your
                            dashboard for good.
                        </p>
                    </template>

                    <template v-else>
                        <hr class="seam my-4" />
                        <Link :href="route('consultations.index')" class="stencil underline underline-offset-4">
                            All my consultations
                        </Link>
                    </template>
                </Card>
            </aside>
        </div>
    </PublicLayout>
</template>
